<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\SubjectFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Course materials (shared files) can be shown under chosen assessments only, and have more kinds. */
class CourseMaterialsTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Assessment $a1;

    protected Assessment $a2;

    protected User $student;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $this->a1 = $this->subject->assessments()->create(['title' => 'Assignment 1', 'type' => 'assignment', 'position' => 1, 'starts_on' => '2026-02-01', 'due_on' => '2026-03-01']);
        $this->a2 = $this->subject->assessments()->create(['title' => 'Assignment 2', 'type' => 'assignment', 'position' => 2, 'starts_on' => '2026-04-01', 'due_on' => '2026-05-01']);
        $this->student = User::factory()->student()->create(['must_change_password' => false]);
        $this->group = Group::create(['subject_id' => $this->subject->id, 'name' => 'G1', 'group_size' => 4]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);
    }

    protected function upload(string $title, array $extra = [])
    {
        return $this->actingAs($this->lecturer)->post(route('lecturer.subjects.files.store', $this->subject), [
            'file' => UploadedFile::fake()->createWithContent('n.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
            'title' => $title, 'kind' => 'notes', 'assessment_id' => '', ...$extra,
        ]);
    }

    public function test_a_shared_file_with_no_choice_is_shown_under_every_assessment(): void
    {
        $this->upload('Handbook')->assertSessionHasNoErrors();

        $file = SubjectFile::sole();
        $this->assertNull($file->assessment_id);
        $this->assertSame(0, $file->targets()->count());
        $this->assertTrue($file->load('targets')->reaches($this->a1));
        $this->assertTrue($file->reaches($this->a2));
        $this->assertSame('All assessments', $file->audienceLabel());
    }

    public function test_a_shared_file_can_be_for_one_assessment_or_both(): void
    {
        $this->upload('Only A1', ['assessment_ids' => [$this->a1->id]])->assertSessionHasNoErrors();
        $this->upload('Both', ['assessment_ids' => [$this->a1->id, $this->a2->id]])->assertSessionHasNoErrors();

        $only = SubjectFile::where('title', 'Only A1')->first()->load('targets');
        $both = SubjectFile::where('title', 'Both')->first()->load('targets');

        $this->assertTrue($only->reaches($this->a1));
        $this->assertFalse($only->reaches($this->a2));
        $this->assertTrue($both->reaches($this->a1) && $both->reaches($this->a2));
        $this->assertSame('Assignment 1', $only->audienceLabel());
        $this->assertSame('Assignment 1, Assignment 2', $both->audienceLabel());

        // The student's brief card for each assessment lists only what reaches it.
        $a2Page = $this->actingAs($this->student)->get(route('student.workspace.show', $this->group).'?assessment='.$this->a2->id)->assertOk()->getContent();
        $this->assertStringContainsString('Both', $a2Page);
        $this->assertStringNotContainsString('Only A1', $a2Page);

        $a1Page = $this->actingAs($this->student)->get(route('student.workspace.show', $this->group).'?assessment='.$this->a1->id)->getContent();
        $this->assertStringContainsString('Only A1', $a1Page);
        $this->assertStringContainsString('Both', $a1Page);
    }

    public function test_the_form_sends_all_or_exactly_one_assessment(): void
    {
        $this->upload('One', ['show_under' => (string) $this->a2->id])->assertSessionHasNoErrors();
        $this->upload('Everywhere', ['show_under' => 'all'])->assertSessionHasNoErrors();
        $this->upload('Bad', ['show_under' => '999999'])->assertSessionHasErrors('show_under');

        $one = SubjectFile::where('title', 'One')->first()->load('targets');
        $this->assertSame([$this->a2->id], $one->targets->pluck('id')->all());
        $this->assertFalse($one->reaches($this->a1));
        $this->assertSame(0, SubjectFile::where('title', 'Everywhere')->first()->targets()->count());
        $this->assertSame(2, SubjectFile::count());
    }

    public function test_another_subjects_assessment_is_rejected_as_a_target(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ9999', 'name' => 'Other', 'group_size' => 4]);
        $foreign = $other->assessments()->create(['title' => 'Foreign', 'type' => 'project', 'position' => 1]);

        $this->upload('Bad', ['assessment_ids' => [$foreign->id]])->assertSessionHasErrors('assessment_ids.0');
        $this->assertSame(0, SubjectFile::count());
    }

    public function test_the_new_kinds_are_accepted_and_labelled(): void
    {
        foreach (['notes' => 'Notes', 'slides' => 'Slides', 'other' => 'Other'] as $kind => $label) {
            $this->upload("A {$kind}", ['kind' => $kind])->assertSessionHasNoErrors();
            $this->assertSame($label, SubjectFile::where('title', "A {$kind}")->first()->kindLabel());
        }

        $this->upload('Bad kind', ['kind' => 'video'])->assertSessionHasErrors('kind');
    }

    public function test_the_lecturer_page_offers_the_choice_and_shows_the_audience(): void
    {
        $this->upload('Only A2', ['assessment_ids' => [$this->a2->id]]);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->assertOk()->getContent();

        $this->assertStringContainsString('data-file-targets', $html);
        $this->assertStringContainsString('type="radio" name="show_under" value="all" checked', $html);
        $this->assertStringContainsString('type="radio" name="show_under" value="'.$this->a2->id.'"', $html);
        $this->assertStringNotContainsString('name="assessment_ids[]"', $html, 'One choice, not several ticks.');
        $this->assertStringContainsString('<option value="slides">Slides</option>', $html);
        $this->assertStringContainsString('<option value="notes">Notes</option>', $html);
        $this->assertStringContainsString('data-file-scope', $html);
    }

    public function test_deleting_an_assessment_does_not_delete_a_shared_file(): void
    {
        $this->upload('Only A2', ['assessment_ids' => [$this->a2->id]]);

        $this->a2->delete();

        $file = SubjectFile::sole()->load('targets');
        $this->assertTrue($file->reaches($this->a1), 'With no target left it is shown under what remains.');
    }
}
