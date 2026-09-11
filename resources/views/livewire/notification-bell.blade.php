{{-- Polls slowly so a notification raised elsewhere appears on its own; the
     notifications page also dispatches 'notifications-updated' for an instant
     refresh after an action. --}}
<span wire:poll.15s class="contents">
    @if ($unread > 0)
        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-pill bg-danger px-1.5 text-tiny font-bold text-white"
              aria-label="{{ $unread }} unread notifications">{{ $unread }}</span>
    @endif
</span>
