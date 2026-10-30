<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\RubricLevel;
use App\Models\RubricSection;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The rubric upload, review and save, and who may reach it.
 */
class RubricImportTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE = __DIR__.'/../../../docs/samples/MarkingRubric.xlsx';

    protected User $lecturer;

    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4,
        ]);
    }

    protected function sample(string $name = 'MarkingRubric.xlsx'): UploadedFile
    {
        return new UploadedFile(self::SAMPLE, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Upload and return the review URL. */
    protected function upload(?User $as = null, ?Subject $subject = null, ?UploadedFile $file = null): string
    {
        $response = $this->actingAs($as ?? $this->lecturer)->post(
            route('lecturer.rubrics.store', $subject ?? $this->subject),
            ['file' => $file ?? $this->sample()],
        );

        return $response->assertRedirect()->headers->get('Location');
    }

    protected function token(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    /** The posted form for a draft, as the review screen would send it: the parsed sample, optionally edited. */
    protected function draftPayload(?callable $edit = null): array
    {
        $draft = (new \App\Support\Rubric\RubricParser)->parse(self::SAMPLE, 'MarkingRubric.xlsx');
        $payload = ['name' => $draft['name'], 'sections' => $draft['sections']];

        foreach ($payload['sections'] as &$section) {
            foreach ($section['criteria'] as &$criterion) {
                foreach ($criterion['levels'] as &$level) {
                    $level['range'] = $level['range'] ?? '';
                }
            }
        }
        unset($section, $criterion, $level);

        return $edit ? $edit($payload) : $payload;
    }

    protected function confirm(string $url, array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->lecturer)->post($url, $payload);
    }

    /*
    | Upload and review write nothing
    */

    public function test_uploading_reads_the_file_and_saves_nothing(): void
    {
        $url = $this->upload();

        $this->assertStringContainsString('/rubrics/import/', $url);
        $this->assertSame(0, Rubric::count());
        $this->assertSame(0, RubricSection::count());
        $this->assertSame(0, RubricCriterion::count());
        $this->assertSame(0, RubricLevel::count());
    }

    public function test_the_review_screen_shows_both_tables_with_their_levels(): void
    {
        $url = $this->upload();

        $this->actingAs($this->lecturer)->get($url)
            ->assertOk()
            ->assertSee('Nothing is saved yet')
            ->assertSee('CLO2 – Report Quality &amp; Presentation (30 marks)', false)
            ->assertSee('CLO4 – Teamwork (10 marks)')
            ->assertSee('Problem statement and objectives')
            ->assertSee('Contribution to tasks')
            ->assertSee('value="4–5"', false)
            ->assertSee('value="1.5"', false)
            ->assertSee('Save as version 1');

        $this->assertSame(0, Rubric::count(), 'Viewing the review must not save anything.');
    }

    public function test_the_review_defaults_clo2_to_manual_and_clo4_to_system_suggested(): void
    {
        $html = $this->actingAs($this->lecturer)->get($this->upload())->getContent();

        preg_match_all('/<section data-section.*?<select id="section-\d+-mode".*?<\/select>/s', $html, $sections);

        $this->assertCount(2, $sections[0]);
        $this->assertMatchesRegularExpression('/<option value="manual"\s+selected/s', $sections[0][0]);
        $this->assertMatchesRegularExpression('/<option value="suggested"\s+selected/s', $sections[0][1]);
    }

    public function test_every_section_and_criterion_can_be_set_to_either_mode_on_the_review_screen(): void
    {
        $html = $this->actingAs($this->lecturer)->get($this->upload())->getContent();

        $this->assertSame(2, substr_count($html, '<section data-section'));
        $this->assertSame(2, preg_match_all('/<select id="section-\d+-mode"/', $html));
        $this->assertSame(12, preg_match_all('/<select name="[^"]+\[mode\]" data-criterion-mode/', $html));
        $this->assertSame(14, substr_count($html, 'System-suggested</option>'));
        $this->assertSame(14, substr_count($html, 'Manual</option>'));
    }

    public function test_the_review_screen_lets_rows_be_deleted_and_names_each_delete(): void
    {
        $html = $this->actingAs($this->lecturer)->get($this->upload())->getContent();

        $this->assertStringContainsString('aria-label="Delete the section CLO2 – Report Quality &amp; Presentation (30 marks)"', $html);
        $this->assertStringContainsString('aria-label="Delete the criterion Collaboration"', $html);
        $this->assertStringContainsString('aria-label="Delete the level Excellent"', $html);
    }

    /*
    | Confirming saves what was reviewed
    */

    public function test_confirming_saves_the_rubric_as_version_one(): void
    {
        $url = $this->upload();

        $this->confirm($url, $this->draftPayload())
            ->assertRedirect(route('lecturer.rubrics', $this->subject))
            ->assertSessionHas('status');

        $rubric = Rubric::firstOrFail();

        $this->assertSame(1, $rubric->version);
        $this->assertTrue($rubric->is_current);
        $this->assertSame($this->subject->id, $rubric->subject_id);
        $this->assertSame($this->lecturer->id, $rubric->created_by);
        $this->assertSame('MarkingRubric.xlsx', $rubric->source_file);
        $this->assertSame(2, $rubric->sections()->count());
        $this->assertSame(12, $rubric->criteria()->count());
        $this->assertSame(48, RubricLevel::count());
    }

    public function test_the_saved_levels_keep_marks_range_and_descriptor(): void
    {
        $this->confirm($this->upload(), $this->draftPayload());

        $criterion = RubricCriterion::where('name', 'Problem statement and objectives')->firstOrFail();
        $excellent = $criterion->levels()->where('label', 'Excellent')->firstOrFail();

        $this->assertSame('5.00', $excellent->marks);
        $this->assertSame('4–5', $excellent->marks_range);
        $this->assertSame('4–5', $excellent->marksLabel());
        $this->assertStringContainsString('measurable', $excellent->descriptor);
        $this->assertSame('1.00', $criterion->weight);

        $good = RubricCriterion::where('name', 'Collaboration')->firstOrFail()->levels()->where('label', 'Good')->firstOrFail();
        $this->assertSame('1.50', $good->marks);
        $this->assertNull($good->marks_range);
    }

    public function test_what_is_saved_is_what_was_corrected_not_what_the_file_said(): void
    {
        $url = $this->upload();

        $payload = $this->draftPayload(function (array $p) {
            $p['name'] = 'Corrected rubric';
            $p['sections'][0]['title'] = 'CLO2: Written report';
            $p['sections'][0]['criteria'][0]['weight'] = '7,5';             // a comma is fine
            $p['sections'][0]['criteria'][0]['levels'][0]['descriptor'] = 'Reworded.';
            $p['sections'][1]['mode'] = 'manual';                            // section flipped,
            foreach ($p['sections'][1]['criteria'] as $i => $criterion) {      // and so its criteria,
                $p['sections'][1]['criteria'][$i]['mode'] = 'manual';
            }
            $p['sections'][1]['criteria'][0]['mode'] = 'suggested';          // except one set back
            unset($p['sections'][0]['criteria'][6]);                          // a deleted row
            unset($p['sections'][1]['criteria'][1]['levels'][0]);             // a deleted level

            return $p;
        });

        $this->confirm($url, $payload)->assertRedirect(route('lecturer.rubrics', $this->subject));

        $rubric = Rubric::firstOrFail();
        $this->assertSame('Corrected rubric', $rubric->name);

        $first = $rubric->sections()->with('criteria.levels')->get()[0];
        $this->assertSame('CLO2: Written report', $first->title);
        $this->assertCount(6, $first->criteria);
        $this->assertSame('7.50', $first->criteria[0]->weight);
        $this->assertSame('Reworded.', $first->criteria[0]->levels[0]->descriptor);

        $second = $rubric->sections()->with('criteria.levels')->get()[1];
        $this->assertSame('manual', $second->mode);
        $this->assertSame('suggested', $second->criteria[0]->mode);
        $this->assertSame('manual', $second->criteria[1]->mode);
        $this->assertCount(3, $second->criteria[1]->levels);
    }

    public function test_the_draft_is_gone_after_saving_so_a_second_post_does_not_save_again(): void
    {
        $url = $this->upload();

        $this->confirm($url, $this->draftPayload())->assertRedirect(route('lecturer.rubrics', $this->subject));
        $this->confirm($url, $this->draftPayload())->assertRedirect(route('lecturer.rubrics', $this->subject))
            ->assertSessionHasErrors('status');

        $this->assertSame(1, Rubric::count());
    }

    public function test_discarding_saves_nothing_and_ends_the_draft(): void
    {
        $url = $this->upload();

        $this->actingAs($this->lecturer)->delete($url)->assertRedirect(route('lecturer.rubrics', $this->subject));

        $this->actingAs($this->lecturer)->get($url)->assertRedirect(route('lecturer.rubrics', $this->subject));
        $this->assertSame(0, Rubric::count());
    }

    /*
    | A refused save keeps the lecturer's work and says why
    */

    public static function invalidEdits(): array
    {
        return [
            'no name' => [fn ($p) => ['name' => ''] + $p, 'name'],
            'section without a title' => [function ($p) { $p['sections'][0]['title'] = ''; return $p; }, 'sections.0.title'],
            'bad mode' => [function ($p) { $p['sections'][0]['mode'] = 'ai'; return $p; }, 'sections.0.mode'],
            'bad criterion mode' => [function ($p) { $p['sections'][0]['criteria'][0]['mode'] = 'auto'; return $p; }, 'sections.0.criteria.0.mode'],
            'criterion without a name' => [function ($p) { $p['sections'][0]['criteria'][0]['name'] = ''; return $p; }, 'sections.0.criteria.0.name'],
            'weight not a number' => [function ($p) { $p['sections'][0]['criteria'][0]['weight'] = 'heavy'; return $p; }, 'sections.0.criteria.0.weight'],
            'negative weight' => [function ($p) { $p['sections'][0]['criteria'][0]['weight'] = '-1'; return $p; }, 'sections.0.criteria.0.weight'],
            'marks not a number' => [function ($p) { $p['sections'][0]['criteria'][0]['levels'][0]['marks'] = 'x'; return $p; }, 'sections.0.criteria.0.levels.0.marks'],
            'marks missing' => [function ($p) { $p['sections'][0]['criteria'][0]['levels'][0]['marks'] = ''; return $p; }, 'sections.0.criteria.0.levels.0.marks'],
            'level without a label' => [function ($p) { $p['sections'][0]['criteria'][0]['levels'][0]['label'] = ''; return $p; }, 'sections.0.criteria.0.levels.0.label'],
            'criterion with no levels' => [function ($p) { $p['sections'][0]['criteria'][0]['levels'] = []; return $p; }, 'sections.0.criteria.0.levels'],
            'section with no criteria' => [function ($p) { $p['sections'][0]['criteria'] = []; return $p; }, 'sections.0.criteria'],
            'no sections' => [function ($p) { $p['sections'] = []; return $p; }, 'sections'],
            'descriptor too long' => [function ($p) { $p['sections'][0]['criteria'][0]['levels'][0]['descriptor'] = str_repeat('x', 2001); return $p; }, 'sections.0.criteria.0.levels.0.descriptor'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEdits')]
    public function test_an_invalid_review_is_refused_and_nothing_is_saved(callable $edit, string $errorKey): void
    {
        $url = $this->upload();

        $this->confirm($url, $this->draftPayload($edit))->assertSessionHasErrors($errorKey);

        $this->assertSame(0, Rubric::count());
        $this->assertSame(0, RubricLevel::count());

        // The draft survives, so the lecturer can correct it and try again.
        $this->actingAs($this->lecturer)->get($url)->assertOk();
    }

    public function test_a_refused_save_shows_plain_neutral_messages_and_keeps_the_typed_values(): void
    {
        $url = $this->upload();

        $this->actingAs($this->lecturer)->from($url)->post($url, $this->draftPayload(function ($p) {
            $p['sections'][0]['title'] = 'My renamed section';
            $p['sections'][0]['criteria'][0]['name'] = '';

            return $p;
        }))->assertRedirect($url);

        $this->actingAs($this->lecturer)->get($url)
            ->assertSee('The rubric was not saved')
            ->assertSee('Every criterion needs a name.')
            ->assertSee('My renamed section');
    }

    /*
    | Versions
    */

    public function test_a_re_upload_becomes_the_next_version_and_leaves_the_first_untouched(): void
    {
        $this->confirm($this->upload(), $this->draftPayload());
        $first = Rubric::firstOrFail();
        $firstLevelIds = RubricLevel::pluck('id')->all();
        $firstSnapshot = RubricLevel::orderBy('id')->get()->toArray();

        $this->confirm($this->upload(), $this->draftPayload(function ($p) {
            $p['name'] = 'Revised';
            $p['sections'][0]['criteria'][0]['levels'][0]['descriptor'] = 'Different.';

            return $p;
        }));

        $this->assertSame(2, Rubric::count());
        $second = Rubric::where('version', 2)->firstOrFail();

        $this->assertSame('Revised', $second->name);
        $this->assertTrue($second->is_current);
        $this->assertFalse($first->fresh()->is_current);

        // Version 1's levels are exactly as they were.
        $this->assertSame($firstSnapshot, RubricLevel::whereIn('id', $firstLevelIds)->orderBy('id')->get()->toArray());
        $this->assertSame(96, RubricLevel::count());
    }

    public function test_the_review_names_the_next_version(): void
    {
        $this->confirm($this->upload(), $this->draftPayload());

        $this->actingAs($this->lecturer)->get($this->upload())->assertSee('Save as version 2');
    }

    public function test_deleting_the_current_version_makes_the_previous_one_current(): void
    {
        $this->confirm($this->upload(), $this->draftPayload());
        $this->confirm($this->upload(), $this->draftPayload());
        $second = Rubric::where('version', 2)->firstOrFail();

        $this->actingAs($this->lecturer)->delete(route('lecturer.rubrics.destroy', [$this->subject, $second]))
            ->assertRedirect(route('lecturer.rubrics', $this->subject));

        $this->assertSame(1, Rubric::count());
        $this->assertTrue(Rubric::first()->is_current);
        $this->assertSame(48, RubricLevel::count(), 'Only the deleted version\'s rows go.');
    }

    public function test_the_index_and_a_saved_version_render(): void
    {
        $this->confirm($this->upload(), $this->draftPayload());
        $rubric = Rubric::firstOrFail();

        $this->actingAs($this->lecturer)->get(route('lecturer.rubrics', $this->subject))
            ->assertOk()->assertSee('Version 1')->assertSee('Current')->assertSee('Upload a new version');

        $this->actingAs($this->lecturer)->get(route('lecturer.rubrics.show', [$this->subject, $rubric]))
            ->assertOk()->assertSee('CLO4 – Teamwork (10 marks)')->assertSee('4–5')->assertSee('System-suggested')
            ->assertSee('Marks = level marks × weight; maximum 30; declared total 30')
            ->assertSee('Marks = level marks (the weight is the maximum); maximum 10; declared total 10');
    }

    /*
    | Files that are refused
    */

    public function test_a_non_xlsx_file_is_refused(): void
    {
        foreach (['rubric.csv' => 'a,b', 'rubric.pdf' => '%PDF-1.4', 'rubric.xls' => 'x', 'rubric.xlsx.php' => '<?php'] as $name => $content) {
            $this->actingAs($this->lecturer)
                ->post(route('lecturer.rubrics.store', $this->subject), ['file' => UploadedFile::fake()->createWithContent($name, $content)])
                ->assertSessionHasErrors('file');
        }

        $this->assertSame(0, Rubric::count());
    }

    public function test_a_file_named_xlsx_that_is_not_one_is_refused_in_plain_words(): void
    {
        $this->actingAs($this->lecturer)
            ->post(route('lecturer.rubrics.store', $this->subject), ['file' => UploadedFile::fake()->createWithContent('rubric.xlsx', 'not a workbook')])
            ->assertSessionHasErrors(['file' => 'This file could not be read as an .xlsx spreadsheet. Check that it opens in Excel and try again.']);
    }

    public function test_no_file_and_an_oversized_file_are_refused(): void
    {
        $this->actingAs($this->lecturer)->post(route('lecturer.rubrics.store', $this->subject), [])
            ->assertSessionHasErrors(['file' => 'Choose the rubric file to upload.']);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.rubrics.store', $this->subject), ['file' => UploadedFile::fake()->create('big.xlsx', 3000)])
            ->assertSessionHasErrors('file');
    }

    /*
    | Ownership: another lecturer's records are not there
    */

    public function test_another_lecturers_subject_rubric_and_draft_are_404(): void
    {
        $url = $this->upload();
        $this->confirm($url, $this->draftPayload());
        $rubric = Rubric::firstOrFail();

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->actingAs($other);

        $this->get(route('lecturer.rubrics', $this->subject))->assertNotFound();
        $this->post(route('lecturer.rubrics.store', $this->subject), ['file' => $this->sample()])->assertNotFound();
        $this->get(route('lecturer.rubrics.show', [$this->subject, $rubric]))->assertNotFound();
        $this->delete(route('lecturer.rubrics.destroy', [$this->subject, $rubric]))->assertNotFound();

        $this->assertSame(1, Rubric::count());
    }

    public function test_a_draft_cannot_be_opened_or_confirmed_by_someone_else_even_with_its_token(): void
    {
        $url = $this->upload();
        $token = $this->token($url);

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = Subject::create(['lecturer_id' => $other->id, 'code' => 'SECV1', 'name' => 'Other Subject', 'group_size' => 4]);

        // Their own subject, our token: no draft there for them.
        $this->actingAs($other)->get(route('lecturer.rubrics.review', [$theirs, $token]))
            ->assertRedirect(route('lecturer.rubrics', $theirs));
        $this->actingAs($other)->post(route('lecturer.rubrics.confirm', [$theirs, $token]), $this->draftPayload())
            ->assertRedirect(route('lecturer.rubrics', $theirs));

        // Our subject, our token: 404 for them.
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($other)->post($url, $this->draftPayload())->assertNotFound();

        $this->assertSame(0, Rubric::count());
    }

    public function test_a_rubric_is_reached_only_through_its_own_subject(): void
    {
        $this->confirm($this->upload(), $this->draftPayload());
        $rubric = Rubric::firstOrFail();

        $mine = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECV2', 'name' => 'Second Subject', 'group_size' => 4]);

        $this->actingAs($this->lecturer)->get(route('lecturer.rubrics.show', [$mine, $rubric]))->assertNotFound();
        $this->actingAs($this->lecturer)->delete(route('lecturer.rubrics.destroy', [$mine, $rubric]))->assertNotFound();
    }

    public function test_each_lecturer_sees_only_their_own_subjects_rubrics(): void
    {
        $this->confirm($this->upload(), $this->draftPayload(fn ($p) => ['name' => 'Mine only'] + $p));

        $this->flushSession();   // the save's confirmation belongs to the first lecturer's session

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = Subject::create(['lecturer_id' => $other->id, 'code' => 'SECV9', 'name' => 'Other Subject', 'group_size' => 4]);

        $this->actingAs($other)->get(route('lecturer.rubrics', $theirs))
            ->assertOk()->assertDontSee('Mine only')->assertDontSee('Version 1');
    }

    /*
    | Roles
    */

    public function test_students_and_admins_cannot_reach_any_rubric_route(): void
    {
        $url = $this->upload();
        $this->confirm($url, $this->draftPayload());
        $rubric = Rubric::firstOrFail();

        $student = User::factory()->student()->create(['must_change_password' => false]);
        $group = Group::create(['subject_id' => $this->subject->id, 'name' => 'G', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        foreach ([$student, $admin] as $user) {
            $this->actingAs($user);

            $this->get(route('lecturer.rubrics', $this->subject))->assertForbidden();
            $this->post(route('lecturer.rubrics.store', $this->subject), ['file' => $this->sample()])->assertForbidden();
            $this->get(route('lecturer.rubrics.show', [$this->subject, $rubric]))->assertForbidden();
            $this->delete(route('lecturer.rubrics.destroy', [$this->subject, $rubric]))->assertForbidden();
            $this->post(route('lecturer.rubrics.confirm', [$this->subject, 'abc']), $this->draftPayload())->assertForbidden();
        }
    }

    public function test_a_student_never_sees_rubric_content_on_their_own_pages(): void
    {
        $this->confirm($this->upload(), $this->draftPayload());

        $this->flushSession();   // the save's confirmation was the lecturer's, not the student's

        $student = User::factory()->student()->create(['must_change_password' => false]);
        $group = Group::create(['subject_id' => $this->subject->id, 'name' => 'G', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        foreach ([route('student.dashboard'), route('student.workspace.show', $group), route('student.board.show', $group), route('student.notifications')] as $url) {
            $html = $this->actingAs($student)->get($url)->assertOk()->getContent();

            foreach (['Contribution to tasks', 'CLO4', 'Excellent (', 'rubric'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $html, "{$needle} on {$url}");
            }
        }
    }

    /*
    | The entry point
    */

    public function test_the_subjects_screen_links_to_the_rubric(): void
    {
        $this->actingAs($this->lecturer)->get(route('lecturer.subjects'))
            ->assertOk()
            ->assertSee(route('lecturer.rubrics', $this->subject), false)
            ->assertSee('Rubric');
    }
}
