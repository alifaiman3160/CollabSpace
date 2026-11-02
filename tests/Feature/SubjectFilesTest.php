<?php

namespace Tests\Feature;

use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectFile;
use App\Models\User;
use App\Support\SubjectFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** The lecturer's project files for a subject: upload rules, who may read them, and where they show. */
class SubjectFilesTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $student;

    protected Subject $subject;

    protected Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(SubjectFiles::DISK);
        Storage::fake('public');

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);
        $this->section = Section::create(['subject_id' => $this->subject->id, 'name' => '1A']);
        $this->student = $this->enrolled();
    }

    protected function enrolled(?Subject $subject = null): User
    {
        $subject ??= $this->subject;
        $section = Section::firstOrCreate(['subject_id' => $subject->id, 'name' => '1A']);
        $student = User::factory()->student()->create(['must_change_password' => false]);
        Enrolment::create(['user_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id]);

        return $student;
    }

    /*
    | Real small files of each kind
    */

    protected function pdf(string $name = 'question.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    protected function ooxml(string $name, string $part): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ox');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString($part, '<x/>');
        $zip->close();

        return UploadedFile::fake()->createWithContent($name, file_get_contents($path));
    }

    protected function ole(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 1016));
    }

    protected function upload(UploadedFile $file, array $extra = [], ?User $as = null, ?Subject $subject = null)
    {
        return $this->actingAs($as ?? $this->lecturer)->post(route('lecturer.subjects.files.store', $subject ?? $this->subject), [
            'file' => $file, 'title' => 'Project question', 'kind' => 'question', ...$extra,
        ]);
    }

    /*
    | Uploading
    */

    public function test_each_allowed_type_can_be_uploaded_and_is_stored_privately_under_a_random_name(): void
    {
        $files = [
            'pdf' => [$this->pdf('question.pdf'), 'application/pdf'],
            'docx' => [$this->ooxml('guide.docx', 'word/document.xml'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'pptx' => [$this->ooxml('slides.pptx', 'ppt/presentation.xml'), 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'xlsx' => [$this->ooxml('marks.xlsx', 'xl/workbook.xml'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'doc' => [$this->ole('old.doc'), 'application/msword'],
            'ppt' => [$this->ole('old.ppt'), 'application/vnd.ms-powerpoint'],
            'xls' => [$this->ole('old.xls'), 'application/vnd.ms-excel'],
        ];

        foreach ($files as $extension => [$file, $mime]) {
            $this->upload($file, ['title' => "A {$extension} file"])->assertSessionHasNoErrors()->assertRedirect(route('lecturer.subjects.edit', $this->subject).'#assessment-shared');

            $row = SubjectFile::where('title', "A {$extension} file")->sole();

            $this->assertSame($mime, $row->mime, $extension);
            $this->assertSame($this->subject->id, $row->subject_id);
            $this->assertSame($this->lecturer->id, $row->uploaded_by);
            $this->assertStringStartsWith('subject-files/'.$this->subject->id.'/', $row->stored_path);
            $this->assertStringEndsWith('.'.$extension, $row->stored_path);
            $this->assertStringNotContainsString(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), $row->stored_path, 'Never the user\'s file name.');
            Storage::disk(SubjectFiles::DISK)->assertExists($row->stored_path);
        }

        $this->assertSame(7, SubjectFile::count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Nothing on the public disk.');
    }

    public function test_a_file_whose_content_is_not_what_its_name_says_is_refused(): void
    {
        $spoofs = [
            'a php file named pdf' => UploadedFile::fake()->createWithContent('evil.pdf', "<?php echo 'hi'; system(\$_GET['c']);"),
            'a windows program named pdf' => UploadedFile::fake()->createWithContent('setup.pdf', "MZ\x90\0\x03\0\0\0".str_repeat("\0", 200)),
            'a text file named docx' => UploadedFile::fake()->createWithContent('notes.docx', 'just text'),
            'an empty zip named docx' => $this->ooxml('plain.docx', 'readme.txt'),
            'a word file named pdf' => $this->ooxml('real.pdf', 'word/document.xml'),
            'a word file named xlsx' => $this->ooxml('mixed.xlsx', 'word/document.xml'),
            'a pdf named doc' => $this->pdf('wrong.doc'),
            'a php file' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
            'a text file' => UploadedFile::fake()->createWithContent('readme.txt', 'hello'),
            'an exe' => UploadedFile::fake()->createWithContent('run.exe', "MZ\x90\0"),
            'no extension' => UploadedFile::fake()->createWithContent('question', "%PDF-1.4\n%%EOF"),
        ];

        foreach ($spoofs as $what => $file) {
            $this->upload($file)->assertSessionHasErrors('file');

            $this->assertStringContainsString('was not accepted', session('errors')->first('file'), $what);
        }

        $this->assertSame(0, SubjectFile::count());
        $this->assertSame([], Storage::disk(SubjectFiles::DISK)->allFiles());
    }

    public function test_an_oversize_file_is_refused_with_a_message(): void
    {
        $this->upload(UploadedFile::fake()->create('huge.pdf', SubjectFiles::MAX_KB + 1, 'application/pdf'))->assertSessionHasErrors('file');

        $this->assertSame('That file is over 10 MB.', session('errors')->first('file'));
        $this->assertSame(0, SubjectFile::count());
    }

    public function test_the_eleventh_file_is_refused(): void
    {
        foreach (range(1, SubjectFiles::MAX_PER_SUBJECT) as $i) {
            $this->upload($this->pdf(), ['title' => "File {$i}"])->assertSessionHasNoErrors();
        }

        $this->upload($this->pdf(), ['title' => 'One too many'])->assertSessionHasErrors('file');

        $this->assertStringContainsString('already has 10 files', session('errors')->first('file'));
        $this->assertSame(10, SubjectFile::count());
        $this->assertCount(10, Storage::disk(SubjectFiles::DISK)->allFiles());
    }

    public function test_the_title_and_kind_are_checked(): void
    {
        $this->upload($this->pdf(), ['title' => ''])->assertSessionHasErrors('title');
        $this->upload($this->pdf(), ['title' => str_repeat('x', 121)])->assertSessionHasErrors('title');
        $this->upload($this->pdf(), ['kind' => 'secret'])->assertSessionHasErrors('kind');
        $this->upload($this->pdf(), ['kind' => ''])->assertSessionHasErrors('kind');
        $this->actingAs($this->lecturer)->post(route('lecturer.subjects.files.store', $this->subject), ['title' => 'No file', 'kind' => 'other'])->assertSessionHasErrors('file');

        $this->assertSame(0, SubjectFile::count());
    }

    public function test_a_file_name_with_path_separators_or_control_characters_is_cleaned(): void
    {
        $this->upload($this->pdf("..\\..\\evil/na\"me\r\n;x.pdf"))->assertSessionHasNoErrors();

        $row = SubjectFile::sole();

        $this->assertDoesNotMatchRegularExpression('#[\\\\/"\r\n;]#', $row->original_name);
        $this->assertStringEndsWith('.pdf', $row->original_name);
        $this->assertStringNotContainsString('..', $row->stored_path);
        $this->assertSame($row->original_name, $row->downloadName());
    }

    public function test_the_marking_guide_option_says_marks_come_from_the_rubric(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->assertOk()->getContent();

        $this->assertStringContainsString('data-whole-subject', $html);
        $this->assertStringNotContainsString('Project files', $html);
        $this->assertStringContainsString('Marking guide (file)', $html);
        $this->assertStringContainsString('For students to read. Marks are calculated from the rubric set up in the system, not from this file.', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('No files yet.', $html);
    }

    /*
    | Listing and deleting (the owning lecturer)
    */

    public function test_the_edit_page_lists_the_files_with_kind_title_size_and_a_delete_action(): void
    {
        $this->upload($this->pdf('q.pdf'), ['title' => 'The question', 'kind' => 'question']);
        $this->upload($this->ooxml('g.docx', 'word/document.xml'), ['title' => 'The guide', 'kind' => 'marking_guide']);
        $row = SubjectFile::where('title', 'The question')->sole();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-subject-file>') + substr_count($html, 'data-subject-file '));
        $this->assertStringContainsString('Question', $html);
        $this->assertStringNotContainsString('Project question', $html);
        $this->assertStringContainsString('Marking guide', $html);
        $this->assertStringContainsString('The question', $html);
        $this->assertStringContainsString('q.pdf', $html);
        $this->assertStringContainsString(route('subject-files.download', $row), $html);
        $this->assertStringContainsString(route('lecturer.subjects.files.destroy', [$this->subject, $row]), $html);
        $this->assertStringContainsString('Delete this file?', $html);
        $this->assertStringNotContainsString($row->stored_path, $html, 'The storage path is never shown.');
    }

    public function test_the_owning_lecturer_deletes_a_file_and_it_leaves_the_disk(): void
    {
        $this->upload($this->pdf())->assertSessionHasNoErrors();
        $row = SubjectFile::sole();
        Storage::disk(SubjectFiles::DISK)->assertExists($row->stored_path);

        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.files.destroy', [$this->subject, $row]))
            ->assertRedirect(route('lecturer.subjects.edit', $this->subject).'#assessment-shared');

        $this->assertDatabaseMissing('subject_files', ['id' => $row->id]);
        Storage::disk(SubjectFiles::DISK)->assertMissing($row->stored_path);
    }

    public function test_deleting_a_file_already_missing_from_the_disk_does_not_crash(): void
    {
        $this->upload($this->pdf());
        $row = SubjectFile::sole();
        Storage::disk(SubjectFiles::DISK)->delete($row->stored_path);

        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.files.destroy', [$this->subject, $row]))->assertRedirect();

        $this->assertDatabaseMissing('subject_files', ['id' => $row->id]);
    }

    public function test_another_lecturer_cannot_upload_delete_or_download(): void
    {
        $this->upload($this->pdf());
        $row = SubjectFile::sole();
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->upload($this->pdf(), [], $other)->assertNotFound();
        $this->actingAs($other)->delete(route('lecturer.subjects.files.destroy', [$this->subject, $row]))->assertNotFound();
        $this->actingAs($other)->get(route('subject-files.download', $row))->assertForbidden();
        $this->actingAs($other)->get(route('lecturer.subjects.edit', $this->subject))->assertNotFound();

        $this->assertSame(1, SubjectFile::count());
        Storage::disk(SubjectFiles::DISK)->assertExists($row->stored_path);
    }

    public function test_a_file_cannot_be_deleted_through_another_subjects_url(): void
    {
        $this->upload($this->pdf());
        $row = SubjectFile::sole();
        $mine = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);

        $this->actingAs($this->lecturer)->delete(route('lecturer.subjects.files.destroy', [$mine, $row]))->assertNotFound();

        $this->assertSame(1, SubjectFile::count());
    }

    public function test_students_and_admins_cannot_upload_or_delete(): void
    {
        $this->upload($this->pdf());
        $row = SubjectFile::sole();
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        foreach ([$this->student, $admin] as $user) {
            $this->upload($this->pdf(), [], $user)->assertForbidden();
            $this->actingAs($user)->delete(route('lecturer.subjects.files.destroy', [$this->subject, $row]))->assertForbidden();
        }

        $this->assertSame(1, SubjectFile::count());
    }

    /*
    | Downloading
    */

    public function test_an_enrolled_student_downloads_with_safe_headers(): void
    {
        $this->upload($this->pdf('Q 1.pdf'), ['title' => 'Question']);
        $this->upload($this->ooxml('g.docx', 'word/document.xml'), ['title' => 'Guide', 'kind' => 'marking_guide']);
        $pdf = SubjectFile::where('title', 'Question')->sole();
        $docx = SubjectFile::where('title', 'Guide')->sole();

        $response = $this->actingAs($this->student)->get(route('subject-files.download', $pdf))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Q 1.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString($pdf->stored_path, $response->headers->get('Content-Disposition'));

        $word = $this->actingAs($this->student)->get(route('subject-files.download', $docx))->assertOk();
        $this->assertStringStartsWith('attachment;', $word->headers->get('Content-Disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $word->headers->get('Content-Type'));
        $this->assertSame('nosniff', $word->headers->get('X-Content-Type-Options'));
    }

    public function test_the_owning_lecturer_and_a_group_member_can_download_too(): void
    {
        $this->upload($this->pdf());
        $row = SubjectFile::sole();

        $this->actingAs($this->lecturer)->get(route('subject-files.download', $row))->assertOk();

        // A student known only through a group of the subject.
        $member = User::factory()->student()->create(['must_change_password' => false]);
        $group = Group::create(['subject_id' => $this->subject->id, 'name' => 'G1', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $member->id, 'joined_at' => now()]);

        $this->actingAs($member)->get(route('subject-files.download', $row))->assertOk();
    }

    public function test_a_student_of_another_subject_an_outsider_and_a_guest_cannot_download(): void
    {
        $this->upload($this->pdf());
        $row = SubjectFile::sole();

        $elsewhere = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);
        $other = $this->enrolled($elsewhere);
        $nobody = User::factory()->student()->create(['must_change_password' => false]);
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        foreach ([$other, $nobody, $admin] as $user) {
            $this->actingAs($user)->get(route('subject-files.download', $row))->assertForbidden();
        }

        auth()->logout();
        $this->get(route('subject-files.download', $row))->assertRedirect(route('login'));
    }

    public function test_a_missing_disk_file_is_a_404_not_a_crash(): void
    {
        $this->upload($this->pdf());
        $row = SubjectFile::sole();
        Storage::disk(SubjectFiles::DISK)->delete($row->stored_path);

        $this->actingAs($this->student)->get(route('subject-files.download', $row))->assertNotFound();
    }

    /*
    | The student's pages
    */

    public function test_a_student_without_a_group_sees_a_files_chip_on_the_dashboard_and_the_files_page(): void
    {
        $this->upload($this->pdf(), ['title' => 'The question']);
        $this->upload($this->ooxml('g.docx', 'word/document.xml'), ['title' => 'The guide', 'kind' => 'marking_guide']);

        $html = $this->actingAs($this->student)->get(route('student.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Files (2)', $html);
        $this->assertStringContainsString(route('student.subject.files', $this->subject), $html);

        $page = $this->actingAs($this->student)->get(route('student.subject.files', $this->subject))->assertOk()->getContent();
        $this->assertStringContainsString('The question', $page);
        $this->assertStringContainsString('The guide', $page);
        $this->assertStringContainsString('Marking guide', $page);
        $this->assertStringNotContainsString('<form', substr($page, strpos($page, 'data-files-list')), 'Read only.');
        $this->assertSame(2, substr_count($page, 'data-file-download'));
    }

    public function test_no_chip_when_the_subject_has_no_files(): void
    {
        $html = $this->actingAs($this->student)->get(route('student.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-files-chip', $html);
    }

    public function test_the_chip_sits_on_an_existing_line_of_the_card(): void
    {
        $this->upload($this->pdf());

        $html = $this->actingAs($this->student)->get(route('student.dashboard'))->getContent();
        $card = substr($html, strpos($html, 'data-waiting '));

        $this->assertLessThan(strpos($card, 'No group yet'), strpos($card, 'data-files-chip'), 'In the header row, before the title line.');
    }

    public function test_a_student_outside_the_subject_cannot_open_the_files_page(): void
    {
        $this->upload($this->pdf());
        $nobody = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($nobody)->get(route('student.subject.files', $this->subject))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('student.subject.files', $this->subject))->assertForbidden();
    }

    public function test_the_group_page_lists_the_files_inside_the_project_brief_card(): void
    {
        $this->upload($this->pdf(), ['title' => 'The question']);
        $this->subject->update(['brief' => 'Build a thing.']);
        $group = Group::create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        foreach (['student.workspace.show', 'student.board.show'] as $route) {
            $html = $this->actingAs($this->student)->get(route($route, $group))->assertOk()->getContent();

            $this->assertStringContainsString('data-brief-files', $html, $route);
            $this->assertStringContainsString('The question', $html, $route);
            $this->assertStringContainsString('Build a thing.', $html, $route);
            $this->assertStringContainsString(route('subject-files.download', SubjectFile::sole()), $html, $route);
        }
    }

    public function test_the_card_shows_for_files_alone_and_not_at_all_for_neither(): void
    {
        $group = Group::create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        $this->assertStringNotContainsString('data-project-brief', $this->actingAs($this->student)->get(route('student.workspace.show', $group))->getContent());

        $this->upload($this->pdf(), ['title' => 'Only a file']);

        $html = $this->actingAs($this->student)->get(route('student.workspace.show', $group))->getContent();
        $this->assertStringContainsString('data-project-brief', $html);
        $this->assertStringContainsString('Only a file', $html);
    }

    public function test_only_this_subjects_files_show_on_its_group_page(): void
    {
        $this->upload($this->pdf(), ['title' => 'Mine']);
        $second = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECV2113', 'name' => 'Second', 'group_size' => 4]);
        $this->upload($this->pdf(), ['title' => 'Theirs'], null, $second);

        $group = Group::create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        $html = $this->actingAs($this->student)->get(route('student.workspace.show', $group))->getContent();

        $this->assertStringContainsString('Mine', $html);
        $this->assertStringNotContainsString('Theirs', $html);
    }

    public function test_deleting_an_empty_subject_takes_its_folder_with_it(): void
    {
        $this->upload($this->pdf());
        $row = SubjectFile::sole();
        Enrolment::query()->delete();
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->actingAs($admin)->delete(route('admin.subjects.destroy', $this->subject))->assertRedirect(route('admin.subjects'));

        Storage::disk(SubjectFiles::DISK)->assertMissing($row->stored_path);
        $this->assertSame(0, SubjectFile::count());
    }

    public function test_the_too_large_page_is_friendly(): void
    {
        $html = view('errors.413')->render();

        $this->assertStringContainsString('That file is too large', $html);
        $this->assertStringContainsString('upload_max_filesize', $html);
    }
}
