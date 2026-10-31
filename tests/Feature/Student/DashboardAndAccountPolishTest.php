<?php

namespace Tests\Feature\Student;

use App\Models\Announcement;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Uniform boxes on the student dashboard and the account page: tidy, and never empty. */
class DashboardAndAccountPolishTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $ana;

    protected Group $group;

    protected Group $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $one = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);
        $two = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ2013', 'name' => 'Data Structures', 'group_size' => 4]);
        $this->group = Group::create(['subject_id' => $one->id, 'name' => 'Falcon', 'group_size' => 4]);
        $this->other = Group::create(['subject_id' => $two->id, 'name' => 'Owl', 'group_size' => 4]);

        $this->ana = User::factory()->student()->create(['name' => 'Ana', 'matric_no' => 'A21EC1234', 'must_change_password' => false]);

        foreach ([$this->group, $this->other] as $g) {
            GroupMember::create(['group_id' => $g->id, 'user_id' => $this->ana->id, 'joined_at' => now()]);
        }
    }

    protected function dashboard(): string
    {
        return $this->actingAs($this->ana)->get(route('student.dashboard'))->assertOk()->getContent();
    }

    protected function task(string $title, string $status = 'todo'): Task
    {
        return Task::create(['group_id' => $this->group->id, 'created_by' => $this->lecturer->id, 'assigned_to' => $this->ana->id, 'title' => $title, 'status' => $status]);
    }

    /*
    | Dashboard
    */

    public function test_every_subject_card_has_the_same_bottom_strip_area_and_a_quiet_one_when_there_is_nothing(): void
    {
        Announcement::create(['subject_id' => $this->group->subject_id, 'author_id' => $this->lecturer->id, 'title' => 'Hello', 'body' => 'Text']);

        $html = $this->dashboard();

        $this->assertSame(2, substr_count($html, 'data-subject-card'));
        $this->assertSame(1, substr_count($html, 'data-announcement-strip'));
        $this->assertSame(1, substr_count($html, 'data-announcements-empty'));
        $this->assertStringContainsString('No announcements yet', $html);

        preg_match('/data-announcements-empty>(.*?)<\/div>\s*<\/div>/s', $html, $quiet);
        $this->assertStringNotContainsString('<button', $quiet[1], 'The quiet strip is not clickable.');
        $this->assertSame(2, substr_count($html, 'min-h-[48px]'), 'The same height whether or not there is news.');
        $this->assertStringContainsString('items-start', $html);
    }

    public function test_the_two_bottom_panels_share_a_height_and_each_has_its_footer_link(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('lg:h-[26rem]', $html);
        $this->assertStringContainsString('lg:items-stretch', $html);
        $this->assertStringContainsString('Open board', $html);
        $this->assertStringContainsString('Open chat', $html);
        $this->assertSame(2, substr_count($html, 'min-h-[23.75rem]'));
        $this->assertStringContainsString('overflow-y-auto', substr($html, strpos($html, 'data-chat-list') - 120, 200), 'Chat scrolls inside its panel.');
    }

    public function test_pending_tasks_show_at_most_five_and_fill_the_rest_with_a_friendly_line(): void
    {
        $this->assertStringContainsString('You have no pending tasks.', $this->dashboard());

        foreach (range(1, 3) as $i) {
            $this->task("Task {$i}");
        }
        $html = $this->dashboard();
        $this->assertStringContainsString('Nothing else pending. Nice work.', $html);
        $this->assertStringNotContainsString('You have no pending tasks.', $html);

        foreach (range(4, 8) as $i) {
            $this->task("Task {$i}");
        }
        $html = $this->dashboard();
        $this->assertSame(5, substr_count($html, 'data-pending-row'));
        $this->assertStringNotContainsString('data-pending-empty', $html, 'Five fill the panel; no filler.');
    }

    public function test_recent_chat_has_a_friendly_empty_state_and_keeps_its_messages(): void
    {
        $this->assertStringContainsString('No messages in your groups yet.', $this->dashboard());

        Message::create(['group_id' => $this->group->id, 'user_id' => $this->ana->id, 'body' => 'Meeting at four']);
        $this->assertStringContainsString('Meeting at four', $this->dashboard());
    }

    /*
    | My account
    */

    public function test_the_account_title_comes_from_the_shared_page_header_like_every_other_page(): void
    {
        $html = $this->actingAs($this->ana)->get(route('account'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>My account</h1>'), 'One title, printed by the layout.');
        $this->assertStringNotContainsString('max-w-5xl', $html, 'No narrow centred column.');
        $this->assertStringNotContainsString('-mt-4', $html);
        $this->assertStringContainsString('text-h1 font-extrabold tracking-title', $html);
    }

    public function test_the_cards_stretch_to_one_height_with_their_buttons_pinned_to_the_bottom(): void
    {
        $html = $this->actingAs($this->ana)->get(route('account'))->getContent();

        $this->assertStringContainsString('min-[1280px]:grid-cols-3', $html);
        $this->assertStringContainsString('min-[900px]:grid-cols-2', $html);
        $this->assertStringContainsString('items-stretch', $html);
        $this->assertSame(2, substr_count($html, '<div class="mt-auto">'));
        $this->assertStringContainsString('Save details', $html);
        $this->assertStringContainsString('Change password', $html);
        $this->assertSame(2, substr_count($html, 'rounded-[10px] bg-periwinkle-soft text-periwinkle-link'), 'A soft icon beside each form heading.');
    }
    public function test_a_student_sees_a_read_only_student_id_and_others_see_their_role(): void
    {
        $html = $this->actingAs($this->ana)->get(route('account'))->getContent();

        preg_match('/<input id="readonly-id"[^>]*>/s', $html, $field);
        $this->assertStringContainsString('readonly', $field[0]);
        $this->assertStringContainsString('value="A21EC1234"', $field[0]);
        $this->assertStringNotContainsString('name=', $field[0], 'It is never submitted.');
        $this->assertStringContainsString('Student ID', $html);

        $html = $this->actingAs($this->lecturer)->get(route('account'))->getContent();
        $this->assertStringContainsString('for="readonly-id"', $html);
        $this->assertMatchesRegularExpression('/for="readonly-id"[^>]*>\s*Role\s*</', $html);
        $this->assertStringContainsString('value="Lecturer"', $html);
    }

    public function test_the_password_rule_hint_and_the_closing_note_are_in_place(): void
    {
        $html = $this->actingAs($this->ana)->get(route('account'))->getContent();

        $this->assertStringContainsString('Use at least 8 characters.', $html);
        $this->assertGreaterThan(strpos($html, 'data-account-cards'), strpos($html, 'data-closing-note'), 'Below both cards.');
        $this->assertStringContainsString('Need your account closed or suspended?', $html);

        // The rule it names is the rule the server enforces.
        $this->actingAs($this->ana)->put(route('account.password'), ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
    }
    public function test_pending_rows_line_up_on_one_grid_with_a_header_and_stack_on_a_narrow_screen(): void
    {
        $this->task('Aligned');

        $html = $this->dashboard();

        $this->assertStringContainsString('md:grid-cols-[minmax(0,1fr)_5.5rem_13rem_6.5rem]', $html);
        $this->assertSame(2, substr_count($html, 'md:grid-cols-[minmax(0,1fr)_5.5rem_13rem_6.5rem]'), 'The header and the rows share the same columns.');
        $this->assertMatchesRegularExpression('/data-pending-head>\s*<span>Task<\/span><span>Subject<\/span><span>Deadline<\/span><span>Status<\/span>/', $html);
        $this->assertStringContainsString('hidden', substr($html, strpos($html, 'data-pending-head') - 400, 400));
    }

    public function test_an_overdue_row_and_a_due_soon_row_get_a_bar_a_tint_and_a_badge_on_the_date_line(): void
    {
        $late = $this->task('Late one');
        $late->update(['deadline' => now()->subDays(3)->toDateString()]);
        $soon = $this->task('Soon one');
        $soon->update(['deadline' => now()->addDay()->toDateString()]);
        $far = $this->task('Far one');
        $far->update(['deadline' => now()->addDays(20)->toDateString()]);

        $html = $this->dashboard();


        $this->assertStringContainsString('data-state="overdue"', $html);
        $this->assertStringContainsString('data-state="soon"', $html);
        $this->assertSame(2, substr_count($html, 'data-row-bar'), 'Only the two urgent rows get a bar.');
        $this->assertStringContainsString('data-deadline-badge="overdue"', $html);
        $this->assertStringContainsString('data-deadline-badge="soon"', $html);
        $this->assertSame(2, substr_count($html, 'data-deadline-badge'), 'No badge for a far-off date.');
        $this->assertStringContainsString('Due in 1 day', $html);
        $this->assertStringContainsString('bg-danger-bg/40 hover:bg-danger-bg/70', $html);
        $this->assertStringContainsString('bg-warning-bg/50 hover:bg-warning-bg/80', $html);
        $this->assertStringContainsString('motion-colors', $html, 'A 150ms background change on hover.');

        // The date and its badge share one line in one cell.
        preg_match_all('/<span class="flex items-center gap-2 whitespace-nowrap" data-deadline>(.*?)<\/span>\s*<span class="justify-self-start">/s', $html, $cells);
        $this->assertCount(3, $cells[1]);
        $this->assertStringContainsString(now()->subDays(3)->format('j M Y'), $cells[1][0] ?? '');
    }

    public function test_the_nothing_pending_notice_is_its_own_mint_box_and_not_a_line(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('You have no pending tasks.', $html);
        preg_match('/<div class="[^"]*" data-pending-empty>/', $html, $box);
        foreach (['border-2', 'border-dashed', 'border-success-line', 'bg-success-bg', 'rounded-card'] as $class) {
            $this->assertStringContainsString($class, $box[0], $class);
        }
        $this->assertStringContainsString('pt-4', substr($html, strpos($html, 'data-pending-box') - 120, 160), 'About 16px above it.');
        $this->assertStringContainsString('px-5', substr($html, strpos($html, 'data-pending-box') - 120, 160), 'Space at the sides.');
        $this->assertStringContainsString('Open board', $html, 'The footer stays.');
    }
}