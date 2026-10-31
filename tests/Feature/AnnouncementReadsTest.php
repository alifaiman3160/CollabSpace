<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use App\Support\SubjectPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Unread dots, "Opened by X of Y", and a steady colour for each subject. */
class AnnouncementReadsTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Group $group;

    protected User $ana;

    protected User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['name' => 'Dr Zainab', 'must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $this->group = Group::create(['subject_id' => $this->subject->id, 'name' => 'Falcon', 'group_size' => 4]);

        $this->ana = $this->member('Ana');
        $this->ben = $this->member('Ben');
    }

    protected function member(string $name, bool $active = true, ?Group $group = null): User
    {
        $user = User::factory()->student()->create(['name' => $name, 'is_active' => $active, 'must_change_password' => false]);
        GroupMember::create(['group_id' => ($group ?? $this->group)->id, 'user_id' => $user->id, 'joined_at' => now()]);

        return $user;
    }

    protected function news(string $title = 'News', ?Subject $subject = null): Announcement
    {
        return Announcement::create(['subject_id' => ($subject ?? $this->subject)->id, 'author_id' => $this->lecturer->id, 'title' => $title, 'body' => 'Body']);
    }

    protected function read(User $who, array $ids)
    {
        return $this->actingAs($who)->postJson(route('student.announcements.read'), ['ids' => $ids]);
    }

    /*
    | Recording a read
    */

    public function test_a_read_is_recorded_once_and_never_duplicated(): void
    {
        $a = $this->news();

        $this->read($this->ana, [$a->id])->assertOk()->assertJson(['read' => 1]);
        $this->read($this->ana, [$a->id])->assertOk();
        $this->read($this->ana, [$a->id, $a->id])->assertOk();

        $this->assertSame(1, AnnouncementRead::where('announcement_id', $a->id)->where('user_id', $this->ana->id)->count());
        $this->assertSame(0, AnnouncementRead::where('user_id', $this->ben->id)->count(), 'Only the one who opened it.');
    }

    public function test_a_student_cannot_mark_another_subjects_announcement(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $elsewhere = Subject::create(['lecturer_id' => $other->id, 'code' => 'SECJ9999', 'name' => 'Elsewhere', 'group_size' => 4]);
        $mine = $this->news('Mine');
        $theirs = $this->news('Theirs', $elsewhere);

        $this->read($this->ana, [$theirs->id])->assertNotFound();
        $this->read($this->ana, [$mine->id, $theirs->id])->assertNotFound();   // nothing partial

        $this->assertSame(0, AnnouncementRead::count());
    }

    public function test_only_students_may_call_it_and_a_page_load_records_nothing(): void
    {
        $a = $this->news();

        $this->actingAs($this->lecturer)->postJson(route('student.announcements.read'), ['ids' => [$a->id]])->assertForbidden();
        $this->actingAs($this->ana)->postJson(route('student.announcements.read'), ['ids' => []])->assertStatus(422);

        $this->actingAs($this->ana)->get(route('student.dashboard'))->assertOk();
        $this->assertSame(0, AnnouncementRead::count(), 'Only opening the strip records a read.');
    }

    /*
    | The strip
    */

    public function test_unread_announcements_show_a_dot_a_label_and_a_bold_title_and_read_ones_do_not(): void
    {
        $first = $this->news('First');
        $second = $this->news('Second');

        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();

        $this->assertStringContainsString('data-unread-dot', $html);
        $this->assertStringContainsString('2 new announcements', $html);
        $this->assertStringContainsString('font-extrabold', $html);

        AnnouncementRead::insert([
            ['announcement_id' => $first->id, 'user_id' => $this->ana->id, 'read_at' => now()],
        ]);
        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();
        $this->assertStringContainsString('New announcement', $html);
        $this->assertStringNotContainsString('2 new announcements', $html);

        AnnouncementRead::insert([
            ['announcement_id' => $second->id, 'user_id' => $this->ana->id, 'read_at' => now()],
        ]);
        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();
        $this->assertStringNotContainsString('data-unread-dot', $html, 'The dot is gone once everything is read.');
        $this->assertStringNotContainsString('New announcement', $html);

        // Reading is per student: Ben still has both unread.
        $this->assertStringContainsString('2 new announcements', $this->actingAs($this->ben)->get(route('student.dashboard'))->getContent());
    }

    public function test_the_strip_is_a_pill_button_with_a_bell_a_chevron_and_the_dot_clears_when_it_opens(): void
    {
        $this->news('Hello');
        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();

        preg_match('/<button type="button"[^>]*data-announcement-strip[^>]*>/s', $html, $button);
        $this->assertStringContainsString('rounded-pill', $button[0]);
        $this->assertStringContainsString('bg-white/70', $button[0]);
        $this->assertStringContainsString('x-on:click="toggle()"', $button[0]);
        $this->assertStringContainsString('x-show="unread > 0"', $html);

        $js = file_get_contents(resource_path('js/announcement-strip.js'));
        $this->assertStringContainsString('this.unread = 0', $js);
        $this->assertStringContainsString('fetch(url', $js);
        $this->assertStringContainsString('init() {', $js);
        $this->assertStringNotContainsString('DOMContentLoaded', $js, 'Nothing is sent on page load.');
        $this->assertStringContainsString('student\/announcements\/read', $html, 'The address the page posts to, as it is written into the page.');
    }

    /*
    | The lecturer's count
    */

    public function test_opened_by_counts_active_students_who_have_read_it(): void
    {
        $gone = $this->member('Gone', active: false);
        $outsider = User::factory()->student()->create(['must_change_password' => false]);
        $a = $this->news('Counted');
        $b = $this->news('Unread');

        foreach ([$this->ana, $gone, $outsider] as $who) {
            AnnouncementRead::create(['announcement_id' => $a->id, 'user_id' => $who->id, 'read_at' => now()]);
        }

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.announcements', $this->subject))->assertOk()->getContent();

        // Y = Ana and Ben (Gone is deactivated). X = Ana only: not the deactivated one, not someone outside the subject.
        $this->assertStringContainsString('Opened by 1 of 2', $html);
        $this->assertStringContainsString('Opened by 0 of 2', $html);
        $this->assertSame(2, substr_count($html, 'data-opened-by'));
    }

    public function test_opened_by_uses_one_grouped_query_for_the_whole_page(): void
    {
        foreach (range(1, 6) as $i) {
            $a = $this->news("N{$i}");
            AnnouncementRead::create(['announcement_id' => $a->id, 'user_id' => $this->ana->id, 'read_at' => now()]);
        }

        \DB::enableQueryLog();
        $this->actingAs($this->lecturer)->get(route('lecturer.announcements', $this->subject))->assertOk();
        $reads = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'announcement_reads'))->count();
        \DB::disableQueryLog();

        $this->assertSame(1, $reads);
    }

    /*
    | Colours
    */

    public function test_a_subject_always_gets_the_same_colour_by_its_id_modulo_six(): void
    {
        $this->assertCount(6, SubjectPalette::COLOURS);

        foreach (range(1, 14) as $id) {
            $this->assertSame(SubjectPalette::COLOURS[$id % 6], SubjectPalette::for($id));
            $this->assertSame(SubjectPalette::for($id), SubjectPalette::for($id + 6));
        }

        $this->assertSame(['name' => 'peach', 'bg' => '#fdf0e6', 'accent' => '#f0a56b', 'border' => '#f7dcc4'], SubjectPalette::for(2));
    }

    public function test_the_card_wears_its_subjects_pastel_for_every_student(): void
    {
        $colour = SubjectPalette::for($this->subject->id);

        foreach ([$this->ana, $this->ben] as $student) {
            $html = $this->actingAs($student)->get(route('student.dashboard'))->getContent();

            $this->assertStringContainsString("data-palette=\"{$colour['name']}\"", $html);
            $this->assertStringContainsString("background-color: {$colour['bg']}; border-color: {$colour['border']};", $html);
            $this->assertStringContainsString("box-shadow: 0 0 0 2px {$colour['accent']};", $html, 'The first card has a 2px accent ring, not a solid fill.');
            $this->assertStringNotContainsString('bg-periwinkle border-transparent', $html);
        }
    }

    /*
    | Opening and closing
    */

    public function test_the_panel_is_a_closed_inert_grid_that_grows_and_the_cards_stay_top_aligned(): void
    {
        $this->news('Hello');

        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();

        preg_match('/<div [^>]*id="announcements-\d+"[^>]*data-announcement-panel[^>]*>/s', $html, $panel);
        $this->assertStringContainsString('class="cs-collapse"', $panel[0]);
        $this->assertStringContainsString(' inert ', ' '.$panel[0].' ', 'Closed from the start, and unreachable.');
        $this->assertStringContainsString('aria-hidden="true"', $panel[0]);
        $this->assertStringContainsString("'is-open': open", $panel[0]);
        $this->assertStringContainsString('x-bind:inert="! (open)"', $panel[0]);
        $this->assertStringNotContainsString('x-show="open"', $panel[0], 'Height is animated, not switched.');
        $this->assertStringContainsString('cs-collapse__inner', $html);
        $this->assertStringContainsString('items-start', $html, 'A card opening never stretches its neighbour.');
        $this->assertStringContainsString('x-bind:aria-expanded="open"', $html);
    }

    public function test_the_timing_is_280_ms_in_200_ms_out_and_reduced_motion_makes_it_instant(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.cs-collapse\.is-open\s*\{[^}]*grid-template-rows:\s*1fr;[^}]*grid-template-rows 280ms ease-out/s', $css);
        $this->assertMatchesRegularExpression('/\.cs-collapse\s*\{[^}]*grid-template-rows:\s*0fr;[^}]*visibility:\s*hidden;[^}]*grid-template-rows 200ms ease-out, visibility 0s linear 200ms/s', $css);
        $this->assertMatchesRegularExpression('/\.cs-collapse__inner\s*\{[^}]*min-height:\s*0;[^}]*overflow:\s*hidden/s', $css);
        $this->assertStringContainsString('.cs-chevron {', $css);

        // The shared reduced-motion rule switches every transition and animation off.
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{[^}]*transition-duration:\s*0s !important/s', $css);
    }

    public function test_nothing_animates_on_arrival_and_the_earlier_items_fade_in(): void
    {
        foreach (range(1, 4) as $i) {
            $this->news("N{$i}");
        }

        $js = file_get_contents(resource_path('js/announcement-strip.js'));
        $html = $this->actingAs($this->ana)->get(route('student.dashboard'))->getContent();

        $this->assertStringContainsString('this.instant = true', $js);
        $this->assertStringContainsString('(this.instant = false)', $js);
        $this->assertStringContainsString('x-transition:enter="transition ease-out duration-150"', $html);
    }
}
