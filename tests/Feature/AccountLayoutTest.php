<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * My account is one page for every role. It uses the shared page header and width like the other pages, with a profile
 * card and the two forms in three equal columns (two between 900 and 1279px, one below).
 */
class AccountLayoutTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['student'], ['lecturer'], ['admin']];
    }

    protected function userFor(string $role): User
    {
        return User::factory()->{$role}()->create(['must_change_password' => false]);
    }

    /** @dataProvider roles */
    public function test_each_role_gets_the_profile_card_and_both_forms_once_in_the_shared_layout(string $role): void
    {
        $user = $this->userFor($role);

        $html = $this->actingAs($user)->get(route('account'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-profile-card'));
        $this->assertSame(1, substr_count($html, 'action="'.route('account.update').'"'));
        $this->assertSame(1, substr_count($html, 'action="'.route('account.password').'"'));
        $this->assertSame(1, substr_count($html, 'data-closing-note'));
        $this->assertSame(1, substr_count($html, 'data-account-cards'));
        $this->assertSame(1, substr_count($html, '>My account</h1>'));

        // The profile card's name and role, and the page title from the shared header.
        $this->assertStringContainsString(e($user->name), $html);
        $this->assertStringContainsString('min-[1280px]:grid-cols-3', $html);
        $this->assertStringContainsString('min-[900px]:col-span-2 min-[1280px]:col-span-1', $html, 'Profile spans both columns on top at medium widths.');

        // The narrow centred wrapper is gone.
        $this->assertStringNotContainsString('max-w-5xl', $html);
        $this->assertStringNotContainsString('mx-auto -mt-4', $html);

        // No dashed boxes, no glows.
        $this->assertStringNotContainsString('border-dashed', substr($html, strpos($html, 'data-account-cards')));
    }

    /** @dataProvider roles */
    public function test_the_forms_still_work_for_each_role(string $role): void
    {
        $user = $this->userFor($role);

        $this->actingAs($user)->patch(route('account.update'), ['name' => 'New Name', 'email' => $user->email])->assertRedirect();
        $this->assertSame('New Name', $user->fresh()->name);

        $this->actingAs($user)->put(route('account.password'), ['current_password' => 'password', 'password' => 'a-new-Password-1', 'password_confirmation' => 'a-new-Password-1'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('account'))->assertRedirect(route('login'));
    }

    public function test_it_is_one_shared_view_not_a_copy_per_role(): void
    {
        $this->assertSame(['edit.blade.php'], array_map('basename', glob(resource_path('views/account/*.blade.php'))));
    }
    /*
    | The profile card's numbers
    */

    protected function stats(string $html): array
    {
        preg_match('/<dl[^>]*data-profile-stats[^>]*>(.*?)<\/dl>/s', $html, $dl);
        preg_match_all('/<dt[^>]*>(.*?)<\/dt>\s*<dd[^>]*>(.*?)<\/dd>/s', $dl[1] ?? '', $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($pair) => [trim($pair[1]) => trim($pair[2])])->all();
    }

    protected function groupFor(Subject $subject, string $name): Group
    {
        return Group::create(['subject_id' => $subject->id, 'name' => $name, 'group_size' => 4]);
    }

    public function test_a_student_with_no_groups_and_with_two_groups_sees_their_own_numbers(): void
    {
        $student = $this->userFor('student');

        $this->assertSame(['Groups' => '0', 'Tasks completed' => '0', 'Member since' => $student->created_at->format('M Y')],
            $this->stats($this->actingAs($student)->get(route('account'))->getContent()));

        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $one = $this->groupFor($subject, 'One');
        $two = $this->groupFor($subject, 'Two');
        $other = $this->groupFor($subject, 'Not mine');
        $friend = User::factory()->student()->create();

        foreach ([$one, $two] as $group) {
            GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);
        }
        GroupMember::create(['group_id' => $other->id, 'user_id' => $friend->id, 'joined_at' => now()]);

        $make = fn ($group, $who, $assignee, $status) => Task::create(['group_id' => $group->id, 'created_by' => $who->id, 'assigned_to' => $assignee->id, 'title' => 'T', 'status' => $status]);
        $make($one, $student, $student, Task::STATUS_DONE);
        $make($two, $student, $student, Task::STATUS_DONE);
        $make($two, $student, $student, 'todo');                 // not done
        $make($other, $friend, $friend, Task::STATUS_DONE);      // someone else's group
        $make($one, $friend, $friend, Task::STATUS_DONE);        // a classmate's task in my group

        $stats = $this->stats($this->actingAs($student)->get(route('account'))->getContent());

        $this->assertSame('2', $stats['Groups']);
        $this->assertSame('2', $stats['Tasks completed'], 'Only tasks assigned to me, done, in my groups.');
    }

    public function test_a_lecturer_sees_subjects_groups_and_students_of_their_own_only(): void
    {
        $lecturer = $this->userFor('lecturer');
        $mine = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'MINE1111', 'name' => 'Mine', 'group_size' => 4]);
        $a = $this->groupFor($mine, 'A');
        $b = $this->groupFor($mine, 'B');
        $shared = User::factory()->student()->create();
        $solo = User::factory()->student()->create();

        foreach ([[$a, $shared], [$b, $shared], [$b, $solo]] as [$group, $who]) {
            GroupMember::create(['group_id' => $group->id, 'user_id' => $who->id, 'joined_at' => now()]);
        }

        $stranger = User::factory()->lecturer()->create();
        $theirs = Subject::create(['lecturer_id' => $stranger->id, 'code' => 'THEIRS22', 'name' => 'Theirs', 'group_size' => 4]);
        $c = $this->groupFor($theirs, 'C');
        GroupMember::create(['group_id' => $c->id, 'user_id' => User::factory()->student()->create()->id, 'joined_at' => now()]);

        $stats = $this->stats($this->actingAs($lecturer)->get(route('account'))->getContent());

        $this->assertSame('1', $stats['Subjects']);
        $this->assertSame('2', $stats['Groups']);
        $this->assertSame('2', $stats['Students'], 'A student in two groups counts once.');
    }

    public function test_an_administrator_has_only_member_since(): void
    {
        $admin = $this->userFor('admin');

        $this->assertSame(['Member since' => $admin->created_at->format('M Y')], $this->stats($this->actingAs($admin)->get(route('account'))->getContent()));
    }

    /** @dataProvider roles */
    public function test_the_profile_card_is_full_with_a_flat_band_a_big_avatar_and_few_queries(string $role): void
    {
        $user = $this->userFor($role);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->actingAs($user)->get(route('account'))->assertOk()->getContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertStringContainsString('data-profile-band', $html);
        $this->assertStringContainsString('!size-24', $html);
        $this->assertStringContainsString('-mt-12', $html, 'The avatar overlaps the band.');
        $this->assertStringContainsString('justify-between', $html);
        $this->assertStringNotContainsString('gradient', substr($html, strpos($html, 'data-profile-card'), 3000));
        $this->assertLessThanOrEqual(14, $queries, "{$queries} queries.");
    }

    public function test_the_number_of_queries_does_not_grow_with_what_a_user_owns(): void
    {
        $lecturer = $this->userFor('lecturer');

        $count = function () use ($lecturer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($lecturer)->get(route('account'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $small = $count();

        foreach (['AAA1111', 'BBB2222', 'CCC3333'] as $code) {
            $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => $code, 'name' => $code, 'group_size' => 4]);

            foreach (['X', 'Y'] as $name) {
                $group = $this->groupFor($subject, "{$code}{$name}");
                GroupMember::create(['group_id' => $group->id, 'user_id' => User::factory()->student()->create()->id, 'joined_at' => now()]);
            }
        }

        $this->assertSame($small, $count());
    }
}
