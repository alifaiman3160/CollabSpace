<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Group $group;

    protected User $ana;

    protected User $ben;

    protected User $gone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['name' => 'Dr Zainab', 'must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $this->group = Group::create(['subject_id' => $this->subject->id, 'name' => 'Falcon', 'group_size' => 4]);

        $this->ana = $this->student('Ana');
        $this->ben = $this->student('Ben');
        $this->gone = $this->student('Gone', active: false);
    }

    protected function student(string $name, bool $active = true, ?Group $group = null): User
    {
        $user = User::factory()->student()->create(['name' => $name, 'is_active' => $active, 'must_change_password' => false]);

        GroupMember::create(['group_id' => ($group ?? $this->group)->id, 'user_id' => $user->id, 'joined_at' => now()]);

        return $user;
    }

    protected function post_(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->lecturer)->post(route('lecturer.announcements.store', $this->subject), $data + ['title' => 'Report moved', 'body' => "Hand in on Friday.\nBring a copy."]);
    }

    protected function make(string $title = 'Hello', ?Subject $subject = null, ?\DateTimeInterface $at = null): Announcement
    {
        $a = Announcement::create(['subject_id' => ($subject ?? $this->subject)->id, 'author_id' => $this->lecturer->id, 'title' => $title, 'body' => 'Body of '.$title]);

        if ($at) {
            $a->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        return $a;
    }

    /*
    | Lecturer access
    */

    public function test_the_owner_opens_the_page_and_other_people_do_not(): void
    {
        $url = route('lecturer.announcements', $this->subject);
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->actingAs($this->lecturer)->get($url)->assertOk()->assertSee('New announcement');
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($this->ana)->get($url)->assertForbidden();
        $this->actingAs($admin)->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_another_lecturer_cannot_post_edit_or_delete(): void
    {
        $a = $this->make();
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $payload = ['title' => 'x', 'body' => 'y'];

        $this->actingAs($other)->post(route('lecturer.announcements.store', $this->subject), $payload)->assertNotFound();
        $this->actingAs($other)->put(route('lecturer.announcements.update', [$this->subject, $a]), $payload)->assertNotFound();
        $this->actingAs($other)->delete(route('lecturer.announcements.destroy', [$this->subject, $a]))->assertNotFound();

        $this->assertSame(1, Announcement::count());
        $this->assertSame('Hello', $a->fresh()->title);
    }

    public function test_an_announcement_is_only_reached_through_its_own_subject(): void
    {
        $mine = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ2013', 'name' => 'Other', 'group_size' => 4]);
        $a = $this->make('On the first subject');

        $this->actingAs($this->lecturer)->get(route('lecturer.announcements.edit', [$mine, $a]))->assertNotFound();
        $this->actingAs($this->lecturer)->delete(route('lecturer.announcements.destroy', [$mine, $a]))->assertNotFound();
    }

    public function test_the_subjects_list_has_the_announcements_action(): void
    {
        $this->actingAs($this->lecturer)->get(route('lecturer.subjects'))
            ->assertOk()
            ->assertSee(route('lecturer.announcements', $this->subject), false)
            ->assertSee('Announcements');
    }

    /*
    | Posting
    */

    public function test_posting_validates_the_title_and_the_message(): void
    {
        $this->post_(['title' => ''])->assertSessionHasErrors('title');
        $this->post_(['body' => ''])->assertSessionHasErrors('body');
        $this->post_(['title' => str_repeat('a', 151)])->assertSessionHasErrors('title');
        $this->post_(['body' => str_repeat('a', 2001)])->assertSessionHasErrors('body');
        $this->assertSame(0, Announcement::count());

        $this->post_(['title' => str_repeat('a', 150), 'body' => str_repeat('b', 2000)])->assertSessionHasNoErrors();
        $this->assertSame(1, Announcement::count());
    }

    public function test_posting_tells_each_active_student_once_and_nobody_else(): void
    {
        $this->post_()->assertRedirect(route('lecturer.announcements', $this->subject))->assertSessionHas('status');

        $a = Announcement::first();
        $rows = AppNotification::about('announcement', $a->id)->get();

        $this->assertEqualsCanonicalizing([$this->ana->id, $this->ben->id], $rows->pluck('user_id')->all(), 'One each; the deactivated student and the lecturer get nothing.');
        $this->assertSame('SECJ3104: Report moved', $rows->first()->message);
        $this->assertStringEndsWith('#announcement-'.$a->id, $rows->first()->link);
        $this->assertSame($this->lecturer->id, $a->author_id);
    }

    public function test_editing_changes_the_text_and_notifies_nobody_again(): void
    {
        $this->post_();
        $a = Announcement::first();
        $before = AppNotification::count();

        $this->actingAs($this->lecturer)->put(route('lecturer.announcements.update', [$this->subject, $a]), ['title' => 'Report moved again', 'body' => 'New text'])
            ->assertRedirect(route('lecturer.announcements', $this->subject));

        $this->assertSame('Report moved again', $a->fresh()->title);
        $this->assertSame($before, AppNotification::count());
    }

    public function test_deleting_removes_the_announcement_and_its_notifications_only(): void
    {
        $this->post_();
        $this->post_(['title' => 'Second']);
        [$first, $second] = Announcement::orderBy('id')->get()->all();
        AppNotification::create(['user_id' => $this->ana->id, 'type' => 'deadline', 'message' => 'Due soon', 'link' => '/x', 'reference_id' => $first->id]);

        $this->actingAs($this->lecturer)->delete(route('lecturer.announcements.destroy', [$this->subject, $first]))->assertRedirect();

        $this->assertNull(Announcement::find($first->id));
        $this->assertSame(0, AppNotification::about('announcement', $first->id)->count());
        $this->assertSame(2, AppNotification::about('announcement', $second->id)->count(), 'The other announcement keeps its bells.');
        $this->assertSame(1, AppNotification::where('type', 'deadline')->count(), 'A different type with the same id is left alone.');
    }

    public function test_deleting_a_subject_removes_its_announcements_and_their_notifications(): void
    {
        $this->post_();

        // The administrator deletes subjects, and only empty ones: nobody enrolled, no groups.
        \App\Models\Group::query()->get()->each->delete();
        \App\Models\Enrolment::query()->delete();

        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        $this->actingAs($admin)->delete(route('admin.subjects.destroy', $this->subject))->assertRedirect(route('admin.subjects'));

        $this->assertSame(0, Announcement::count());
        $this->assertSame(0, AppNotification::where('type', 'announcement')->count());
    }

    public function test_the_form_counts_characters_and_guards_a_double_click(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.announcements', $this->subject))->getContent();

        $this->assertStringContainsString('data-counter', $html);
        $this->assertStringContainsString('/ 2000', $html);
        $this->assertStringContainsString('x-on:submit="if (busy)', $html);
        $this->assertMatchesRegularExpression('/x-bind:disabled="busy"[^>]*data-post/', $html);
    }

    /*
    | Students
    */

    public function test_a_student_sees_only_the_announcements_of_their_own_subjects(): void
    {
        $otherLecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $elsewhere = Subject::create(['lecturer_id' => $otherLecturer->id, 'code' => 'SECJ9999', 'name' => 'Elsewhere', 'group_size' => 4]);
        $this->make('For my subject');
        $this->make('For somewhere else', $elsewhere);

        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('For my subject', $html);
        $this->assertStringContainsString('SECJ3104', $html);
        $this->assertStringContainsString('Dr Zainab', $html);
        $this->assertStringNotContainsString('For somewhere else', $html);
    }

    public function test_the_section_is_hidden_when_there_is_nothing_to_show(): void
    {
        $this->actingAs($this->ana)->get(route('student.dashboard'))->assertOk()->assertDontSee('data-announcement-strip', false);
    }

    protected function secondSubject(): array
    {
        $subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ2013', 'name' => 'Data Structures', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Owl', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->ana->id, 'joined_at' => now()]);

        return [$subject, $group];
    }

    public function test_announcements_live_inside_their_own_subject_card_and_there_is_no_separate_card(): void
    {
        [$other] = $this->secondSubject();
        $this->make('First');
        $this->make('Second');

        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Announcements"', $html, 'No separate card.');

        $cards = explode('data-subject-card', $html);
        array_shift($cards);
        $cards = array_map(fn ($c) => substr($c, 0, strpos($c, 'aria-label="My tasks"') ?: strlen($c)), $cards);
        $withStrip = array_values(array_filter($cards, fn ($c) => str_contains($c, 'data-announcement-strip')));

        $this->assertCount(1, $withStrip, 'Only the subject that has announcements gets a clickable strip.');
        $this->assertStringContainsString('SECJ3104', $withStrip[0]);
        $this->assertStringContainsString('2 announcements', $withStrip[0]);
        $this->assertStringContainsString('Second', $withStrip[0], 'The collapsed strip names the latest one.');
    }

    public function test_the_strip_is_a_button_beside_the_workspace_link_never_inside_it(): void
    {
        $this->make();

        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();
        preg_match('#<a href="[^"]*workspace/\d+"[^>]*>.*?</a>#s', $html, $link);
        $this->assertStringContainsString('SECJ3104', $link[0], 'The main area is the link.');
        $this->assertStringNotContainsString('<button', $link[0]);
        $this->assertStringNotContainsString('data-announcement-strip', $link[0]);

        preg_match('/<button type="button"[^>]*data-announcement-strip[^>]*>/s', $html, $button);
        $this->assertStringContainsString('x-bind:aria-expanded="open"', $button[0]);
        $this->assertStringContainsString('aria-controls="announcements-', $button[0]);
        $this->assertStringContainsString('interactive', $button[0]);
    }

    public function test_cards_are_top_aligned_so_opening_one_does_not_stretch_the_next(): void
    {
        $this->make();

        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();

        $this->assertMatchesRegularExpression('/<section class="grid grid-cols-1 items-stretch gap-\[18px\] md:grid-cols-2[^"]*" aria-label="My groups"/', $html);
        $this->assertStringNotContainsString('items-start gap-[18px] md:grid-cols-2', $html, 'Cards in a row stretch equally; the strip sits at the bottom.');
    }

    public function test_the_card_opens_by_itself_for_a_notification_link(): void
    {
        $js = file_get_contents(resource_path('js/announcement-strip.js'));

        $this->assertStringContainsString('#announcement-(\d+)', $js);
        $this->assertStringContainsString('this.open = true', $js);
        $this->assertStringContainsString('if (position >= 3) {', $js, 'An earlier one is revealed too.');
        $this->assertStringContainsString('this.earlier = true', $js);
        $this->assertStringContainsString("Alpine.data('announcementStrip'", file_get_contents(resource_path('js/app.js')));

        $this->make();
        $a = Announcement::first();
        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();
        $this->assertStringContainsString('x-data="announcementStrip(', $html);
        $this->assertStringContainsString('id="announcement-'.$a->id.'"', $html);
        $this->assertStringContainsString('x-on:hashchange.window="reveal()"', $html);
    }
    public function test_line_breaks_are_kept_and_markup_is_printed_as_text(): void
    {
        $a = $this->make();
        $a->update(['title' => '<b>Bold</b> & co', 'body' => "Line one\nLine two <script>alert(1)</script>"]);

        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();

        $this->assertStringContainsString('whitespace-pre-line', $html);
        $this->assertStringContainsString("Line one\nLine two &lt;script&gt;alert(1)&lt;/script&gt;", str_replace("\r\n", "\n", $html));
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; &amp; co', $html);
    }

    public function test_the_latest_three_show_and_the_rest_wait_behind_show_earlier(): void
    {
        foreach (range(1, 5) as $i) {
            $this->make("News {$i}", at: now()->subDays(6 - $i));   // News 5 is the newest
        }

        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();

        $this->assertSame(5, substr_count($html, 'data-announcement>'));
        $this->assertStringContainsString('data-show-earlier', $html);
        $this->assertStringContainsString('Show earlier (2)', $html);
        // The first three in order are the newest; the last two are the hidden ones.
        $this->assertLessThan(strpos($html, 'News 4'), strpos($html, 'News 5'));
        $this->assertLessThan(strpos($html, 'News 3'), strpos($html, 'News 4'));
        $this->assertSame(2, preg_match_all('/x-show="earlier" x-cloak/', $html));

        $this->make('Only three');
        Announcement::query()->where('title', '!=', 'Only three')->delete();
        $this->assertStringNotContainsString('data-show-earlier', $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent());
    }

    public function test_a_long_message_gets_a_read_more_toggle_and_a_short_one_does_not(): void
    {
        $long = $this->make('Long');
        $long->update(['body' => str_repeat('A long sentence that keeps going. ', 12)]);
        $this->make('Short');

        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();

        $this->assertSame(1, substr_count($html, 'data-read-more'));
        $this->assertStringContainsString('line-clamp-3', $html);
    }

    public function test_the_notification_has_an_icon_and_points_at_the_dashboard_section(): void
    {
        $this->post_();
        $a = Announcement::first();

        $this->actingAs($this->ana)->get(route('student.notifications'))->assertOk()->assertSee('SECJ3104: Report moved');
        $this->assertSame(route('student.dashboard', absolute: false).'#announcement-'.$a->id, AppNotification::about('announcement', $a->id)->where('user_id', $this->ana->id)->value('link'));
        $this->assertStringContainsString("'announcement' => 'inbox'", file_get_contents(resource_path('views/livewire/notification-list.blade.php')));

        $this->actingAs($this->ana)->get(route('student.dashboard'))->assertSee('id="announcement-'.$a->id.'"', false);
    }
}
