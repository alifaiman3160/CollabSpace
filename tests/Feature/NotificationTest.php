<?php

namespace Tests\Feature;

use App\Livewire\GroupChat;
use App\Livewire\NotificationBell;
use App\Livewire\NotificationList;
use App\Livewire\TaskBoard;
use App\Models\AppNotification;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $me;

    protected User $mate;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['name' => 'Dr Siti', 'must_change_password' => false]);
        $subject = Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);
        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['name' => 'Alif', 'must_change_password' => false]);
        $this->mate = User::factory()->student()->create(['name' => 'Nurul', 'must_change_password' => false]);

        foreach ([$this->me, $this->mate] as $m) {
            GroupMember::create(['group_id' => $this->group->id, 'user_id' => $m->id, 'joined_at' => now()]);
        }
    }

    protected function notify(User $user, bool $read = false): AppNotification
    {
        $n = new AppNotification([
            'user_id' => $user->id, 'type' => 'task_assigned',
            'message' => 'Something happened', 'link' => '/student/dashboard', 'is_read' => $read,
        ]);
        $n->created_at = now();
        $n->save();

        return $n;
    }

    /*
    | What writes notifications
    */

    public function test_assigning_a_task_notifies_the_assignee(): void
    {
        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->set('title', 'Write chapter 1')
            ->set('assignedTo', $this->mate->id)
            ->call('createTask')
            ->assertHasNoErrors();

        $notification = AppNotification::where('user_id', $this->mate->id)->sole();

        $this->assertSame('task_assigned', $notification->type);
        $this->assertStringContainsString('Write chapter 1', $notification->message);
        $this->assertFalse($notification->is_read);

        // The person doing the assigning is not told about their own action.
        $this->assertSame(0, AppNotification::where('user_id', $this->me->id)->count());
    }

    public function test_assigning_a_task_to_yourself_notifies_nobody(): void
    {
        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->set('title', 'My own job')
            ->set('assignedTo', $this->me->id)
            ->call('createTask');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_a_chat_message_notifies_the_rest_of_the_group(): void
    {
        Livewire::actingAs($this->me)
            ->test(GroupChat::class, ['group' => $this->group])
            ->set('body', 'Morning all')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(1, AppNotification::where('user_id', $this->mate->id)->count());
        $this->assertSame(0, AppNotification::where('user_id', $this->me->id)->count());

        $this->assertStringContainsString('Morning all', AppNotification::sole()->message);
    }

    public function test_creating_a_document_notifies_the_group(): void
    {
        $this->actingAs($this->me)
            ->post(route('student.documents.store', $this->group), ['title' => 'Chapter 1']);

        $notification = AppNotification::where('user_id', $this->mate->id)->sole();

        $this->assertSame('document_created', $notification->type);
        $this->assertStringContainsString('Chapter 1', $notification->message);
    }

    public function test_editing_a_document_notifies_the_group(): void
    {
        $this->actingAs($this->me)
            ->post(route('student.documents.store', $this->group), ['title' => 'Chapter 1']);

        AppNotification::query()->delete();

        $document = \App\Models\Document::sole();

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1', 'content' => '<p>New writing</p>',
        ]);

        $this->assertSame('document_edited', AppNotification::where('user_id', $this->mate->id)->sole()->type);
    }

    public function test_a_no_op_document_save_notifies_nobody(): void
    {
        $this->actingAs($this->me)
            ->post(route('student.documents.store', $this->group), ['title' => 'Chapter 1']);

        AppNotification::query()->delete();

        $document = \App\Models\Document::sole();

        $this->actingAs($this->me)->put(route('student.documents.update', $document), [
            'title' => 'Chapter 1', 'content' => '',
        ]);

        $this->assertSame(0, AppNotification::count());
    }

    public function test_being_added_to_a_group_notifies_the_student(): void
    {
        $newcomer = User::factory()->student()->create(['name' => 'Farah']);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $this->group), ['user_id' => $newcomer->id]);

        $notification = AppNotification::where('user_id', $newcomer->id)->sole();

        $this->assertSame('group_assigned', $notification->type);
        $this->assertStringContainsString('Group A', $notification->message);
    }

    public function test_being_removed_from_a_group_notifies_the_student(): void
    {
        $this->actingAs($this->lecturer)
            ->delete(route('lecturer.groups.members.destroy', [$this->group, $this->mate]));

        $this->assertSame('group_removed', AppNotification::where('user_id', $this->mate->id)->sole()->type);
    }

    /*
    | The list
    */

    public function test_the_list_shows_only_my_own_notifications(): void
    {
        $this->notify($this->me);
        $mine = AppNotification::sole();
        $mine->update(['message' => 'For me']);

        $theirs = $this->notify($this->mate);
        $theirs->update(['message' => 'For them']);

        Livewire::actingAs($this->me)
            ->test(NotificationList::class)
            ->assertSee('For me')
            ->assertDontSee('For them');
    }

    public function test_marking_one_read_and_unread(): void
    {
        $n = $this->notify($this->me);

        $component = Livewire::actingAs($this->me)->test(NotificationList::class);

        $component->call('markRead', $n->id);
        $this->assertTrue($n->fresh()->is_read);

        $component->call('markUnread', $n->id);
        $this->assertFalse($n->fresh()->is_read);
    }

    public function test_marking_all_read(): void
    {
        $this->notify($this->me);
        $this->notify($this->me);
        $otherPersons = $this->notify($this->mate);

        Livewire::actingAs($this->me)->test(NotificationList::class)->call('markAllRead');

        $this->assertSame(0, AppNotification::where('user_id', $this->me->id)->unread()->count());
        // Somebody else's stays untouched.
        $this->assertFalse($otherPersons->fresh()->is_read);
    }

    public function test_removing_one_and_clearing_all(): void
    {
        $a = $this->notify($this->me);
        $this->notify($this->me);
        $theirs = $this->notify($this->mate);

        $component = Livewire::actingAs($this->me)->test(NotificationList::class);

        $component->call('remove', $a->id);
        $this->assertDatabaseMissing('app_notifications', ['id' => $a->id]);

        $component->call('clearAll');
        $this->assertSame(0, AppNotification::where('user_id', $this->me->id)->count());
        $this->assertDatabaseHas('app_notifications', ['id' => $theirs->id]);
    }

    public function test_another_users_notification_cannot_be_touched(): void
    {
        $theirs = $this->notify($this->mate);

        $component = Livewire::actingAs($this->me)->test(NotificationList::class);

        $component->call('markRead', $theirs->id)->assertStatus(404);
        $this->assertFalse($theirs->fresh()->is_read);

        Livewire::actingAs($this->me)->test(NotificationList::class)
            ->call('remove', $theirs->id)->assertStatus(404);
        $this->assertDatabaseHas('app_notifications', ['id' => $theirs->id]);
    }

    public function test_the_unread_filter(): void
    {
        $read = $this->notify($this->me, read: true);
        $read->update(['message' => 'Already seen']);
        $unread = $this->notify($this->me);
        $unread->update(['message' => 'Still new']);

        Livewire::actingAs($this->me)
            ->test(NotificationList::class)
            ->call('setFilter', 'unread')
            ->assertSee('Still new')
            ->assertDontSee('Already seen');
    }

    /*
    | The counter
    */

    public function test_the_bell_counts_only_my_unread(): void
    {
        $this->notify($this->me);
        $this->notify($this->me);
        $this->notify($this->me, read: true);
        $this->notify($this->mate);

        Livewire::actingAs($this->me)->test(NotificationBell::class)->assertSee('2');
    }

    public function test_the_bell_hides_itself_at_zero(): void
    {
        Livewire::actingAs($this->me)
            ->test(NotificationBell::class)
            ->assertDontSee('aria-label="0 unread');
    }

    public function test_acting_on_the_list_tells_the_bell_to_refresh(): void
    {
        $n = $this->notify($this->me);

        Livewire::actingAs($this->me)
            ->test(NotificationList::class)
            ->call('markRead', $n->id)
            ->assertDispatched('notifications-updated');
    }

    /*
    | Access
    */

    public function test_every_role_has_a_notifications_screen(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->actingAs($admin)->get(route('admin.notifications'))->assertOk()->assertSee('Notifications');
        $this->actingAs($this->lecturer)->get(route('lecturer.notifications'))->assertOk()->assertSee('Notifications');
        $this->actingAs($this->me)->get(route('student.notifications'))->assertOk()->assertSee('Notifications');
    }

    public function test_the_counter_appears_in_the_shell(): void
    {
        $this->notify($this->me);

        $this->actingAs($this->me)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('unread notifications', escape: false);
    }
}
