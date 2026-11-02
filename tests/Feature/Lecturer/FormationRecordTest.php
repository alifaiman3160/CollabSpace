<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsFormationCohort;
use Tests\TestCase;

/**
 * Where the lecturer meets group formation, and the record of how a group
 * came about.
 *
 * A group made by hand, one dealt out by the balancer and one imported from the
 * students' own choices fail for different reasons, so the lecturer has to be
 * able to tell them apart afterwards. These cover that record, the screens that
 * show it, and the entry points that lead to formation in the first place.
 */
class FormationRecordTest extends TestCase
{
    use BuildsFormationCohort;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCohort();
    }

    /*
    | Entry points
    */

    public function test_the_groups_screen_offers_formation_for_the_chosen_subject(): void
    {
        $this->makeGroup('Group A');

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups', ['subject' => $this->subject->id]))
            ->assertOk()
            ->assertSee('Auto-assign students')
            ->assertSee(route('lecturer.formation', $this->subject), false);
    }

    public function test_with_one_subject_the_button_needs_no_choosing(): void
    {
        $this->makeGroup('Group A');

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups'))
            ->assertOk()
            ->assertSee(route('lecturer.formation', $this->subject), false);
    }

    public function test_with_several_subjects_and_none_chosen_it_offers_a_list_to_pick_from(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECV2113', 'name' => 'HCI', 'group_size' => 3]);

        $this->makeGroup('Group A');

        // The button must not disappear just because no subject is chosen: that
        // left a lecturer with several subjects thinking there was no such
        // feature. It opens a list instead, with a way into each subject.
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups'))
            ->assertOk()
            ->assertSee('Auto-assign students')
            ->assertSee('For which subject?')
            ->assertSee(route('lecturer.formation', $this->subject), false)
            ->assertSee(route('lecturer.formation', $other), false);
    }

    public function test_both_ways_of_making_groups_are_always_offered_together(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECV2113', 'name' => 'HCI', 'group_size' => 3]);

        $this->makeGroup('Group A');

        foreach ([
            route('lecturer.groups'),
            route('lecturer.groups', ['subject' => $this->subject->id]),
            route('lecturer.groups', ['subject' => $other->id]),
        ] as $url) {
            $this->actingAs($this->lecturer)
                ->get($url)
                ->assertOk()
                ->assertSee('Auto-assign students')
                ->assertSee('Add one group');
        }
    }

    public function test_the_list_of_subjects_is_not_shown_once_one_is_chosen(): void
    {
        Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECV2113', 'name' => 'HCI', 'group_size' => 3]);

        $this->makeGroup('Group A');

        // With a subject chosen it is a plain link, not a menu.
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups', ['subject' => $this->subject->id]))
            ->assertOk()
            ->assertDontSee('For which subject?');
    }

    public function test_an_empty_subject_offers_both_choices(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups', ['subject' => $this->subject->id]))
            ->assertOk()
            ->assertSee('No groups here yet')
            ->assertSee('Add one group')
            ->assertSee('Auto-assign students')
            ->assertSee(route('lecturer.formation', $this->subject), false);
    }

    public function test_a_lecturer_with_no_subjects_is_not_offered_either(): void
    {
        $newcomer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($newcomer)
            ->get(route('lecturer.groups'))
            ->assertOk()
            ->assertSee('No subjects assigned yet')
            ->assertDontSee('Auto-assign students');
    }
    public function test_the_subjects_screen_links_to_formation(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.subjects'))
            ->assertOk()
            ->assertSee(route('lecturer.formation', $this->subject), false);
    }

    public function test_the_hub_links_back_to_the_subjects_groups(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', $this->subject))
            ->assertOk()
            ->assertSee(route('lecturer.groups', ['subject' => $this->subject->id]), false);
    }

    public function test_the_import_screens_render(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.import', $this->subject))
            ->assertOk()
            ->assertSee('Upload the form')
            ->assertSee('One row per group')
            ->assertSee('One row per student')
            ->assertSee('Never by name');

        $csv = "Timestamp,Group name,Member 1\nx,Alpha,".'A21EC0001'."\n";

        $response = $this->actingAs($this->lecturer)->post(route('lecturer.formation.import.store', $this->subject), [
            'layout' => 'group_rows',
            'file' => UploadedFile::fake()->createWithContent('r.csv', $csv),
        ]);

        $this->actingAs($this->lecturer)
            ->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Check the columns')
            ->assertSee('Group name')
            ->assertSee('Member 1')
            ->assertSee('A21EC0001');
    }

    /*
    | The record: manual
    */

    public function test_a_group_created_by_hand_is_recorded_as_manual(): void
    {
        $this->actingAs($this->lecturer)->post(route('lecturer.groups.store'), [
            'subject_id' => $this->subject->id,
            'name' => 'By Hand',
            'group_size' => 4,
        ])->assertSessionHasNoErrors();

        $group = Group::where('name', 'By Hand')->sole();

        $this->assertSame(Group::FORMED_MANUAL, $group->formation_method);
        $this->assertSame($this->lecturer->id, $group->formed_by);
        $this->assertNotNull($group->formed_at);
    }

    public function test_groups_that_existed_before_the_record_count_as_manual(): void
    {
        // Before this, a group could only be made by hand, so that is true.
        $group = $this->makeGroup('Old Group');

        $this->assertSame('manual', $group->fresh()->formation_method);
        $this->assertNull($group->fresh()->formed_at);
    }

    public function test_a_manual_group_says_so_on_its_screen(): void
    {
        $group = $this->makeGroup('By Hand');

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $group))
            ->assertOk()
            ->assertSee('Created manually')
            ->assertSee('Created by hand, with members assigned one at a time');
    }

    public function test_an_old_group_with_no_formation_date_falls_back_to_when_it_was_created(): void
    {
        $group = $this->makeGroup('Old Group');

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $group))
            ->assertOk()
            ->assertSee($group->created_at->format('j F Y'));
    }

    /*
    | The record: balanced
    */

    protected function balancedBatch(): array
    {
        $batch = 'batch-1';

        return collect(['Group 1' => 3.31, 'Group 2' => 3.27])->map(fn ($average, $name) => Group::create([
            'subject_id' => $this->subject->id,
            'name' => $name,
            'group_size' => 4,
            'formation_method' => Group::FORMED_BALANCED,
            'formation_meta' => [
                'batch' => $batch, 'batch_size' => 2, 'algorithm' => 'snake_draft',
                'average_cgpa' => $average, 'cgpa_known' => 3, 'cgpa_unknown' => 1,
                'spread_across_batch' => 0.04, 'cgpa_entered_manually' => true,
            ],
            'formed_by' => $this->lecturer->id,
            'formed_at' => now(),
        ]))->values()->all();
    }

    public function test_a_balanced_group_shows_its_average_and_its_batch(): void
    {
        [$first, $second] = $this->balancedBatch();

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $first))
            ->assertOk()
            ->assertSee('Balanced by CGPA')
            ->assertSee('snake draft')
            ->assertSee('3.31')
            ->assertSee('0.04 apart')
            ->assertSee('Group 2')
            ->assertSee(route('lecturer.groups.show', $second), false);
    }

    public function test_a_balanced_group_admits_its_cgpas_were_typed_by_hand(): void
    {
        [$first] = $this->balancedBatch();

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $first))
            ->assertOk()
            ->assertSee('entered by hand and may have changed since');
    }

    public function test_it_says_how_many_members_had_no_cgpa(): void
    {
        [$first] = $this->balancedBatch();

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $first))
            ->assertOk()
            ->assertSee('1 member had none');
    }

    public function test_a_batch_of_one_says_it_has_no_company(): void
    {
        $lone = Group::create([
            'subject_id' => $this->subject->id, 'name' => 'Lone', 'group_size' => 4,
            'formation_method' => Group::FORMED_BALANCED,
            'formation_meta' => ['batch' => 'solo', 'average_cgpa' => 3.0, 'cgpa_known' => 2, 'cgpa_unknown' => 0],
            'formed_by' => $this->lecturer->id, 'formed_at' => now(),
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $lone))
            ->assertOk()
            ->assertSee('no other group');
    }

    public function test_batches_do_not_bleed_into_each_other(): void
    {
        [$first] = $this->balancedBatch();

        Group::create([
            'subject_id' => $this->subject->id, 'name' => 'Elsewhere', 'group_size' => 4,
            'formation_method' => Group::FORMED_BALANCED,
            'formation_meta' => ['batch' => 'a-different-batch', 'average_cgpa' => 3.0, 'cgpa_known' => 2, 'cgpa_unknown' => 0],
            'formed_by' => $this->lecturer->id, 'formed_at' => now(),
        ]);

        $this->assertSame(['Group 2'], $first->formationPeers()->pluck('name')->all());
    }

    /*
    | The record: imported
    */

    public function test_an_imported_group_names_its_file_and_rows(): void
    {
        $group = Group::create([
            'subject_id' => $this->subject->id, 'name' => 'Team Alpha', 'group_size' => 5,
            'formation_method' => Group::FORMED_IMPORTED,
            'formation_meta' => [
                'source' => 'sem1-responses.csv', 'rows' => [4, 9], 'matched_by_hand' => 2,
                'limit_raised' => true, 'problem_groups_skipped' => true,
            ],
            'formed_by' => $this->lecturer->id, 'formed_at' => now(),
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $group))
            ->assertOk()
            ->assertSee('Imported from CSV')
            ->assertSee('sem1-responses.csv')
            ->assertSee('rows 4, 9')
            ->assertSee('2 students had to be matched by hand')
            ->assertSee('size limit was raised')
            ->assertSee('Groups with problems were left out');
    }

    public function test_the_filename_is_escaped_not_rendered(): void
    {
        $group = Group::create([
            'subject_id' => $this->subject->id, 'name' => 'Team Alpha', 'group_size' => 4,
            'formation_method' => Group::FORMED_IMPORTED,
            'formation_meta' => ['source' => '<script>alert(1)</script>.csv', 'rows' => [2]],
            'formed_by' => $this->lecturer->id, 'formed_at' => now(),
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $group))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    /*
    | The list shows it at a glance
    */

    public function test_the_group_list_labels_each_groups_origin(): void
    {
        [$balanced] = $this->balancedBatch();
        $this->makeGroup('By Hand');
        Group::create([
            'subject_id' => $this->subject->id, 'name' => 'Brought In', 'group_size' => 4,
            'formation_method' => Group::FORMED_IMPORTED, 'formation_meta' => ['source' => 'x.csv'],
            'formed_by' => $this->lecturer->id, 'formed_at' => now(),
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups', ['subject' => $this->subject->id]))
            ->assertOk()
            ->assertSee('Manual')
            ->assertSee('Balanced')
            ->assertSee('Imported');
    }

    /*
    | Ownership
    */

    public function test_another_lecturer_cannot_see_the_record(): void
    {
        [$first] = $this->balancedBatch();

        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($stranger)
            ->get(route('lecturer.groups.show', $first))
            ->assertNotFound();
    }

    public function test_the_record_survives_the_lecturer_account_going(): void
    {
        [$first] = $this->balancedBatch();

        $this->lecturer->forceFill(['is_active' => true])->save();

        // formed_by is nulled, not cascaded, when an account is deleted.
        $first->forceFill(['formed_by' => null])->save();

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $first))
            ->assertOk()
            ->assertSee('Balanced by CGPA');
    }
}
