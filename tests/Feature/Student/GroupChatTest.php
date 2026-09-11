<?php

namespace Tests\Feature\Student;

use App\Livewire\GroupChat;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\User;
use App\Support\MemberColour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class GroupChatTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected User $mate;

    protected User $outsider;

    protected Group $group;

    protected Group $otherGroup;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create([
            'lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);

        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'My Group', 'group_size' => 4]);
        $this->otherGroup = Group::create(['subject_id' => $subject->id, 'name' => 'Other Group', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        $this->mate = User::factory()->student()->create(['must_change_password' => false]);
        $this->outsider = User::factory()->student()->create(['must_change_password' => false]);

        foreach ([$this->me, $this->mate] as $member) {
            GroupMember::create(['group_id' => $this->group->id, 'user_id' => $member->id, 'joined_at' => now()]);
        }

        GroupMember::create(['group_id' => $this->otherGroup->id, 'user_id' => $this->outsider->id, 'joined_at' => now()]);
    }

    protected function board()
    {
        return Livewire::actingAs($this->me)->test(GroupChat::class, ['group' => $this->group]);
    }

    /*
    | Sending
    */

    public function test_a_text_message_sends_and_logs_message_sent_only(): void
    {
        $this->board()->set('body', 'Morning all')->call('send')->assertHasNoErrors();

        $message = Message::sole();

        $this->assertSame('Morning all', $message->body);
        $this->assertNull($message->file_path);
        $this->assertSame($this->me->id, $message->user_id);

        $this->assertSame(1, ActivityLog::where('activity_type', 'message_sent')->count());
        $this->assertSame(0, ActivityLog::where('activity_type', 'file_uploaded')->count());
    }

    public function test_an_empty_message_is_refused(): void
    {
        $this->board()->set('body', '   ')->call('send')->assertHasErrors('body');

        $this->assertSame(0, Message::count());
    }

    public function test_a_file_only_message_logs_file_uploaded_only(): void
    {
        $this->board()
            ->set('attachment', UploadedFile::fake()->create('report.pdf', 120, 'application/pdf'))
            ->call('send')
            ->assertHasNoErrors();

        $message = Message::sole();

        $this->assertNull($message->body);
        $this->assertNotNull($message->file_path);

        $this->assertSame(0, ActivityLog::where('activity_type', 'message_sent')->count());
        $this->assertSame(1, ActivityLog::where('activity_type', 'file_uploaded')->count());
    }

    public function test_a_message_with_both_logs_both(): void
    {
        $this->board()
            ->set('body', 'Here is the draft')
            ->set('attachment', UploadedFile::fake()->create('draft.pdf', 90, 'application/pdf'))
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(1, ActivityLog::where('activity_type', 'message_sent')->count());
        $this->assertSame(1, ActivityLog::where('activity_type', 'file_uploaded')->count());

        $message = Message::sole();
        $this->assertSame('Here is the draft', $message->body);
        $this->assertNotNull($message->file_path);
    }

    /*
    | The daily message cap
    */

    public function test_messages_past_the_daily_cap_still_send_but_stop_scoring(): void
    {
        $cap = (int) config('contribution.message_cap_per_day');

        $component = $this->board();

        for ($i = 1; $i <= $cap + 5; $i++) {
            $component->set('body', "Message {$i}")->call('send')->assertHasNoErrors();
        }

        // Every message is delivered...
        $this->assertSame($cap + 5, Message::count());

        // ...but only the first `cap` of them earned score.
        $this->assertSame($cap, ActivityLog::where('activity_type', 'message_sent')->count());
    }

    public function test_the_cap_does_not_hide_or_block_anything_in_the_ui(): void
    {
        $cap = (int) config('contribution.message_cap_per_day');

        $component = $this->board();

        for ($i = 1; $i <= $cap + 2; $i++) {
            $component->set('body', "Message {$i}")->call('send');
        }

        // The over-cap messages sit in the thread like any other, and no copy
        // anywhere tells the student the scoring rule exists.
        $component->assertSee('Message '.($cap + 2))
            ->assertSee('Message '.($cap + 1))
            ->assertDontSee('daily limit')
            ->assertDontSee('message cap')
            ->assertDontSee('no longer counts')
            ->assertDontSee('stopped earning')
            ->assertDontSee('contribution score');
    }

    public function test_the_cap_is_per_group(): void
    {
        GroupMember::create(['group_id' => $this->otherGroup->id, 'user_id' => $this->me->id, 'joined_at' => now()]);

        $cap = (int) config('contribution.message_cap_per_day');

        $first = $this->board();
        for ($i = 1; $i <= $cap; $i++) {
            $first->set('body', "A{$i}")->call('send');
        }

        // A different group starts from a fresh allowance.
        Livewire::actingAs($this->me)
            ->test(GroupChat::class, ['group' => $this->otherGroup])
            ->set('body', 'First here')
            ->call('send');

        $this->assertSame($cap, ActivityLog::where('activity_type', 'message_sent')
            ->where('group_id', $this->group->id)->count());
        $this->assertSame(1, ActivityLog::where('activity_type', 'message_sent')
            ->where('group_id', $this->otherGroup->id)->count());
    }

    public function test_uploads_are_not_capped(): void
    {
        $cap = (int) config('contribution.message_cap_per_day');

        $component = $this->board();

        for ($i = 1; $i <= $cap; $i++) {
            $component->set('body', "M{$i}")->call('send');
        }

        $component->set('attachment', UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'))->call('send');

        $this->assertSame($cap, ActivityLog::where('activity_type', 'message_sent')->count());
        $this->assertSame(1, ActivityLog::where('activity_type', 'file_uploaded')->count());
    }

    /*
    | Uploads
    */

    public function test_the_stored_filename_is_never_the_uploaded_one(): void
    {
        $this->board()
            ->set('attachment', UploadedFile::fake()->create('../../evil name.pdf', 10, 'application/pdf'))
            ->call('send');

        $message = Message::sole();

        // Display name is kept as sent...
        $this->assertStringContainsString('evil name', $message->file_name);

        // ...but nothing from it reaches the path on disk.
        $this->assertStringNotContainsString('evil', $message->file_path);
        $this->assertStringNotContainsString('..', $message->file_path);
        $this->assertStringStartsWith('group-files/'.$this->group->id.'/', $message->file_path);
        $this->assertMatchesRegularExpression('#/[A-Za-z0-9]{40}\.pdf$#', $message->file_path);
    }

    public function test_the_file_is_stored_on_the_private_disk(): void
    {
        $this->board()
            ->set('attachment', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->call('send');

        Storage::disk('local')->assertExists(Message::sole()->file_path);
    }

    public function test_a_disallowed_type_is_rejected(): void
    {
        $this->board()
            ->set('attachment', UploadedFile::fake()->create('shell.php', 5, 'application/x-php'))
            ->call('send')
            ->assertHasErrors('attachment');

        $this->assertSame(0, Message::count());
    }

    public function test_a_file_renamed_to_look_allowed_is_still_rejected(): void
    {
        // Correct extension, wrong contents — the mimetypes rule reads the file.
        $this->board()
            ->set('attachment', UploadedFile::fake()->create('payload.pdf', 5, 'text/html'))
            ->call('send')
            ->assertHasErrors('attachment');

        $this->assertSame(0, Message::count());
    }

    public function test_a_file_over_ten_megabytes_is_rejected(): void
    {
        $this->board()
            ->set('attachment', UploadedFile::fake()->create('huge.pdf', 10 * 1024 + 1, 'application/pdf'))
            ->call('send')
            ->assertHasErrors('attachment');

        $this->assertSame(0, Message::count());
    }

    /*
    | Downloading
    */

    protected function sendFile(): Message
    {
        $this->board()
            ->set('attachment', UploadedFile::fake()->create('minutes.pdf', 12, 'application/pdf'))
            ->call('send');

        return Message::sole();
    }

    public function test_a_member_can_download_an_attachment(): void
    {
        $message = $this->sendFile();

        $this->actingAs($this->mate)
            ->get(route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]))
            ->assertOk()
            ->assertDownload('minutes.pdf');
    }

    public function test_a_non_member_cannot_download_an_attachment(): void
    {
        $message = $this->sendFile();

        $this->actingAs($this->outsider)
            ->get(route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]))
            ->assertNotFound();
    }

    /**
     * Built without Livewire::actingAs, which authenticates the whole test case
     * — a guest assertion made after it would silently still be signed in.
     */
    protected function plantFile(): Message
    {
        $path = 'group-files/'.$this->group->id.'/'.str_repeat('b', 40).'.pdf';

        Storage::disk('local')->put($path, 'pretend-pdf-bytes');

        return Message::create([
            'group_id' => $this->group->id,
            'user_id' => $this->me->id,
            'body' => null,
            'file_path' => $path,
            'file_name' => 'minutes.pdf',
            'file_size' => 17,
        ]);
    }

    public function test_a_guest_cannot_download_an_attachment(): void
    {
        $message = $this->plantFile();

        $this->assertGuest();

        $this->get(route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]))
            ->assertRedirect(route('login'));
    }

    public function test_the_wrong_token_is_refused_even_for_a_member(): void
    {
        $message = $this->sendFile();

        $this->actingAs($this->me)
            ->get(route('student.chat.file', ['message' => $message, 'token' => str_repeat('a', 40)]))
            ->assertNotFound();
    }

    public function test_the_attachment_is_served_as_a_download_not_inline(): void
    {
        $message = $this->sendFile();

        $response = $this->actingAs($this->me)
            ->get(route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]));

        $this->assertStringStartsWith('attachment;', $response->headers->get('content-disposition'));
    }

    /*
    | Scoping
    */

    public function test_a_non_member_cannot_open_the_thread(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('student.chat.show', $this->group))
            ->assertNotFound();
    }

    public function test_a_non_member_cannot_mount_the_component(): void
    {
        Livewire::actingAs($this->outsider)
            ->test(GroupChat::class, ['group' => $this->group])
            ->assertStatus(404);
    }

    public function test_the_thread_shows_only_this_groups_messages(): void
    {
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'body' => 'Ours']);
        Message::create(['group_id' => $this->otherGroup->id, 'user_id' => $this->outsider->id, 'body' => 'Theirs']);

        $this->board()->assertSee('Ours')->assertDontSee('Theirs');
    }

    public function test_a_student_with_no_group_gets_an_empty_state(): void
    {
        $orphan = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($orphan)
            ->get(route('student.chat'))
            ->assertOk()
            ->assertSee('You are not in a group yet');
    }

    /*
    | Rendering
    */

    public function test_messages_are_grouped_by_day_with_a_divider(): void
    {
        $old = Message::create(['group_id' => $this->group->id, 'user_id' => $this->mate->id, 'body' => 'Last week']);
        $old->forceFill(['created_at' => now()->subWeek(), 'updated_at' => now()->subWeek()])->save();

        Message::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'body' => 'Right now']);

        $this->board()
            ->assertSee('Today')
            // The chip carries the short form. The long form also appears on
            // every timestamp's title attribute, so asserting that instead
            // would pass whether or not the divider rendered at all.
            ->assertSee(now()->subWeek()->format('j M Y'))
            ->assertSee('Last week')
            ->assertSee('Right now');
    }

    public function test_the_date_divider_is_a_chip_not_a_rule(): void
    {
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'body' => 'Hello']);

        $html = $this->board()->html();

        $this->assertStringContainsString('rounded-pill bg-surface-muted', $html);

        // The old treatment drew a hairline either side of the date. It pulled
        // more attention than the label it was framing.
        $this->assertStringNotContainsString('h-px flex-1 bg-surface-line', $html);
    }

    public function test_own_messages_are_labelled_differently_from_others(): void
    {
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'body' => 'Mine']);
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->mate->id, 'body' => 'Theirs']);

        $this->board()
            ->assertSee('You')
            ->assertSee($this->mate->name);
    }

    public function test_the_thread_polls_rather_than_using_websockets(): void
    {
        $this->actingAs($this->me)
            ->get(route('student.chat.show', $this->group))
            ->assertOk()
            ->assertSee('wire:poll.5s', escape: false);
    }

    /*
    | Emoji — utf8mb4 all the way through
    */

    public function test_an_emoji_survives_send_and_display(): void
    {
        $text = 'Great work everyone 🎉👏 lunch at 1? ☕';

        $this->board()->set('body', $text)->call('send')->assertHasNoErrors();

        // Stored byte-for-byte...
        $this->assertSame($text, Message::sole()->body);

        // ...and rendered back into the thread unmangled.
        $this->board()->assertSee($text);
    }

    public function test_emoji_outside_the_basic_plane_are_not_truncated(): void
    {
        // 4-byte characters are exactly what utf8 (3-byte) would mangle.
        $text = '🚀🫠💀🥲';

        $this->board()->set('body', $text)->call('send');

        $stored = Message::sole()->body;

        $this->assertSame($text, $stored);
        $this->assertSame(4, mb_strlen($stored));
        $this->assertStringNotContainsString('?', $stored);
    }

    public function test_an_emoji_only_message_is_allowed(): void
    {
        $this->board()->set('body', '👍')->call('send')->assertHasNoErrors();

        $this->assertSame('👍', Message::sole()->body);
    }

    public function test_the_picker_offers_a_curated_set_not_a_library(): void
    {
        $flat = collect(GroupChat::EMOJI)->flatten();

        $this->assertGreaterThan(20, $flat->count());
        $this->assertLessThan(80, $flat->count(), 'The picker should stay a curated set.');
        $this->assertSame($flat->unique()->count(), $flat->count(), 'Duplicate emoji in the picker.');

        $this->board()->assertSee($flat->first(), escape: false);
    }

    /*
    | Inline images
    */

    protected function sendImage(string $name = 'screenshot.png'): Message
    {
        $this->board()
            ->set('attachment', UploadedFile::fake()->image($name, 400, 300))
            ->call('send');

        return Message::sole();
    }

    public function test_an_image_attachment_renders_as_a_thumbnail(): void
    {
        $message = $this->sendImage();

        $this->assertTrue($message->isImage());

        $html = $this->actingAs($this->me)
            ->get(route('student.chat.show', $this->group))
            ->getContent();

        // Thumbnail points at the authenticated route, carrying the token.
        $expected = route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]);

        $this->assertStringContainsString($expected.'?inline=1', $html);
    }

    public function test_a_non_image_attachment_keeps_the_file_row(): void
    {
        $this->board()
            ->set('attachment', UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'))
            ->call('send');

        $message = Message::sole();

        $this->assertFalse($message->isImage());

        $html = $this->actingAs($this->me)
            ->get(route('student.chat.show', $this->group))
            ->getContent();

        $this->assertStringContainsString('Download', $html);
        $this->assertStringNotContainsString('?inline=1', $html);
    }

    public function test_an_image_can_be_served_inline_for_the_lightbox(): void
    {
        $message = $this->sendImage();

        $response = $this->actingAs($this->me)->get(
            route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]).'?inline=1'
        )->assertOk();

        $this->assertSame('image/png', $response->headers->get('content-type'));
        $this->assertStringStartsWith('inline;', $response->headers->get('content-disposition'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    /**
     * Inline is restricted to png/jpeg. A pdf asked for inline must still come
     * back as a download, or an upload could render on this origin.
     */
    public function test_a_non_image_cannot_be_forced_inline(): void
    {
        $this->board()
            ->set('attachment', UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'))
            ->call('send');

        $message = Message::sole();

        $response = $this->actingAs($this->me)->get(
            route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]).'?inline=1'
        )->assertOk();

        $this->assertStringStartsWith('attachment;', $response->headers->get('content-disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('content-type'));
    }

    public function test_the_inline_route_still_checks_membership(): void
    {
        $message = $this->sendImage();

        $url = route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]).'?inline=1';

        $this->actingAs($this->outsider)->get($url)->assertNotFound();
    }

    public function test_the_inline_route_still_checks_the_token(): void
    {
        $message = $this->sendImage();

        $this->actingAs($this->me)->get(
            route('student.chat.file', ['message' => $message, 'token' => str_repeat('q', 40)]).'?inline=1'
        )->assertNotFound();
    }

    /*
    | Per-sender colour
    */

    public function test_each_sender_gets_a_stable_colour_from_their_id(): void
    {
        $a = MemberColour::for($this->me->id);
        $b = MemberColour::for($this->me->id);

        $this->assertSame($a, $b, 'The same user must always get the same colour.');
        $this->assertStringStartsWith('text-member-', $a['name']);
        $this->assertStringContainsString('bg-member-', $a['avatar']);
    }

    public function test_different_members_of_a_group_get_different_colours(): void
    {
        // Consecutive ids must not collide, or a group would look uniform.
        $seen = [];

        for ($id = 1; $id <= MemberColour::count(); $id++) {
            $seen[] = MemberColour::name($id);
        }

        $this->assertSame(count($seen), count(array_unique($seen)));
    }

    public function test_the_thread_carries_the_senders_colour_class(): void
    {
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->mate->id, 'body' => 'Hello']);

        $tone = MemberColour::for($this->mate->id);

        $this->board()->assertSee($tone['name'], escape: false);
    }

    public function test_a_mates_message_text_carries_their_colour(): void
    {
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->mate->id, 'body' => 'Their line']);

        $tone = MemberColour::for($this->mate->id);
        $html = $this->board()->html();

        // The colour has to be on the paragraph itself, not only the name
        // above it — that is the whole point of the change.
        $this->assertMatchesRegularExpression(
            '/<p[^>]*'.preg_quote($tone['name'], '/').'[^>]*>\s*Their line/',
            $html,
        );
    }

    public function test_my_own_message_text_stays_on_the_dark_bubble(): void
    {
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'body' => 'My line']);

        $tone = MemberColour::for($this->me->id);
        $html = $this->board()->html();

        // A member hue on the dark bubble would fail contrast, so own
        // messages keep the white-on-ink treatment.
        $this->assertDoesNotMatchRegularExpression(
            '/<p[^>]*'.preg_quote($tone['name'], '/').'[^>]*>\s*My line/',
            $html,
        );
    }
}
