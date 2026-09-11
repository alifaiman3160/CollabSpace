<?php

namespace App\Livewire;

use App\Models\AppNotification;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The unread counter in the sidebar.
 *
 * Lives in the layout, so it is on every page for every role. It refreshes two
 * ways: on the 'notifications-updated' event, so acting on the notifications
 * page updates it immediately without a reload, and on a slow poll, so a
 * notification raised by somebody else turns up on its own.
 */
class NotificationBell extends Component
{
    public function render(): View
    {
        return view('livewire.notification-bell', [
            'unread' => auth()->check()
                ? AppNotification::where('user_id', auth()->id())->unread()->count()
                : 0,
        ]);
    }

    /** Dispatched by the notification list after every action. */
    #[On('notifications-updated')]
    public function refreshCount(): void
    {
        // Re-rendering is the whole job; Livewire does that after any action.
    }
}
