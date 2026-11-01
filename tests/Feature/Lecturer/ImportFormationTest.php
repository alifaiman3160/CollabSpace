<?php

namespace Tests\Feature\Lecturer;

use App\Models\AppNotification;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\Formation\ImportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsFormationCohort;
use Tests\TestCase;

/**
 * Mode 2: importing groups from a Google Form CSV.
 *
 * The cohort is thirteen students whose matric numbers run A21EC0001 to
 * A21EC0013, so a CSV can name them by number the way a real form would. Every
 * problem the review screen is meant to catch has a test of its own, because
 * the screen is only worth having if it catches all of them.
 */
class ImportFormationTest extends TestCase
{
    use BuildsFormationCohort;
    use RefreshDatabase;

    protected const HEADER = 'Timestamp,Group name,Member 1,Member 2,Member 3,Member 4';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCohort();
    }

    /** The matric number of the nth student, counting from one. */
    protected function m(int $n): string
    {
        return sprintf('A21EC%04d', $n);
    }

    /** A one-row-per-group CSV from [name => [matric, ...]]. */
    protected function csv(array $groups, string $header = self::HEADER): string
    {
        $lines = [$header];

        foreach ($groups as $name => $members) {
            $lines[] = '2026-10-01 09:00:00,'.$name.','.implode(',', $members);
        }

        return implode("\n", $lines)."\n";
    }

    protected function upload(string $csv, string $layout = ImportBuilder::LAYOUT_GROUP_ROWS, string $filename = 'responses.csv')
    {
        return $this->actingAs($this->lecturer)->post(
            route('lecturer.formation.import.store', $this->subject),
            ['layout' => $layout, 'file' => UploadedFile::fake()->createWithContent($filename, $csv)],
        );
    }

    /** Uploads, maps the columns the obvious way, and returns the token. */
    protected function reviewed(string $csv, ?array $roles = null, string $layout = ImportBuilder::LAYOUT_GROUP_ROWS): string
    {
        $token = $this->tokenFromColumns($this->upload($csv, $layout));

        $headers = $this->held($token)['headers'];

        $roles ??= array_map(fn ($h) => match (true) {
            $h === 'Group name' => 'group',
            str_starts_with($h, 'Member') || in_array($h, ['Matric number', 'Email'], true) => 'member',
            default => 'ignore',
        }, $headers);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.import.map', [$this->subject, $token]), ['roles' => $roles])
            ->assertRedirect(route('lecturer.formation.import.review', [$this->subject, $token]));

        return $token;
    }

    protected function tokenFromColumns($response): string
    {
        $response->assertRedirect();

        return basename(dirname(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    protected function held(string $token): ?array
    {
        return session('formation.import.'.$token);
    }

    protected function review(string $token)
    {
        return $this->actingAs($this->lecturer)->get(route('lecturer.formation.import.review', [$this->subject, $token]));
    }

    protected function decide(string $token, array $fields)
    {
        return $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.import.decide', [$this->subject, $token]), $fields);
    }

    protected function confirm(string $token, string $mode = 'all')
    {
        return $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.import.confirm', [$this->subject, $token]), ['mode' => $mode]);
    }

    /** The key the draft gave the nth group, or the person at a position. */
    protected function groupKey(string $token, string $name): string
    {
        foreach ($this->held($token)['draft']['groups'] as $key => $group) {
            if ($group['name'] === $name) {
                return $key;
            }
        }

        $this->fail("No group called {$name} in the draft.");
    }

    protected function personKey(string $token, string $raw): string
    {
        foreach ($this->held($token)['draft']['groups'] as $group) {
            foreach ($group['members'] as $person) {
                if ($person['raw'] === $raw) {
                    return $person['key'];
                }
            }
        }

        $this->fail("No person called {$raw} in the draft.");
    }

    protected function counts(): array
    {
        return [Group::count(), GroupMember::count(), AppNotification::count()];
    }

    /*
    |--------------------------------------------------------------------------
    | The happy path
    |--------------------------------------------------------------------------
    */

    public function test_a_clean_file_imports_in_full(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2), $this->m(3), $this->m(4)],
            'Team Beta' => [$this->m(5), $this->m(6), $this->m(7), $this->m(8)],
        ]));

        $this->review($token)
            ->assertOk()
            ->assertSee('Groups that read cleanly')
            ->assertSee('Team Alpha')
            ->assertSee('Team Beta')
            ->assertSee('Create 2 groups');

        $this->confirm($token)->assertRedirect(route('lecturer.formation', [$this->subject, 'section' => $this->section->id, 'after' => 'import']));

        $this->assertSame(2, Group::count());
        $this->assertSame(8, GroupMember::count());
        $this->assertSame(8, AppNotification::where('type', 'group_assigned')->count());
    }

    public function test_an_imported_group_records_how_it_came_about(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $this->confirm($token);

        $group = Group::where('name', 'Team Alpha')->sole();

        $this->assertSame(Group::FORMED_IMPORTED, $group->formation_method);
        $this->assertSame($this->lecturer->id, $group->formed_by);
        $this->assertSame('responses.csv', $group->formation_meta['source']);
        $this->assertSame([2], $group->formation_meta['rows']);
        $this->assertSame('Imported from CSV', $group->formationLabel());
    }

    public function test_nothing_is_created_at_any_step_before_confirming(): void
    {
        $before = $this->counts();

        $csv = $this->csv(['Team Alpha' => [$this->m(1), $this->m(2)], 'Team Beta' => ['A99XX9999']]);

        $token = $this->tokenFromColumns($this->upload($csv));
        $this->assertSame($before, $this->counts(), 'uploading');

        $this->actingAs($this->lecturer)->get(route('lecturer.formation.import.columns', [$this->subject, $token]))->assertOk();
        $this->assertSame($before, $this->counts(), 'the column screen');

        $token = $this->reviewed($csv);
        $this->assertSame($before, $this->counts(), 'reading the groups');

        $this->review($token)->assertOk();
        $this->decide($token, ['_decisions' => 1]);
        $this->assertSame($before, $this->counts(), 'reviewing and deciding');
    }

    public function test_a_confirmed_import_cannot_be_confirmed_again(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $this->confirm($token);
        $this->confirm($token)->assertSessionHasErrors('status');

        $this->assertSame(1, Group::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Matching: matric first, email second, never name
    |--------------------------------------------------------------------------
    */

    public function test_students_are_matched_by_matric_number(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $this->assertSame('matric', $this->held($token)['draft']['groups']['g1']['members'][0]['matched_by']);
    }

    public function test_a_student_is_matched_by_email_when_there_is_no_matric(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->cohort[0]->email, $this->cohort[1]->email]]));

        $person = $this->held($token)['draft']['groups']['g1']['members'][0];

        $this->assertSame($this->cohort[0]->id, $person['user_id']);
        $this->assertSame('email', $person['matched_by']);
    }

    public function test_an_email_in_capitals_still_matches(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [strtoupper($this->cohort[0]->email), $this->m(2)]]));

        $this->assertSame($this->cohort[0]->id, $this->held($token)['draft']['groups']['g1']['members'][0]['user_id']);
    }

    public function test_a_name_alone_never_matches_anyone(): void
    {
        // A student really is called "Ahmad Faiz". Typing exactly that as the
        // answer must still not match: two students can share a name, and a
        // name is spelt a dozen ways.
        $this->cohort[4]->forceFill(['name' => 'Ahmad Faiz'])->save();

        $token = $this->reviewed($this->csv(['Team Alpha' => ['Ahmad Faiz', $this->m(2)]]));

        $person = $this->held($token)['draft']['groups']['g1']['members'][0];

        $this->assertNull($person['user_id']);

        $this->review($token)
            ->assertSee('Students not found')
            ->assertSee('That looks like a name')
            ->assertSee('Names are never used to match students');
    }

    public function test_matric_wins_when_both_are_given_and_agree(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1).' '.$this->cohort[0]->email, $this->m(2)]]));

        $this->assertSame($this->cohort[0]->id, $this->held($token)['draft']['groups']['g1']['members'][0]['user_id']);
    }

    public function test_a_matric_and_email_that_disagree_are_left_for_the_lecturer(): void
    {
        // The number says one student and the email says another. Neither is
        // trusted.
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1).' '.$this->cohort[1]->email, $this->m(3)]]));

        $person = $this->held($token)['draft']['groups']['g1']['members'][0];

        $this->assertNull($person['user_id']);
        $this->assertSame([$this->cohort[0]->id, $this->cohort[1]->id], $person['conflict']);

        $this->review($token)->assertSee('belong to different accounts');
    }

    public function test_a_matric_typed_with_spaces_or_hyphens_still_matches(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => ['A21-EC-0001', 'a21 ec 0002']]));

        $members = $this->held($token)['draft']['groups']['g1']['members'];

        $this->assertSame($this->cohort[0]->id, $members[0]['user_id']);
        $this->assertSame($this->cohort[1]->id, $members[1]['user_id']);
    }

    public function test_several_members_in_one_cell_are_all_read(): void
    {
        // Losing the second and third of "A, B, C" silently is the failure to
        // avoid, so a cell listing several people yields several people.
        $csv = self::HEADER."\n".'2026-10-01,"Team Alpha","'.$this->m(1).', '.$this->m(2).', '.$this->m(3).'",,,'."\n";

        $token = $this->reviewed($csv);

        $this->assertCount(3, $this->held($token)['draft']['groups']['g1']['members']);
    }

    public function test_a_members_in_the_same_cell_separated_by_new_lines_are_all_read(): void
    {
        $csv = self::HEADER."\n".'2026-10-01,"Team Alpha","'.$this->m(1)."\n".$this->m(2)."\n".$this->m(3).'",,,'."\n";

        $token = $this->reviewed($csv);

        $this->assertCount(3, $this->held($token)['draft']['groups']['g1']['members']);
    }

    /*
    |--------------------------------------------------------------------------
    | Problem: named but not found
    |--------------------------------------------------------------------------
    */

    public function test_a_student_who_is_not_in_the_system_blocks_a_full_import(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2), 'A99XX9999']]));

        $this->review($token)
            ->assertOk()
            ->assertSee('Students not found')
            ->assertSee('A99XX9999')
            ->assertSee('No account has this matric number or email')
            ->assertSee('Resolve the problems to import everything');

        $this->confirm($token)->assertSessionHasErrors('status');

        $this->assertSame(0, Group::count());
    }

    public function test_a_not_found_student_can_be_matched_by_hand(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2), 'A99XX9999']]));

        $this->decide($token, ['assign' => [$this->personKey($token, 'A99XX9999') => $this->cohort[7]->id]]);

        $this->review($token)->assertSee('Matched by you to '.$this->cohort[7]->name);

        $this->confirm($token);

        $this->assertEqualsCanonicalizing(
            [$this->cohort[0]->id, $this->cohort[1]->id, $this->cohort[7]->id],
            Group::where('name', 'Team Alpha')->sole()->groupMembers()->pluck('user_id')->all(),
        );

        $this->assertSame(1, Group::first()->formation_meta['matched_by_hand']);
    }

    public function test_a_not_found_student_can_be_removed_instead(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2), 'A99XX9999']]));

        $key = $this->personKey($token, 'A99XX9999');

        $this->decide($token, ['seen' => ['remove' => [$key => 1]], 'remove' => [$key => 1]]);
        $this->confirm($token);

        $this->assertSame(2, GroupMember::count());
    }

    public function test_a_removal_can_be_undone(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2), 'A99XX9999']]));

        $key = $this->personKey($token, 'A99XX9999');

        $this->decide($token, ['seen' => ['remove' => [$key => 1]], 'remove' => [$key => 1]]);
        $this->review($token)->assertSee('Removed by you');

        // The box comes back unticked: marker present, box absent.
        $this->decide($token, ['seen' => ['remove' => [$key => 1]]]);

        $this->assertEmpty($this->held($token)['decisions']['remove']);
    }

    /*
    |--------------------------------------------------------------------------
    | Problem: students who cannot be placed
    |--------------------------------------------------------------------------
    */

    public function test_a_student_already_in_a_group_for_this_subject_cannot_be_imported(): void
    {
        $this->makeGroup('Existing', [$this->cohort[0]]);

        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $this->review($token)
            ->assertSee('Already in Existing for this subject');

        $this->confirm($token)->assertSessionHasErrors('status');
    }

    public function test_a_deactivated_account_cannot_be_imported(): void
    {
        $this->cohort[0]->forceFill(['is_active' => false])->save();

        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $this->review($token)->assertSee('This account has been deactivated');
    }

    public function test_a_lecturer_account_is_not_a_student(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->lecturer->email, $this->m(2)]]));

        $this->review($token)->assertSee('not a student');
    }

    /*
    |--------------------------------------------------------------------------
    | Problem: in more than one group
    |--------------------------------------------------------------------------
    */

    public function test_a_student_in_two_groups_is_flagged(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2)],
            'Team Beta' => [$this->m(1), $this->m(3)],
        ]));

        $this->review($token)
            ->assertOk()
            ->assertSee('Students in more than one group')
            ->assertSee('Listed in Team Alpha and Team Beta');

        $this->confirm($token)->assertSessionHasErrors('status');
        $this->assertSame(0, Group::count());
    }

    public function test_choosing_where_they_stay_resolves_it(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2)],
            'Team Beta' => [$this->m(1), $this->m(3)],
        ]));

        $this->decide($token, ['keep' => [$this->cohort[0]->id => $this->groupKey($token, 'Team Beta')]]);
        $this->confirm($token);

        $this->assertSame(
            [$this->cohort[0]->id],
            Group::where('name', 'Team Beta')->sole()->groupMembers()->where('user_id', $this->cohort[0]->id)->pluck('user_id')->all(),
        );
        $this->assertSame(0, Group::where('name', 'Team Alpha')->sole()->groupMembers()->where('user_id', $this->cohort[0]->id)->count());

        // In exactly one group, never two.
        $this->assertSame(1, GroupMember::where('user_id', $this->cohort[0]->id)->count());
    }

    public function test_leaving_them_out_of_both_is_allowed(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2)],
            'Team Beta' => [$this->m(1), $this->m(3)],
        ]));

        $this->decide($token, ['keep' => [$this->cohort[0]->id => 'none']]);
        $this->confirm($token);

        $this->assertSame(0, GroupMember::where('user_id', $this->cohort[0]->id)->count());
        $this->assertSame(2, Group::count());
    }

    public function test_the_same_student_listed_twice_in_one_group_is_one_member(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(1), $this->m(2)]]));

        $this->review($token)->assertOk();
        $this->confirm($token);

        $this->assertSame(2, GroupMember::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Problem: over the size limit
    |--------------------------------------------------------------------------
    */

    public function test_a_group_over_the_limit_is_flagged(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2), $this->m(3), $this->m(4), $this->m(5)],
        ], 'Timestamp,Group name,Member 1,Member 2,Member 3,Member 4,Member 5'));

        $this->review($token)
            ->assertOk()
            ->assertSee('Groups over the size limit')
            ->assertSee('5 members, limit 4');

        $this->confirm($token)->assertSessionHasErrors('status');
    }

    public function test_removing_someone_brings_a_group_back_under_the_limit(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2), $this->m(3), $this->m(4), $this->m(5)],
        ], 'Timestamp,Group name,Member 1,Member 2,Member 3,Member 4,Member 5'));

        $key = $this->personKey($token, $this->m(5));

        $this->decide($token, ['seen' => ['remove' => [$key => 1]], 'remove' => [$key => 1]]);
        $this->confirm($token);

        $this->assertSame(4, GroupMember::count());
    }

    public function test_the_limit_can_be_raised_for_one_group(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2), $this->m(3), $this->m(4), $this->m(5)],
        ], 'Timestamp,Group name,Member 1,Member 2,Member 3,Member 4,Member 5'));

        $key = $this->groupKey($token, 'Team Alpha');

        $this->decide($token, ['seen' => ['raise' => [$key => 1]], 'raise' => [$key => 1]]);
        $this->confirm($token);

        $group = Group::where('name', 'Team Alpha')->sole();

        $this->assertSame(5, $group->group_size);
        $this->assertSame(5, $group->groupMembers()->count());
        $this->assertTrue($group->formation_meta['limit_raised']);

        // The subject's own limit is untouched.
        $this->assertSame(4, $this->subject->fresh()->group_size);
    }

    public function test_no_amount_of_raising_allows_more_than_ten(): void
    {
        $matrics = array_map(fn ($n) => $this->m($n), range(1, 11));

        $headers = 'Timestamp,Group name,'.implode(',', array_map(fn ($n) => "Member {$n}", range(1, 11)));

        $token = $this->reviewed($this->csv(['Big Team' => $matrics], $headers));

        $key = $this->groupKey($token, 'Big Team');

        $this->decide($token, ['seen' => ['raise' => [$key => 1]], 'raise' => [$key => 1]]);

        $this->review($token)->assertSee('which no group can have');
        $this->confirm($token)->assertSessionHasErrors('status');
    }

    /*
    |--------------------------------------------------------------------------
    | Problem: names
    |--------------------------------------------------------------------------
    */

    public function test_a_group_name_already_in_use_is_flagged_and_can_be_renamed(): void
    {
        $this->makeGroup('Team Alpha');

        $token = $this->reviewed($this->csv(['team alpha' => [$this->m(1), $this->m(2)]]));

        $this->review($token)->assertSee('A group with this name already exists');

        $key = $this->groupKey($token, 'team alpha');

        $this->decide($token, ['rename' => [$key => 'Team Gamma']]);
        $this->confirm($token);

        $this->assertSame(1, Group::where('name', 'Team Gamma')->count());
    }

    public function test_a_group_with_no_name_has_to_be_named(): void
    {
        $token = $this->reviewed($this->csv(['' => [$this->m(1), $this->m(2)]]));

        $this->review($token)->assertSee('This group has no name');
        $this->confirm($token)->assertSessionHasErrors('status');
    }

    public function test_two_groups_renamed_to_the_same_thing_are_flagged(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2)],
            'Team Beta' => [$this->m(3), $this->m(4)],
        ]));

        $this->decide($token, ['rename' => [$this->groupKey($token, 'Team Beta') => 'team alpha']]);

        $this->review($token)->assertSee('Another group in this file ends up with the same name');
    }

    public function test_rows_with_the_same_group_name_are_one_group(): void
    {
        // One leader submitting twice must not make two groups.
        $token = $this->reviewed($this->csv([
            'Team Alpha ' => [$this->m(1), $this->m(2)],
        ]).'2026-10-01 10:00:00,TEAM  ALPHA,'.$this->m(3).','.$this->m(4).",,\n");

        $this->assertCount(1, $this->held($token)['draft']['groups']);
        $this->assertCount(4, $this->held($token)['draft']['groups']['g1']['members']);
    }

    /*
    |--------------------------------------------------------------------------
    | Students who never appear, and the leftovers
    |--------------------------------------------------------------------------
    */

    public function test_students_the_form_never_mentioned_are_listed(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $page = $this->review($token)->assertSee('Students who never appear');

        $this->assertSame(11, $this->cohort->count() - 2);
        $page->assertSee($this->m(13));
    }

    public function test_a_student_who_is_in_the_file_is_not_listed_as_missing(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $html = $this->review($token)->getContent();
        $section = substr($html, strpos($html, 'Students who never appear'));
        $section = substr($section, 0, strpos($section, 'Every group in the file'));

        $this->assertStringNotContainsString($this->m(1), $section);
        $this->assertStringContainsString($this->m(3), $section);
    }

    public function test_after_importing_the_leftovers_are_offered_balanced_allocation(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2), $this->m(3), $this->m(4)]]));

        $this->confirm($token);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', [$this->subject, 'after' => 'import']))
            ->assertOk()
            ->assertSee('9 students were not in any group you imported')
            ->assertSee('Balance groups by CGPA');
    }

    public function test_leftovers_can_then_be_placed_with_balanced_allocation(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2), $this->m(3), $this->m(4)],
            'Team Beta' => [$this->m(5), $this->m(6), $this->m(7), $this->m(8)],
        ]));

        $this->confirm($token);

        // Five are left. They are the pool for Mode 1, and only they.
        $left = User::where('role', 'student')->whereDoesntHave('groups')->pluck('id')->all();
        $this->assertCount(5, $left);

        $preview = $this->actingAs($this->lecturer)->post(
            route('lecturer.formation.balanced.preview', $this->subject),
            ['students' => $left, 'groups' => 2],
        );

        $balancedToken = basename(parse_url($preview->headers->get('Location'), PHP_URL_PATH));

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.balanced.confirm', [$this->subject, $balancedToken]));

        // Both modes now sit side by side, each recorded as what it was.
        $this->assertSame(2, Group::where('formation_method', Group::FORMED_IMPORTED)->count());
        $this->assertSame(2, Group::where('formation_method', Group::FORMED_BALANCED)->count());
        $this->assertSame(0, User::where('role', 'student')->whereDoesntHave('groups')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Skipping the bad rows
    |--------------------------------------------------------------------------
    */

    public function test_problem_groups_can_be_skipped_and_the_rest_imported(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2)],
            'Team Beta' => [$this->m(3), 'A99XX9999'],
            'Team Gamma' => [$this->m(5), $this->m(6)],
        ]));

        $this->review($token)
            ->assertSee('Or skip the problem groups')
            ->assertSee('Skip 1 and create 2');

        $this->confirm($token, 'clean');

        $this->assertEqualsCanonicalizing(['Team Alpha', 'Team Gamma'], Group::pluck('name')->all());

        // The skipped group's students are simply still without a group.
        $this->assertSame(0, GroupMember::where('user_id', $this->cohort[2]->id)->count());
    }

    public function test_skipping_is_recorded_on_the_groups_that_were_made(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2)],
            'Team Beta' => [$this->m(3), 'A99XX9999'],
        ]));

        $this->confirm($token, 'clean');

        $this->assertTrue(Group::where('name', 'Team Alpha')->sole()->formation_meta['problem_groups_skipped']);
    }

    public function test_skip_is_not_offered_when_there_is_nothing_clean_to_import(): void
    {
        $token = $this->reviewed($this->csv(['Team Beta' => [$this->m(3), 'A99XX9999']]));

        $this->review($token)->assertDontSee('Or skip the problem groups');

        $this->confirm($token, 'clean')->assertSessionHasErrors('status');
        $this->assertSame(0, Group::count());
    }

    public function test_the_full_import_is_refused_while_problems_remain_even_in_skip_mode_for_all(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2)],
            'Team Beta' => [$this->m(3), 'A99XX9999'],
        ]));

        $this->confirm($token, 'all')->assertSessionHasErrors('status');

        $this->assertSame(0, Group::count(), 'Asking for everything must not quietly import part of it.');
    }

    public function test_a_group_can_be_left_out_on_purpose(): void
    {
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2)],
            'Team Beta' => [$this->m(3), 'A99XX9999'],
        ]));

        $key = $this->groupKey($token, 'Team Beta');

        $this->decide($token, ['seen' => ['skip' => [$key => 1]], 'skip' => [$key => 1]]);

        // Left out deliberately is a decision, not an unresolved problem, so the
        // rest can be imported in full.
        $this->review($token)->assertSee('Create 1 group');

        $this->confirm($token, 'all');

        $this->assertSame(['Team Alpha'], Group::pluck('name')->all());
    }

    public function test_a_skipped_group_does_not_claim_its_students(): void
    {
        // Alpha and Beta both list student 1, but Beta is left out, so there is
        // no longer anything to decide.
        $token = $this->reviewed($this->csv([
            'Team Alpha' => [$this->m(1), $this->m(2)],
            'Team Beta' => [$this->m(1), $this->m(3)],
        ]));

        $key = $this->groupKey($token, 'Team Beta');

        $this->decide($token, ['seen' => ['skip' => [$key => 1]], 'skip' => [$key => 1]]);
        $this->confirm($token, 'all');

        $this->assertSame(['Team Alpha'], Group::pluck('name')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Decisions survive
    |--------------------------------------------------------------------------
    */

    public function test_a_decision_survives_its_control_disappearing(): void
    {
        // The rename box sits beside the name problem and moves to the group
        // list once the problem is fixed. A replace-everything form would have
        // dropped the rename at that moment and the problem would be back.
        $this->makeGroup('Team Alpha');

        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $key = $this->groupKey($token, 'Team Alpha');

        $this->decide($token, ['rename' => [$key => 'Team Delta']]);

        // A later submission that says nothing about the rename.
        $this->decide($token, ['seen' => ['skip' => [$key => 1]]]);

        $this->assertSame('Team Delta', $this->held($token)['decisions']['rename'][$key]);

        $this->confirm($token);

        $this->assertSame(1, Group::where('name', 'Team Delta')->count());
    }

    public function test_decisions_about_things_that_do_not_exist_are_ignored(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $this->decide($token, [
            'assign' => ['p999' => $this->cohort[0]->id],
            'rename' => ['g999' => 'Ghost'],
            'seen' => ['skip' => ['g999' => 1]],
            'skip' => ['g999' => 1],
        ]);

        $decisions = $this->held($token)['decisions'];

        $this->assertEmpty($decisions['assign']);
        $this->assertEmpty($decisions['rename']);
        $this->assertEmpty($decisions['skip']);
    }

    public function test_choices_made_on_the_confirm_click_itself_count(): void
    {
        // The confirm button submits the same form, so decisions on screen at
        // that moment have to be applied before it is judged.
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2), 'A99XX9999']]));

        $key = $this->personKey($token, 'A99XX9999');

        $this->actingAs($this->lecturer)->post(route('lecturer.formation.import.confirm', [$this->subject, $token]), [
            'mode' => 'all',
            '_decisions' => 1,
            'seen' => ['remove' => [$key => 1]],
            'remove' => [$key => 1],
        ]);

        $this->assertSame(1, Group::count());
        $this->assertSame(2, GroupMember::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Things that change while reviewing
    |--------------------------------------------------------------------------
    */

    public function test_a_student_placed_by_hand_during_review_is_caught(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $this->makeGroup('Placed by hand', [$this->cohort[0]]);

        $this->review($token)->assertSee('Already in Placed by hand');
        $this->confirm($token)->assertSessionHasErrors('status');

        $this->assertSame(1, Group::count());
    }

    /*
    |--------------------------------------------------------------------------
    | One row per student
    |--------------------------------------------------------------------------
    */

    public function test_a_one_row_per_student_form_groups_by_the_name_they_chose(): void
    {
        $csv = "Timestamp,Matric number,Email,Group name\n"
            .'2026-10-01,'.$this->m(1).','.$this->cohort[0]->email.",Team Alpha\n"
            .'2026-10-01,'.$this->m(2).",,team alpha\n"
            .'2026-10-01,'.$this->m(3).",,Team Beta\n"
            .'2026-10-01,'.$this->m(4).",,Team Beta\n";

        $token = $this->reviewed($csv, null, ImportBuilder::LAYOUT_STUDENT_ROWS);

        $this->assertCount(2, $this->held($token)['draft']['groups']);

        $this->confirm($token);

        $this->assertSame(2, Group::where('name', 'Team Alpha')->sole()->groupMembers()->count());
        $this->assertSame(2, Group::where('name', 'Team Beta')->sole()->groupMembers()->count());
    }

    public function test_one_student_answering_twice_for_different_groups_is_flagged(): void
    {
        $csv = "Timestamp,Matric number,Email,Group name\n"
            .'2026-10-01,'.$this->m(1).",,Team Alpha\n"
            .'2026-10-01,'.$this->m(2).",,Team Alpha\n"
            .'2026-10-02,'.$this->m(1).",,Team Beta\n"
            .'2026-10-02,'.$this->m(3).",,Team Beta\n";

        $token = $this->reviewed($csv, null, ImportBuilder::LAYOUT_STUDENT_ROWS);

        $this->review($token)->assertSee('Students in more than one group');
    }

    public function test_in_a_one_row_per_student_form_the_matric_and_email_belong_to_the_same_person(): void
    {
        $csv = "Timestamp,Matric number,Email,Group name\n"
            .'2026-10-01,'.$this->m(1).','.$this->cohort[0]->email.",Team Alpha\n"
            .'2026-10-01,,'.$this->cohort[1]->email.",Team Alpha\n";

        $token = $this->reviewed($csv, null, ImportBuilder::LAYOUT_STUDENT_ROWS);

        // Two rows, two people: not three.
        $this->assertCount(2, $this->held($token)['draft']['groups']['g1']['members']);
    }

    /*
    |--------------------------------------------------------------------------
    | Mapping the columns
    |--------------------------------------------------------------------------
    */

    public function test_the_columns_are_guessed_from_google_style_headings(): void
    {
        $token = $this->tokenFromColumns($this->upload(
            "Timestamp,Group name,Member 1 (matric number),Member 2 (matric number),Leader's email\n".'x,Alpha,'.$this->m(1).','.$this->m(2).",a@b.test\n"
        ));

        $this->assertSame(['ignore', 'group', 'member', 'member', 'member'], $this->held($token)['roles']);
    }

    public function test_a_name_column_is_not_guessed_as_a_member_column(): void
    {
        $token = $this->tokenFromColumns($this->upload(
            "Timestamp,Group name,Member 1 name,Member 1 matric\nx,Alpha,Ali,".$this->m(1)."\n"
        ));

        $this->assertSame(['ignore', 'group', 'ignore', 'member'], $this->held($token)['roles']);
    }

    public function test_exactly_one_group_column_is_required(): void
    {
        $token = $this->tokenFromColumns($this->upload($this->csv(['Alpha' => [$this->m(1)]])));

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.import.map', [$this->subject, $token]), ['roles' => ['ignore', 'ignore', 'member', 'member', 'member', 'member']])
            ->assertSessionHasErrors('roles');

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.import.map', [$this->subject, $token]), ['roles' => ['group', 'group', 'member', 'ignore', 'ignore', 'ignore']])
            ->assertSessionHasErrors('roles');
    }

    public function test_at_least_one_member_column_is_required(): void
    {
        $token = $this->tokenFromColumns($this->upload($this->csv(['Alpha' => [$this->m(1)]])));

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.import.map', [$this->subject, $token]), ['roles' => ['ignore', 'group', 'ignore', 'ignore', 'ignore', 'ignore']])
            ->assertSessionHasErrors('roles');
    }

    public function test_changing_the_columns_starts_the_decisions_afresh(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), 'A99XX9999']]));

        $key = $this->personKey($token, 'A99XX9999');
        $this->decide($token, ['assign' => [$key => $this->cohort[2]->id]]);

        $this->assertNotEmpty($this->held($token)['decisions']['assign']);

        $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), 'A99XX9999']]));

        // A different token, so a clean slate; and re-mapping the same one
        // resets too, because the people have new keys.
        $this->actingAs($this->lecturer)->post(
            route('lecturer.formation.import.map', [$this->subject, $token]),
            ['roles' => $this->held($token)['roles']],
        );

        $this->assertEmpty($this->held($token)['decisions']);
    }

    /*
    |--------------------------------------------------------------------------
    | The upload itself
    |--------------------------------------------------------------------------
    */

    public function test_a_file_that_is_not_a_csv_is_refused(): void
    {
        $this->actingAs($this->lecturer)->post(route('lecturer.formation.import.store', $this->subject), [
            'layout' => ImportBuilder::LAYOUT_GROUP_ROWS,
            'file' => UploadedFile::fake()->createWithContent('responses.pdf', '%PDF-1.4'),
        ])->assertSessionHasErrors('file');
    }

    public function test_an_empty_file_is_refused_with_a_plain_message(): void
    {
        $this->upload('')->assertSessionHasErrors('file');

        $this->assertStringContainsString('empty', session('errors')->first('file'));
    }

    public function test_a_file_with_only_headings_is_refused(): void
    {
        $this->upload(self::HEADER."\n")->assertSessionHasErrors('file');
    }

    public function test_a_binary_file_renamed_to_csv_is_refused(): void
    {
        $this->upload("PK\x03\x04\x00\x00binary\x00data\x00")->assertSessionHasErrors('file');
    }

    public function test_no_file_is_refused(): void
    {
        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.import.store', $this->subject), ['layout' => ImportBuilder::LAYOUT_GROUP_ROWS])
            ->assertSessionHasErrors('file');
    }

    public function test_an_unknown_layout_is_refused(): void
    {
        $this->actingAs($this->lecturer)->post(route('lecturer.formation.import.store', $this->subject), [
            'layout' => 'sideways',
            'file' => UploadedFile::fake()->createWithContent('r.csv', $this->csv(['A' => [$this->m(1)]])),
        ])->assertSessionHasErrors('layout');
    }

    public function test_the_filename_kept_for_the_record_is_just_a_name(): void
    {
        $token = $this->tokenFromColumns($this->upload(
            $this->csv(['Alpha' => [$this->m(1)]]), ImportBuilder::LAYOUT_GROUP_ROWS, '../../etc/<script>passwd.csv'
        ));

        $this->assertStringNotContainsString('/', $this->held($token)['filename']);
        $this->assertStringNotContainsString('<', $this->held($token)['filename']);
    }

    public function test_the_templates_download_with_the_right_headings(): void
    {
        $perGroup = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.import.template', [$this->subject, 'layout' => ImportBuilder::LAYOUT_GROUP_ROWS]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('Group name', $perGroup->streamedContent());

        $perStudent = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.import.template', [$this->subject, 'layout' => ImportBuilder::LAYOUT_STUDENT_ROWS]))
            ->assertOk();

        $this->assertStringContainsString('Matric number', $perStudent->streamedContent());
    }

    public function test_a_template_can_be_read_back_by_the_importer(): void
    {
        $template = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.import.template', [$this->subject, 'layout' => ImportBuilder::LAYOUT_GROUP_ROWS]))
            ->streamedContent();

        $token = $this->tokenFromColumns($this->upload($template));

        $this->assertSame(['ignore', 'group', 'member', 'member', 'member', 'member'], $this->held($token)['roles']);
    }

    /*
    |--------------------------------------------------------------------------
    | Discarding and expiry
    |--------------------------------------------------------------------------
    */

    public function test_discarding_forgets_the_import_and_creates_nothing(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $this->actingAs($this->lecturer)
            ->delete(route('lecturer.formation.import.discard', [$this->subject, $token]))
            ->assertRedirect(route('lecturer.formation', $this->subject));

        $this->assertNull($this->held($token));
        $this->assertSame(0, Group::count());

        $this->review($token)->assertRedirect(route('lecturer.formation', $this->subject));
    }

    public function test_an_unknown_token_is_an_expired_import_not_an_error_page(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.import.review', [$this->subject, 'doesnotexist000000000000']))
            ->assertRedirect(route('lecturer.formation', $this->subject))
            ->assertSessionHasErrors('status');
    }

    public function test_the_review_is_not_reachable_before_the_columns_are_mapped(): void
    {
        $token = $this->tokenFromColumns($this->upload($this->csv(['Alpha' => [$this->m(1)]])));

        $this->review($token)->assertRedirect(route('lecturer.formation.import.columns', [$this->subject, $token]));
    }

    /*
    |--------------------------------------------------------------------------
    | Ownership
    |--------------------------------------------------------------------------
    */

    public function test_another_lecturer_cannot_reach_any_import_screen(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($stranger);

        $this->get(route('lecturer.formation.import', $this->subject))->assertNotFound();
        $this->get(route('lecturer.formation.import.template', $this->subject))->assertNotFound();
        $this->post(route('lecturer.formation.import.store', $this->subject), ['layout' => 'group_rows'])->assertNotFound();
        $this->get(route('lecturer.formation.import.columns', [$this->subject, $token]))->assertNotFound();
        $this->get(route('lecturer.formation.import.review', [$this->subject, $token]))->assertNotFound();
        $this->post(route('lecturer.formation.import.decide', [$this->subject, $token]))->assertNotFound();
        $this->post(route('lecturer.formation.import.confirm', [$this->subject, $token]), ['mode' => 'all'])->assertNotFound();
        $this->delete(route('lecturer.formation.import.discard', [$this->subject, $token]))->assertNotFound();

        $this->assertSame(0, Group::count());
    }

    public function test_an_import_held_for_one_subject_is_not_valid_for_another(): void
    {
        $token = $this->reviewed($this->csv(['Team Alpha' => [$this->m(1), $this->m(2)]]));

        $other = \App\Models\Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECV2113', 'name' => 'HCI', 'group_size' => 4,
        ]);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.formation.import.confirm', [$other, $token]), ['mode' => 'all'])
            ->assertRedirect(route('lecturer.formation', $other))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Group::count());
    }

    public function test_students_cannot_reach_the_import(): void
    {
        $this->actingAs($this->cohort[0])
            ->get(route('lecturer.formation.import', $this->subject))
            ->assertForbidden();
    }
}
