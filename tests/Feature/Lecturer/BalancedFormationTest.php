<?php

namespace Tests\Feature\Lecturer;

use App\Models\AppNotification;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFormationCohort;
use Tests\TestCase;

/**
 * Mode 1: balanced allocation by CGPA.
 *
 * The property that matters most is that nothing is written until the lecturer
 * confirms. Several of these tests count rows before and after each step for
 * exactly that reason.
 */
class BalancedFormationTest extends TestCase
{
    use BuildsFormationCohort;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCohort();
    }

    protected function preview(int $groups = 4, ?array $students = null, array $extra = [])
    {
        return $this->actingAs($this->lecturer)->post(
            route('lecturer.formation.balanced.preview', $this->subject),
            ['students' => $students ?? $this->cohort->pluck('id')->all(), 'groups' => $groups] + $extra,
        );
    }

    protected function held(string $token): array
    {
        return session('formation.balanced.'.$token);
    }

    protected function counts(): array
    {
        return [Group::count(), GroupMember::count(), AppNotification::count()];
    }

    /*
    | The hub
    */

    public function test_the_hub_lists_who_still_needs_a_group(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', $this->subject))
            ->assertOk()
            ->assertSee('Without a group')
            ->assertSee('Student A1')
            ->assertSee('3.95')
            ->assertSee('Balance groups by CGPA')
            ->assertSee('Import the groups students chose');
    }

    public function test_the_hub_says_cgpa_is_entered_by_hand(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', $this->subject))
            ->assertOk()
            ->assertSee('entered by hand')
            ->assertSee('not connected to any academic');
    }

    public function test_a_student_already_in_a_group_is_not_offered(): void
    {
        $this->makeGroup('Existing', [$this->cohort[0]]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', $this->subject))
            ->assertOk()
            // Matched on the matric number: "Student A1" is also a prefix of
            // "Student A10" to "A13", which would make the name useless here.
            ->assertDontSee('A21EC0001')
            ->assertSee('A21EC0002');
    }

    public function test_the_hub_says_so_when_nobody_needs_placing(): void
    {
        $this->makeGroup('All of them', $this->cohort->take(4)->all());
        $this->makeGroup('And the rest', $this->cohort->slice(4)->all(), 10);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', $this->subject))
            ->assertOk()
            ->assertSee('Fewer than two students need a group');
    }

    /*
    | Preview before save
    */

    public function test_previewing_writes_nothing(): void
    {
        $before = $this->counts();

        $response = $this->preview()->assertRedirect();

        $this->assertSame($before, $this->counts(), 'A preview must not create groups, members or notifications.');

        $this->actingAs($this->lecturer)
            ->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('This is a preview')
            ->assertSee('Nothing has been saved yet');
    }

    public function test_the_preview_shows_every_groups_average_cgpa(): void
    {
        $token = $this->tokenFrom($this->preview());

        $proposal = $this->held($token)['proposal'];

        $page = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.balanced.show', [$this->subject, $token]))
            ->assertOk()
            ->assertSee('Average CGPA');

        foreach ($proposal['groups'] as $group) {
            $page->assertSee($group['name']);
            $page->assertSee(number_format($group['average'], 2));
        }
    }

    public function test_regenerating_writes_nothing_either(): void
    {
        $token = $this->tokenFrom($this->preview());
        $before = $this->counts();

        foreach (range(1, 5) as $_) {
            $this->actingAs($this->lecturer)
                ->post(route('lecturer.formation.balanced.regenerate', [$this->subject, $token]))
                ->assertRedirect(route('lecturer.formation.balanced.show', [$this->subject, $token]));
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_regenerating_gives_a_different_arrangement(): void
    {
        $token = $this->tokenFrom($this->preview());

        $arrangements = [];

        foreach (range(1, 10) as $_) {
            $this->actingAs($this->lecturer)
                ->post(route('lecturer.formation.balanced.regenerate', [$this->subject, $token]));

            $arrangements[] = json_encode(array_map(
                fn ($g) => array_column($g['members'], 'id'),
                $this->held($token)['proposal']['groups'],
            ));
        }

        // Regenerate has to do something, or the button looks broken.
        $this->assertGreaterThan(1, count(array_unique($arrangements)));
    }

    public function test_the_first_preview_is_the_plain_snake_and_regenerate_changes_the_seed(): void
    {
        $token = $this->tokenFrom($this->preview());

        $this->assertSame(0, $this->held($token)['proposal']['seed']);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.regenerate', [$this->subject, $token]));

        $this->assertNotSame(0, $this->held($token)['proposal']['seed']);
    }

    /*
    | Confirming
    */

    public function test_confirming_creates_the_groups_exactly_as_previewed(): void
    {
        $token = $this->tokenFrom($this->preview());

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.regenerate', [$this->subject, $token]));

        $proposal = $this->held($token)['proposal'];

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]))
            ->assertRedirect(route('lecturer.groups', ['subject' => $this->subject->id]))
            ->assertSessionHas('status');

        $this->assertSame(4, Group::count());
        $this->assertSame(13, GroupMember::count());

        // What was saved is what was on screen, group by group.
        foreach ($proposal['groups'] as $planned) {
            $saved = Group::where('name', $planned['name'])->sole();

            $this->assertEqualsCanonicalizing(
                array_column($planned['members'], 'id'),
                $saved->groupMembers()->pluck('user_id')->all(),
                "{$planned['name']} was not saved as previewed.",
            );
        }
    }

    public function test_confirming_records_how_each_group_was_formed(): void
    {
        $token = $this->tokenFrom($this->preview());
        $proposal = $this->held($token)['proposal'];

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]));

        $group = Group::where('name', $proposal['groups'][0]['name'])->sole();

        $this->assertSame(Group::FORMED_BALANCED, $group->formation_method);
        $this->assertSame($this->lecturer->id, $group->formed_by);
        $this->assertNotNull($group->formed_at);

        $this->assertSame('snake_draft', $group->formation_meta['algorithm']);
        $this->assertSame($proposal['groups'][0]['average'], $group->formation_meta['average_cgpa']);
        $this->assertTrue($group->formation_meta['cgpa_entered_manually']);
        $this->assertSame(4, $group->formation_meta['batch_size']);

        // One batch id across the set, so "formed together" can be shown later.
        $this->assertSame(1, Group::all()->pluck('formation_meta.batch')->unique()->count());
    }

    public function test_the_recorded_average_is_a_snapshot_not_a_live_figure(): void
    {
        $token = $this->tokenFrom($this->preview());
        $proposal = $this->held($token)['proposal'];

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]));

        // The administrator corrects everyone's CGPA afterwards.
        User::query()->where('role', 'student')->update(['cgpa' => 1.00]);

        $group = Group::where('name', $proposal['groups'][0]['name'])->sole();

        $this->assertSame($proposal['groups'][0]['average'], $group->formation_meta['average_cgpa']);
    }

    public function test_each_student_is_told_they_were_placed(): void
    {
        $token = $this->tokenFrom($this->preview());

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]));

        $this->assertSame(13, AppNotification::where('type', 'group_assigned')->count());
    }

    public function test_a_proposal_cannot_be_confirmed_twice(): void
    {
        $token = $this->tokenFrom($this->preview());

        $url = route('lecturer.formation.balanced.confirm', [$this->subject, $token]);

        $this->actingAs($this->lecturer)->post($url);
        $this->actingAs($this->lecturer)->post($url)->assertSessionHasErrors('status');

        $this->assertSame(4, Group::count(), 'A second confirm must not create a second set.');
    }

    /*
    | Headcounts that do not divide evenly
    */

    public function test_thirteen_students_into_four_groups_saves_four_three_three_three(): void
    {
        $token = $this->tokenFrom($this->preview(4));

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]));

        $sizes = Group::withCount('groupMembers')->get()->pluck('group_members_count')->sort()->values()->all();

        $this->assertSame([3, 3, 3, 4], $sizes);
    }

    public function test_no_group_exceeds_the_subjects_limit(): void
    {
        $token = $this->tokenFrom($this->preview(4));

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]));

        foreach (Group::withCount('groupMembers')->get() as $group) {
            $this->assertLessThanOrEqual($this->subject->group_size, $group->group_members_count);
        }
    }

    /*
    | Choosing the pool and the number of groups
    */

    public function test_only_the_chosen_students_are_placed(): void
    {
        $chosen = $this->cohort->take(8)->pluck('id')->all();

        $token = $this->tokenFrom($this->preview(2, $chosen));

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]));

        $this->assertEqualsCanonicalizing($chosen, GroupMember::pluck('user_id')->all());
    }

    public function test_too_few_groups_for_the_size_limit_is_refused(): void
    {
        // Thirteen students with a limit of four cannot fit in three groups.
        $this->preview(3)->assertSessionHasErrors('groups');

        $this->assertSame(0, Group::count());
    }

    public function test_too_many_groups_to_give_everyone_a_partner_is_refused(): void
    {
        $this->preview(7)->assertSessionHasErrors('groups');
    }

    public function test_the_refusal_says_what_range_would_work(): void
    {
        $this->preview(3);

        $this->assertStringContainsString('between 4 and 6', session('errors')->first('groups'));
    }

    public function test_at_least_two_students_are_needed(): void
    {
        $this->preview(1, [$this->cohort[0]->id])->assertSessionHasErrors('students');
    }

    public function test_a_student_who_already_has_a_group_is_dropped_from_the_pool(): void
    {
        $this->makeGroup('Existing', [$this->cohort[0]]);

        $token = $this->tokenFrom($this->preview(4));

        $ids = collect($this->held($token)['students'])->pluck('id');

        $this->assertFalse($ids->contains($this->cohort[0]->id));
        $this->assertCount(12, $ids);
    }

    public function test_an_id_that_is_not_a_student_is_ignored(): void
    {
        $ids = $this->cohort->pluck('id')->push($this->lecturer->id)->all();

        $token = $this->tokenFrom($this->preview(4, $ids));

        $this->assertFalse(collect($this->held($token)['students'])->pluck('id')->contains($this->lecturer->id));
    }

    public function test_a_deactivated_student_is_never_placed(): void
    {
        $this->cohort[0]->forceFill(['is_active' => false])->save();

        $token = $this->tokenFrom($this->preview(4));

        $this->assertFalse(collect($this->held($token)['students'])->pluck('id')->contains($this->cohort[0]->id));
    }

    public function test_group_names_skip_ones_already_taken(): void
    {
        $this->makeGroup('Group 1');
        $this->makeGroup('group 2');

        $token = $this->tokenFrom($this->preview(4));

        $names = array_column($this->held($token)['proposal']['groups'], 'name');

        $this->assertSame(['Group 3', 'Group 4', 'Group 5', 'Group 6'], $names);
    }

    public function test_a_custom_name_prefix_is_used(): void
    {
        $token = $this->tokenFrom($this->preview(4, null, ['prefix' => 'Team']));

        $this->assertSame('Team 1', $this->held($token)['proposal']['groups'][0]['name']);
    }

    /*
    | Students with no CGPA
    */

    public function test_a_student_with_no_cgpa_is_placed_and_flagged(): void
    {
        $this->cohort[5]->forceFill(['cgpa' => null])->save();

        $token = $this->tokenFrom($this->preview(4));

        $this->assertCount(13, collect($this->held($token)['proposal']['groups'])->pluck('members')->flatten(1));

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.balanced.show', [$this->subject, $token]))
            ->assertOk()
            ->assertSee('no CGPA');
    }

    public function test_a_cohort_with_no_cgpa_at_all_is_warned_loudly(): void
    {
        User::query()->where('role', 'student')->update(['cgpa' => null]);

        $token = $this->tokenFrom($this->preview(4));

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.balanced.show', [$this->subject, $token]))
            ->assertOk()
            ->assertSee('nothing to balance on');
    }

    /*
    | Things that changed while the lecturer was looking
    */

    public function test_confirming_after_a_student_was_placed_elsewhere_saves_nothing(): void
    {
        $token = $this->tokenFrom($this->preview(4));

        // Placed by hand, in another tab, after the preview was made.
        $this->makeGroup('Placed by hand', [$this->cohort[3]]);

        $before = Group::count();

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]))
            ->assertRedirect(route('lecturer.formation', $this->subject))
            ->assertSessionHasErrors('status');

        $this->assertSame($before, Group::count(), 'A stale preview must save none of its groups.');
        $this->assertSame(1, GroupMember::count());
    }

    public function test_a_stale_preview_is_discarded_not_left_to_retry(): void
    {
        $token = $this->tokenFrom($this->preview(4));

        $this->makeGroup('Placed by hand', [$this->cohort[3]]);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]));

        $this->assertNull(session('formation.balanced.'.$token));
    }

    public function test_a_deactivated_account_since_the_preview_saves_nothing(): void
    {
        $token = $this->tokenFrom($this->preview(4));

        $this->cohort[2]->forceFill(['is_active' => false])->save();

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Group::count());
    }

    public function test_a_name_taken_since_the_preview_saves_nothing(): void
    {
        $token = $this->tokenFrom($this->preview(4));

        $this->makeGroup('Group 1');

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]))
            ->assertSessionHasErrors('status');

        // Only the one made by hand.
        $this->assertSame(1, Group::count());
    }

    public function test_an_unknown_token_is_an_expired_preview_not_an_error_page(): void
    {
        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, 'doesnotexist000000000000']))
            ->assertRedirect(route('lecturer.formation', $this->subject))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Group::count());
    }

    /*
    | Ownership
    */

    public function test_another_lecturer_cannot_open_the_hub(): void
    {
        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($stranger)
            ->get(route('lecturer.formation', $this->subject))
            ->assertNotFound();
    }

    public function test_another_lecturer_cannot_preview_for_this_subject(): void
    {
        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($stranger)->post(route('lecturer.formation.balanced.preview', $this->subject), [
            'students' => $this->cohort->pluck('id')->all(),
            'groups' => 4,
        ])->assertNotFound();

        $this->assertSame(0, Group::count());
    }

    public function test_another_lecturer_cannot_confirm_a_proposal_even_with_its_token(): void
    {
        $token = $this->tokenFrom($this->preview(4));

        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($stranger)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]))
            ->assertNotFound();

        $this->assertSame(0, Group::count());
    }

    public function test_a_token_does_not_work_against_a_different_subject(): void
    {
        $token = $this->tokenFrom($this->preview(4));

        $other = \App\Models\Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECV2113', 'name' => 'HCI', 'group_size' => 4,
        ]);

        // The lecturer owns both, but a proposal made for one is not valid for
        // the other.
        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$other, $token]))
            ->assertRedirect(route('lecturer.formation', $other))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Group::count());
    }

    public function test_students_cannot_reach_any_of_it(): void
    {
        $student = $this->cohort[0];

        $this->actingAs($student)->get(route('lecturer.formation', $this->subject))->assertForbidden();
        $this->actingAs($student)->post(route('lecturer.formation.balanced.preview', $this->subject), [
            'students' => $this->cohort->pluck('id')->all(), 'groups' => 4,
        ])->assertForbidden();
    }
}
