@php
    $icons = [
        'task_assigned' => 'check',
        'task_overdue' => 'clock',
        'chat_message' => 'chat',
        'document_created' => 'file',
        'document_edited' => 'edit',
        'group_assigned' => 'users',
        'group_removed' => 'users',
        'low_participation' => 'alert',
        'deadline' => 'calendar',
        'mention' => 'chat',
        'project_deadline' => 'book',
        'announcement' => 'inbox',
        // Lecturer
        'group_completed' => 'check',
        // Administrator
        'account_created' => 'user',
        'account_status' => 'shield',
        'account_password' => 'lock',
        'account_activated' => 'check',
    ];
    $tones = [
        'task_overdue' => 'bg-danger-bg text-danger-text',
        'low_participation' => 'bg-danger-bg text-danger-text',
        // A deadline that is still ahead is information, not an alarm — the
        // alarm is task_overdue, and two red rows would flatten the difference
        // between "due Friday" and "you have already missed it".
        'deadline' => 'bg-periwinkle-soft text-periwinkle-link',
        'task_assigned' => 'bg-success-bg text-success-text',
        'group_assigned' => 'bg-periwinkle-soft text-periwinkle-link',
        'group_completed' => 'bg-success-bg text-success-text',
        'account_activated' => 'bg-success-bg text-success-text',
        'account_created' => 'bg-periwinkle-soft text-periwinkle-link',
        'project_deadline' => 'bg-periwinkle-soft text-periwinkle-link',
        'announcement' => 'bg-periwinkle-soft text-periwinkle-link',
    ];
@endphp

{{-- One Alpine scope wrapping the list and the single confirm dialog, the same
     arrangement the admin and lecturer tables use. --}}
<div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

    {{-- Controls --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex rounded-pill bg-surface-muted p-[3px]">
            <button type="button" wire:click="setFilter('all')"
                    class="h-8 rounded-pill px-[15px] text-meta font-bold interactive
                           {{ $filter === 'all' ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                All ({{ $total }})
            </button>
            <button type="button" wire:click="setFilter('unread')"
                    class="h-8 rounded-pill px-[15px] text-meta font-bold interactive
                           {{ $filter === 'unread' ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                Unread ({{ $unread }})
            </button>
        </div>

        <span class="flex-1"></span>

        @if ($unread > 0)
            <x-button variant="secondary" wire:click="markAllRead">Mark all read</x-button>
        @endif

        @if ($total > 0)
            @php
                // Built here, then passed through Js::from below. A double
                // quote inside a component attribute ends that attribute as
                // far as Blade's component parser is concerned, so the string
                // cannot be written inline — same reason the admin and lecturer
                // tables build theirs up here too.
                $clearBody = 'All '.$total.' '.Str::plural('notification', $total)
                    .' will be removed from your list. Nothing else is affected — the tasks,'
                    .' documents and messages they refer to are untouched.';
            @endphp

            {{-- Was wire:confirm, which raises the browser's own confirm() box:
                 a grey system dialog with the site's hostname at the top, in
                 the middle of an app that styles every other confirmation
                 itself. Same guard, CollabSpace's own dialog. --}}
            <x-button variant="secondary" class="!text-danger-text"
                      x-on:click="confirm = {
                          call: 'clearAll',
                          title: 'Clear every notification?',
                          body: {{ Js::from($clearBody) }},
                          action: 'Clear all',
                          tone: 'danger',
                      }">
                Clear all
            </x-button>
        @endif
    </div>

    @forelse ($notifications as $date => $group)
        @php($day = \Illuminate\Support\Carbon::parse($date))

        <div class="flex flex-col gap-2.5">
            <span class="text-label font-bold uppercase tracking-eyebrow text-ink-300">
                @if ($day->isToday()) Today
                @elseif ($day->isYesterday()) Yesterday
                @else {{ $day->format('j F Y') }}
                @endif
            </span>

            {{-- The line below uses the inline php directive, with the value in
                 brackets, rather than the block form with a closing tag.
                 Livewire injects its own code at the top of every loop
                 iteration to track wire:key, and a block directive there lands
                 inside that injection and breaks the compiled template. The
                 inline form compiles to one statement and is safe.

                 For the same reason, do not name Blade directives inside a
                 comment in this file: the compiler reads them before comments
                 are stripped, which is its own way of producing a parse error
                 a long way from the line that caused it. --}}
            @foreach ($group as $notification)
                @php($removeBody = Str::limit($notification->message, 120).' — this removes the notification only; whatever it refers to is untouched.')
                <article wire:key="n-{{ $notification->id }}" data-cs-item
                         class="flex flex-wrap items-center gap-3.5 rounded-card px-4 py-3.5 transition-colors
                                {{ $notification->is_read ? 'border border-surface-line bg-surface-soft' : 'border border-surface-line bg-surface shadow-subtle' }}">

                    <span class="flex size-10 shrink-0 items-center justify-center rounded-[14px] {{ $tones[$notification->type] ?? 'bg-surface-muted text-ink-600' }}">
                        <x-icon :name="$icons[$notification->type] ?? 'bell'" size="18" />
                    </span>

                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-body {{ $notification->is_read ? 'font-medium text-ink-600' : 'font-bold text-ink' }}">
                                {{ $notification->message }}
                            </span>
                            @unless ($notification->is_read)
                                <span class="size-2 shrink-0 rounded-pill bg-danger" aria-label="Unread"></span>
                            @endunless
                        </span>
                        <span class="text-label text-ink-300" title="{{ $notification->created_at->format('j F Y, g:ia') }}">
                            {{ $notification->created_at->diffForHumans() }}
                        </span>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        @if ($notification->link)
                            <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                      :href="$notification->link"
                                      wire:click="markRead({{ $notification->id }})">Open</x-button>
                        @endif

                        @if ($notification->is_read)
                            <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                      wire:click="markUnread({{ $notification->id }})">Mark unread</x-button>
                        @else
                            <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                      wire:click="markRead({{ $notification->id }})">Mark read</x-button>
                        @endif

                        <button type="button"
                                x-on:click="confirm = {
                                    call: 'remove',
                                    args: [{{ $notification->id }}],
                                    title: 'Remove this notification?',
                                    body: {{ Js::from($removeBody) }},
                                    action: 'Remove',
                                    tone: 'danger',
                                }"
                                class="flex size-9 items-center justify-center rounded-pill bg-surface-muted text-ink-400 interactive hover:bg-danger-bg hover:text-danger-text"
                                aria-label="Remove this notification">
                            <x-icon name="trash" size="15" />
                        </button>
                    </div>
                </article>
            @endforeach
        </div>
    @empty
        <x-empty-state
            icon="bell"
            :title="$filter === 'unread' ? 'Nothing unread' : 'No notifications'"
            :body="$filter === 'unread'
                ? 'You are all caught up. Switch to All to see the ones you have already read.'
                : 'Assignments, document changes and group activity will show up here.'" />
    @endforelse

    <x-confirm-dialog />
</div>
