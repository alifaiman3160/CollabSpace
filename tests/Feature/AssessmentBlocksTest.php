<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Subject;
use App\Models\SubjectFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The edit page keeps each assessment's brief and files inside its own block; the subject form holds only subject settings. */
class AssessmentBlocksTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Assessment $a;

    protected Assessment $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $this->a = $this->subject->assessments()->create(['title' => 'Assignment 1', 'type' => 'assignment', 'position' => 1, 'brief' => 'Build the thing alpha.', 'starts_on' => '2026-02-01', 'due_on' => '2026-03-01']);
        $this->b = $this->subject->assessments()->create(['title' => 'Project', 'type' => 'project', 'position' => 2, 'starts_on' => '2026-04-01', 'due_on' => '2026-05-01']);
    }

    protected function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('q.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    protected function upload(array $extra)
    {
        return $this->actingAs($this->lecturer)->post(route('lecturer.subjects.files.store', $this->subject), [
            'file' => $this->pdf(), 'title' => 'Sheet', 'kind' => 'question', ...$extra,
        ]);
    }

    protected function block(string $html, int $id): string
    {
        $start = strpos($html, 'id="assessment-'.$id.'"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</li>', $start) - $start);
    }

    public function test_brief_and_files_sit_inside_their_own_assessment_block(): void
    {
        $this->upload(['assessment_id' => $this->a->id])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->assertOk()->getContent();

        $blockA = $this->block($html, $this->a->id);
        $blockB = $this->block($html, $this->b->id);

        $this->assertStringContainsString('Build the thing alpha.', $blockA);
        $this->assertStringContainsString('Sheet', $blockA);
        $this->assertStringNotContainsString('Sheet', $blockB);
        $this->assertStringContainsString('No brief yet', $blockB);
        $this->assertStringContainsString('No files yet', $blockB);
        $this->assertStringNotContainsString('data-subject-files', $html, 'The standalone Project files card is gone.');
    }

    public function test_the_upload_form_posts_the_blocks_own_assessment_and_has_no_select(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();

        $this->assertStringContainsString('<input type="hidden" name="assessment_id" value="'.$this->b->id.'">', $this->block($html, $this->b->id));
        $this->assertStringNotContainsString('data-file-assessment', $html);
        $this->assertStringContainsString('<option value="question">Question</option>', $html);
    }

    public function test_an_assessment_of_another_subject_is_rejected_and_the_redirect_lands_on_the_block(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ0001', 'name' => 'Other', 'group_size' => 4]);
        $foreign = $other->assessments()->create(['title' => 'Foreign', 'type' => 'project', 'position' => 1]);

        $this->upload(['assessment_id' => $foreign->id])->assertSessionHasErrors('assessment_id');
        $this->assertSame(0, SubjectFile::count());

        $this->upload(['assessment_id' => $this->b->id])
            ->assertRedirect(route('lecturer.subjects.edit', $this->subject).'#assessment-'.$this->b->id);
    }

    public function test_the_whole_subject_block_takes_files_with_no_assessment(): void
    {
        $this->upload(['assessment_id' => ''])->assertRedirect(route('lecturer.subjects.edit', $this->subject).'#assessment-shared');
        $this->assertNull(SubjectFile::sole()->assessment_id);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringContainsString('Sheet', substr($html, strpos($html, 'data-whole-subject')));
        $this->assertStringNotContainsString('+ Add a shared file', $html);
    }

    public function test_an_empty_whole_subject_block_is_a_link(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringContainsString('+ Add a shared file', $html);
    }

    public function test_the_subject_form_has_no_brief_or_due_date_and_ignores_them(): void
    {
        $this->subject->update(['brief' => 'Old brief', 'project_deadline' => '2026-12-01']);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringNotContainsString('name="project_deadline"', $html);
        $this->assertDoesNotMatchRegularExpression('/<textarea id="brief" name="brief"/', $html);
        $this->assertStringNotContainsString('Group project due date', $html);
        $this->assertStringContainsString('Subject settings', $html);
        $this->assertStringContainsString('name="semester_start_date"', $html);
        $this->assertStringContainsString('name="group_size"', $html);

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.update', $this->subject), [
            'brief' => 'Sneaked', 'project_deadline' => '2027-01-01', 'group_size' => 5,
        ])->assertRedirect();

        $fresh = $this->subject->fresh();
        $this->assertSame('Old brief', $fresh->brief);
        $this->assertSame('2026-12-01', $fresh->project_deadline->format('Y-m-d'));
        $this->assertSame(5, $fresh->group_size);
    }

    public function test_the_assessment_brief_is_saved_for_its_own_subject_only(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($this->lecturer)->put(route('lecturer.subjects.assessments.update', [$this->subject, $this->b]), [
            'title' => 'Project', 'type' => 'project', 'starts_on' => '2026-04-01', 'due_on' => '2026-05-01', 'brief' => 'Written here',
        ])->assertRedirect(route('lecturer.subjects.edit', $this->subject).'#assessment-'.$this->b->id);
        $this->assertSame('Written here', $this->b->fresh()->brief);

        $this->actingAs($other)->put(route('lecturer.subjects.assessments.update', [$this->subject, $this->b]), [
            'title' => 'Project', 'type' => 'project', 'brief' => 'Hijacked',
        ])->assertNotFound();
        $this->assertSame('Written here', $this->b->fresh()->brief);
    }

    public function test_the_page_is_one_full_width_stack_with_a_two_column_assessment_grid(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();

        $this->assertStringContainsString('data-edit-stack', $html);
        $this->assertStringNotContainsString('data-edit-grid', $html);
        $this->assertStringNotContainsString('data-edit-side', $html);
        $this->assertStringNotContainsString('Manage its groups', $html);
        $this->assertStringNotContainsString('aria-label="Groups"', $html);
        $this->assertMatchesRegularExpression('/<ul class="grid items-stretch[^"]*min-\[1280px\]:grid-cols-2"[^>]*data-assessment-list/', $html);
        $this->assertStringContainsString('min-[1280px]:last:odd:col-span-full', $html);
        $this->assertStringNotContainsString('sticky top-6', $html);
    }
    public function test_the_kind_labels_are_the_same_everywhere(): void
    {
        $this->assertSame('Question', SubjectFile::KINDS['question']);
        $this->assertSame('Marking guide', SubjectFile::KINDS['marking_guide']);
    }
}
