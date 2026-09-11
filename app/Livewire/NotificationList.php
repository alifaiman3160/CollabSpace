<?php

namespace App\Livewire;

use App\Models\AppNotification;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The notifications screen, shared by all three roles.
 *
 * Every action is scoped to the signed-in user's own rows — a notification id
 * arriving from the browser is never trusted on its own.
 */
class NotificationList extends Component
{
    /** all | unread */
    public string $filter = 'all';

    protected function own()
    {
        return AppNotification::query()->where('user_id', auth()->id());
    }

    /** Resolves one of the signed-in user's notifications, or 404s. */
    protected function ownNotification(int $id): AppNotification
    {
        $notification = $this->own()->whereKey($id)->first();

        abort_if($notification === null, 404);

        return $notification;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter === 'unread' ? 'unread' : 'all';
    }

    public function markRead(int $id): void
    {
        $this->ownNotification($id)->update(['is_read' => true]);

        $this->dispatch('notifications-updated');
    }

    public function markUnread(int $id): void
    {
        $this->ownNotification($id)->update(['is_read' => false]);

        $this->dispatch('notifications-updated');
    }

    public function markAllRead(): void
    {
        $this->own()->unread()->update(['is_read' => true]);

        $this->dispatch('notifications-updated');
    }

    public function remove(int $id): void
    {
        $this->ownNotification($id)->delete();

        $this->dispatch('notifications-updated');
    }

    public function clearAll(): void
    {
        $this->own()->delete();

        $this->dispatch('notifications-updated');
    }

    public function render(): View
    {
        $notifications = $this->own()
            ->when($this->filter === 'unread', fn ($q) => $q->unread())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('livewire.notification-list', [
            'notifications' => $notifications->groupBy(fn ($n) => $n->created_at->toDateString()),
            'total' => $this->own()->count(),
            'unread' => $this->own()->unread()->count(),
        ]);
    }
}
