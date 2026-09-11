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
    ];
    $tones = [
        'task_overdue' => 'bg-danger-bg text-danger-text',
        'low_participation' => 'bg-danger-bg text-danger-text',
        'deadline' => 'bg-danger-bg text-danger-text',
        'task_assigned' => 'bg-success-bg text-success-text',
        'group_assigned' => 'bg-periwinkle-soft text-periwinkle-link',
    ];
@endphp

<div class="flex flex-col gap-[18px]">

    {{-- Controls --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex rounded-pill bg-surface-muted p-[3px]">
            <button type="button" wire:click="setFilter('all')"
                    class="h-8 rounded-pill px-[15px] text-meta font-bold transition-colors
                           {{ $filter === 'all' ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                All ({{ $total }})
            </button>
            <button type="button" wire:click="setFilter('unread')"
                    class="h-8 rounded-pill px-[15px] text-meta font-bold transition-colors
                           {{ $filter === 'unread' ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                Unread ({{ $unread }})
            </button>
        </div>

        <span class="flex-1"></span>

        @if ($unread > 0)
            <x-button variant="secondary" wire:click="markAllRead">Mark all read</x-button>
        @endif

        @if ($total > 0)
            <x-button variant="secondary" class="!text-danger-text"
                      wire:click="clearAll"
                      wire:confirm="Remove all {{ $total }} notifications? This cannot be undone.">
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

            @foreach ($group as $notification)
                <article wire:key="n-{{ $notification->id }}"
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
                                wire:click="remove({{ $notification->id }})"
                                class="flex size-9 items-center justify-center rounded-pill bg-surface-muted text-ink-400 transition-colors hover:bg-danger-bg hover:text-danger-text"
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
</div>
