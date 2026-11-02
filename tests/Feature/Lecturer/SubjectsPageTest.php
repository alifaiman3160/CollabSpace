<?php

namespace Tests\Feature\Lecturer;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * My subjects: the cards (no New subject tile: the administrator gives subjects out), and two panels underneath (deadlines, announcements).
 */
class SubjectsPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
    }

    protected function subject(string $code, $deadline = null, ?User $owner = null): Subject
    {
        return Subject::create([
            'lecturer_id' => ($owner ?? $this->lecturer)->id, 'code' => $code, 'name' => "Name of {$code}", 'group_size' => 4,
            'project_deadline' => $deadline,
        ]);
    }

    protected function page(): string
    {
        return $this->actingAs($this->lecturer)->get(route('lecturer.subjects'))->assertOk()->getContent();
    }

    public function test_it_renders_for_none_one_three_and_six_subjects(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('No subjects assigned yet. The administrator assigns subjects to lecturers.', $html);
        $this->assertSame(0, substr_count($html, 'data-new-subject-tile'));
        $this->assertSame(0, substr_count($html, 'data-subject-card'));
        $this->assertStringNotContainsString('data-subject-panels', $html, 'No panels with no subjects.');

        foreach ([1, 3, 6] as $n) {
            Subject::query()->where('lecturer_id', $this->lecturer->id)->delete();

            foreach (range(1, $n) as $i) {
                $this->subject('SUB'.$i.'000');
            }

            $html = $this->page();

            $this->assertSame($n, substr_count($html, 'data-subject-card'), "{$n} subjects");
            $this->assertSame(0, substr_count($html, 'data-new-subject-tile'));
            $this->assertSame(1, substr_count($html, 'data-subject-panels'));
        }
    }

    public function test_there_is_no_new_subject_button_or_tile_and_the_grid_keeps_its_width(): void
    {
        $this->subject('AAA1111');
        $this->subject('BBB2222');

        $html = $this->page();

        $this->assertStringNotContainsString('New subject', $html);
        $this->assertStringNotContainsString('Add a course you teach', $html);
        $this->assertStringContainsString('grid-cols-[repeat(auto-fill,minmax(24rem,1fr))]', $html);
    }

    public function test_the_page_has_the_same_permission_as_before(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($student)->get(route('lecturer.subjects'))->assertForbidden();
        $this->actingAs($student)->post(route('lecturer.subjects.store'), [])->assertForbidden();
    }
    public function test_upcoming_deadlines_are_soonest_first_only_for_subjects_with_one_and_labelled(): void
    {
        $this->subject('NONE0000');
        $this->subject('LATE1111', now()->addDays(28)->toDateString());
        $this->subject('SOON2222', now()->addDays(3)->toDateString());
        $this->subject('TODAY333', now()->toDateString());
        $this->subject('PAST4444', now()->subDays(3)->toDateString());

        $html = $this->page();
        preg_match('/<section[^>]*aria-label="Upcoming deadlines"[^>]*>(.*?)<\/section>/s', $html, $panel);

        $this->assertSame(4, substr_count($panel[1], 'data-deadline-row'), 'Only subjects with a deadline.');
        $this->assertStringNotContainsString('NONE0000', $panel[1]);

        $order = ['PAST4444', 'TODAY333', 'SOON2222', 'LATE1111'];
        $positions = array_map(fn ($code) => strpos($panel[1], $code), $order);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        $this->assertStringContainsString('Overdue by 3 days', $panel[1]);
        $this->assertStringContainsString('Today', $panel[1]);
        $this->assertStringContainsString('in 3 days', $panel[1]);
        $this->assertStringContainsString('in 28 days', $panel[1]);
    }

    public function test_recent_announcements_are_the_newest_five_of_the_lecturers_own_subjects(): void
    {
        $mine = $this->subject('MINE1111');
        $other = User::factory()->lecturer()->create();
        $theirs = $this->subject('THEIRS22', null, $other);

        foreach (range(1, 7) as $i) {
            $announcement = Announcement::create(['subject_id' => $mine->id, 'author_id' => $this->lecturer->id, 'title' => "Notice {$i}", 'body' => 'b']);
            $announcement->forceFill(['created_at' => now()->subHours(8 - $i)])->save();
        }
        Announcement::create(['subject_id' => $theirs->id, 'author_id' => $other->id, 'title' => 'Not yours', 'body' => 'b']);

        $html = $this->page();
        preg_match('/<section[^>]*aria-label="Recent announcements"[^>]*>(.*?)<\/section>/s', $html, $panel);

        $this->assertSame(5, substr_count($panel[1], 'data-announcement-row'));
        $this->assertStringContainsString('Notice 7', $panel[1]);
        $this->assertStringContainsString('Notice 3', $panel[1]);
        $this->assertStringNotContainsString('Notice 2', $panel[1], 'Only the newest five.');
        $this->assertStringNotContainsString('Not yours', $html);
        $this->assertLessThan(strpos($panel[1], 'Notice 6'), strpos($panel[1], 'Notice 7'), 'Newest first.');
        $this->assertStringContainsString('hours ago', $panel[1]);
    }

    public function test_an_announcement_shows_how_many_students_opened_it(): void
    {
        $subject = $this->subject('MINE1111');
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G', 'group_size' => 4]);
        $students = User::factory()->student()->count(3)->create();
        $students->each(fn ($s) => GroupMember::create(['group_id' => $group->id, 'user_id' => $s->id, 'joined_at' => now()]));

        $announcement = Announcement::create(['subject_id' => $subject->id, 'author_id' => $this->lecturer->id, 'title' => 'Read me', 'body' => 'b']);
        AnnouncementRead::create(['announcement_id' => $announcement->id, 'user_id' => $students[0]->id, 'read_at' => now()]);

        $this->assertStringContainsString('Opened by 1 of 3', $this->page());
    }

    public function test_the_empty_states_are_friendly_and_each_offers_a_next_step(): void
    {
        $subject = $this->subject('AAA1111');

        $html = $this->page();

        $this->assertStringContainsString('No project deadlines set yet.', $html);
        $this->assertStringContainsString('data-deadlines-empty', $html);
        $this->assertStringContainsString(route('lecturer.subjects.edit', $subject), $html);
        $this->assertStringContainsString('No announcements yet.', $html);
        $this->assertStringContainsString('data-post-announcement', $html);
        $this->assertStringContainsString(route('lecturer.announcements', $subject), $html, 'One subject: straight to its announcements.');
        $this->assertStringNotContainsString('border-dashed', $html);
    }

    public function test_with_several_subjects_post_announcement_offers_a_short_list(): void
    {
        $a = $this->subject('AAA1111');
        $b = $this->subject('BBB2222');

        $html = $this->page();

        $this->assertStringContainsString('For which subject?', $html);
        $this->assertStringContainsString(route('lecturer.announcements', $a), $html);
        $this->assertStringContainsString(route('lecturer.announcements', $b), $html);
    }

    public function test_the_panels_are_dashboard_panels_and_the_entrance_is_staggered(): void
    {
        $this->subject('AAA1111');

        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-deadlines-panel="'));
        $this->assertSame(1, substr_count($html, 'data-announcements-panel="'));
        $this->assertSame(2, substr_count($html, 'data-panel-body'), 'A body that scrolls inside, in each.');
        $this->assertStringContainsString('lg:h-[24rem]', $html, 'Both the same height.');
        $this->assertStringContainsString('cs-card-in', $html);
    }

    public function test_the_query_count_is_fixed(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->lecturer)->get(route('lecturer.subjects'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $first = $this->subject('AAA1111', now()->addDays(5)->toDateString());
        Announcement::create(['subject_id' => $first->id, 'author_id' => $this->lecturer->id, 'title' => 'T', 'body' => 'b']);
        $small = $count();

        foreach (range(1, 6) as $i) {
            $subject = $this->subject('SUB'.$i.'000', now()->addDays($i)->toDateString());
            Announcement::create(['subject_id' => $subject->id, 'author_id' => $this->lecturer->id, 'title' => "T{$i}", 'body' => 'b']);
        }

        $this->assertSame($small, $count());
    }
}
