@props([
    'items',                    // the subject's announcements, newest first (already loaded, with author)
    'colour',                   // SubjectPalette entry
    'suffix',                   // makes the ids on the page unique (the card's own id)
    'subjectId',
    'unreadIds' => [],          // student: the ones not opened yet
    'readUrl' => null,          // student: where "opened" is reported
    // Lecturer only. Both null for a student, so nothing extra shows.
    'audience' => null,         // students in the subject
    'opened' => null,           // announcement id => how many of them opened it
    'postUrl' => null,          // where a new announcement is written
])

@php
    $items = collect($items);
    $latest = $items->first();
    $unreadIds = collect($unreadIds)->values();
    $unreadCount = $unreadIds->count();
@endphp

{{--
    The announcements strip along the bottom of a subject card, shared by the student and the lecturer dashboards.
    One quiet pill with the latest title and time; it opens (the shared .cs-collapse motion) to the announcements,
    and a long one has "Read more". With none, the same pill says so, so every card keeps the same bottom edge.
    For a student it also tracks which were opened (announcement-strip.js); a lecturer sees "Opened by X of Y"
    on each instead and has a link to write another.
--}}
@if ($items->isNotEmpty())
    <div class="px-3 pb-3" data-announcements-for="{{ $subjectId }}"
         x-data="announcementStrip({ ids: @js($items->pluck('id')->values()), unreadIds: @js($unreadIds), url: @js($readUrl), token: @js(csrf_token()) })"
         x-on:hashchange.window="reveal()">
        <button type="button" x-on:click="toggle()" x-bind:aria-expanded="open"
                aria-controls="announcements-{{ $suffix }}" data-announcement-strip
                class="interactive flex min-h-[48px] w-full items-center gap-2.5 rounded-pill bg-white/70 px-3.5 py-2 text-left text-meta text-ink hover:bg-white/90">
            <x-icon name="bell" size="16" class="shrink-0 text-ink-600" />

            <span class="flex min-w-0 flex-1 flex-col">
                @if ($unreadCount > 0)
                    <span class="flex items-center gap-1.5 text-label font-bold text-danger-text" x-show="unread > 0" data-unread>
                        <span class="size-2 shrink-0 rounded-pill bg-danger" aria-hidden="true" data-unread-dot></span>
                        {{ $unreadCount === 1 ? 'New announcement' : $unreadCount.' new announcements' }}
                    </span>
                @endif
                <span class="truncate {{ $unreadCount > 0 ? 'font-extrabold' : 'font-semibold' }}"
                      @if ($unreadCount > 0) x-bind:class="unread > 0 ? 'font-extrabold' : 'font-semibold'" @endif>{{ $latest->title }}</span>
            </span>

            @if ($items->count() > 1)
                <span class="shrink-0 text-label font-medium text-ink-600" @if ($unreadCount > 0) x-show="unread === 0" @endif>{{ $items->count() }} announcements</span>
            @endif
            <span class="shrink-0 text-label font-medium text-ink-600">{{ $latest->created_at->diffForHumans(short: true) }}</span>
            <svg class="cs-chevron size-4 shrink-0" style="color: {{ $colour['accent'] }};" x-bind:class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <x-collapse open="open" instant="instant" id="announcements-{{ $suffix }}" data-announcement-panel>
           <div class="flex flex-col gap-2.5 pt-2.5">
            @foreach ($items as $n => $announcement)
                <article id="announcement-{{ $announcement->id }}"
                         class="flex flex-col gap-1.5 rounded-card bg-white/80 p-3.5 text-ink"
                         @if ($n >= 3) x-show="earlier" x-cloak x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-out duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @endif
                         x-data="{ more: false }" data-announcement>
                    <h3 class="text-nav font-bold">{{ $announcement->title }}</h3>

                    <p class="whitespace-pre-line break-words text-body leading-[1.6] text-ink-600"
                       @if ($announcement->isLong()) x-bind:class="more ? 'cs-reveal' : 'line-clamp-3'" @endif>{{ $announcement->body }}</p>

                    @if ($announcement->isLong())
                        <button type="button" x-on:click="more = ! more" x-bind:aria-expanded="more" data-read-more
                                class="interactive self-start rounded-pill px-2.5 py-1 text-label font-bold text-periwinkle-link hover:bg-periwinkle-soft">
                            <span x-text="more ? 'Show less' : 'Read more'">Read more</span>
                        </button>
                    @endif

                    <p class="text-label text-ink-600">
                        {{ $announcement->author->name }} · <span title="{{ $announcement->created_at->format('j F Y, g:ia') }}">{{ $announcement->created_at->diffForHumans() }}</span>
                        @if ($audience !== null)
                            · <span data-opened-by>Opened by {{ (int) ($opened[$announcement->id] ?? 0) }} of {{ $audience }}</span>
                        @endif
                    </p>
                </article>
            @endforeach

            @if ($items->count() > 3)
                <button type="button" x-on:click="toggleEarlier()" x-bind:aria-expanded="earlier" data-show-earlier
                        class="interactive self-start rounded-pill bg-white/80 px-4 py-2 text-meta font-semibold text-ink hover:bg-white">
                    <span x-text="earlier ? 'Show fewer' : 'Show earlier ({{ $items->count() - 3 }})'">Show earlier ({{ $items->count() - 3 }})</span>
                </button>
            @endif

            @if ($postUrl)
                <a href="{{ $postUrl }}" data-post-announcement
                   class="interactive inline-flex items-center gap-1.5 self-start rounded-pill bg-white/80 px-4 py-2 text-meta font-semibold text-ink hover:bg-white">
                    <x-icon name="plus" size="14" />Post announcement
                </a>
            @endif
           </div>
        </x-collapse>
    </div>
@else
    {{-- The same pill, quiet and not clickable, so every card has the same bottom edge. --}}
    <div class="px-3 pb-3" data-announcements-empty>
        <div class="flex min-h-[48px] w-full items-center gap-2.5 rounded-pill bg-white/40 px-3.5 py-2 text-meta text-ink-600">
            <x-icon name="bell" size="16" class="shrink-0" />
            <span class="min-w-0 flex-1 truncate font-medium">No announcements yet</span>
            @if ($postUrl)
                <a href="{{ $postUrl }}" data-post-announcement
                   class="interactive shrink-0 rounded-pill bg-white/70 px-3 py-1 text-label font-bold text-ink hover:bg-white">Post one</a>
            @endif
        </div>
    </div>
@endif
