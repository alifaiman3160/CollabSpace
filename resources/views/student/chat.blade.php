<x-layouts.app title="Group chat — CollabSpace" fit
               heading="Group chat"
               :subtitle="$hasGroup
                   ? $group->subject->code.' — '.$group->name
                   : 'You are not in a group yet.'">

    @if (! $hasGroup)

        <x-empty-state
            icon="chat"
            title="You are not in a group yet"
            body="Chat belongs to a group. Once your lecturer assigns you to one, your group's thread appears here." />

    @else

        @php
            $initialsOf = fn (string $name) => \Illuminate\Support\Str::of($name)->explode(' ')->filter()->take(2)
                ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
            $me = auth()->id();

            // What the page script needs to switch in place: each group's address and name.
            $switchTo = $groups->mapWithKeys(fn ($g) => [$g->id => ['url' => route('student.chat.show', $g), 'name' => $g->name]]);
        @endphp

        {{-- The card takes whatever height is left under the title. Every parent below has min-height 0, so the
             height is right however tall the title is, and only the two lists scroll. --}}
        <div class="mx-auto flex w-full max-w-[1400px] flex-col gap-[18px] min-[900px]:min-h-0 min-[900px]:flex-1" data-chat-page>

                <x-flash />

                {{-- One card, two panes: the groups on the left, the open conversation on the right. --}}
                <div class="flex min-h-[36rem] flex-1 overflow-hidden rounded-panel border border-surface-line bg-surface shadow-card min-[900px]:min-h-0"
                     x-data="chatSwitcher({ current: {{ $group->id }}, groups: @js($switchTo) })"
                     data-messenger>

                    {{-- Politely says which chat opened. --}}
                    <p class="sr-only" role="status" aria-live="polite" x-text="announce"></p>

                    {{-- Left: my groups. Below 900px it gives way to the strip of chips above the conversation. --}}
                    <aside class="hidden w-[320px] shrink-0 flex-col border-r border-surface-line bg-surface-soft min-[900px]:flex" x-data="{ q: '' }" aria-label="My groups" data-group-list>
                        <div class="border-b border-surface-line p-3">
                            <label class="relative block">
                                <span class="sr-only">Search groups</span>
                                <x-icon name="search" size="15" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-300" />
                                <input type="search" x-model="q" placeholder="Search groups" autocomplete="off" data-group-search
                                       class="h-10 w-full rounded-pill border border-surface-line bg-surface pl-10 pr-4 text-meta font-medium text-ink placeholder:text-ink-300 transition-colors focus:border-ink/30 focus:outline-none focus:ring-2 focus:ring-ink/10" />
                            </label>
                        </div>

                        <nav class="min-h-0 flex-1 overflow-y-auto" aria-label="Groups">
                            @foreach ($groups as $g)
                                @php
                                    $pal = \App\Support\SubjectPalette::for($g->subject_id);
                                    $last = $lastMessages->get($g->id);
                                    $isCurrent = $g->id === $group->id;
                                @endphp
                                <a href="{{ route('student.chat.show', $g) }}"
                                   @if ($isCurrent) aria-current="page" @endif
                                   x-bind:aria-current="current === {{ $g->id }} ? 'page' : null"
                                   x-on:click="go($event, {{ $g->id }})"
                                   data-group-row data-search="{{ mb_strtolower($g->name.' '.$g->subject->code) }}"
                                   x-show="q === '' || $el.dataset.search.includes(q.toLowerCase().trim())"
                                   class="group relative flex items-center gap-3 px-4 py-3 transition-colors duration-150 ease-out aria-[current=page]:bg-periwinkle-soft not-aria-[current=page]:hover:bg-surface-muted">
                                    {{-- On every row, shown on the open one: it fades from row to row. --}}
                                    <span class="absolute inset-y-2 left-0 w-1 origin-center scale-y-0 rounded-r-pill bg-periwinkle-link transition-transform duration-150 ease-out group-aria-[current=page]:scale-y-100"
                                          aria-hidden="true" data-row-bar></span>

                                    <span class="flex size-11 shrink-0 items-center justify-center rounded-pill text-meta font-extrabold text-ink" style="background-color: {{ $pal['accent'] }};" aria-hidden="true">{{ $initialsOf($g->name) }}</span>

                                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span class="flex items-center gap-2">
                                            <span class="truncate text-nav font-bold">{{ $g->name }}</span>
                                            <span class="shrink-0 rounded-pill px-2 py-px text-micro font-bold text-ink" style="background-color: {{ $pal['bg'] }}; box-shadow: inset 0 0 0 1px {{ $pal['border'] }};">{{ $g->subject->code }}</span>
                                        </span>
                                        <span class="flex items-center gap-1 truncate text-label text-ink-600" data-preview>
                                            @if (! $last)
                                                No messages yet
                                            @elseif ($last->hasAttachment() && ! $last->body)
                                                <span class="shrink-0 font-semibold">{{ $last->user_id === $me ? 'You' : \Illuminate\Support\Str::before($last->user->name, ' ') }}:</span>
                                                <x-icon name="file" size="12" class="shrink-0" />
                                                <span class="truncate">{{ $last->isImage() ? 'Photo' : 'File' }}</span>
                                            @else
                                                <span class="truncate"><span class="font-semibold">{{ $last->user_id === $me ? 'You' : \Illuminate\Support\Str::before($last->user->name, ' ') }}:</span> {{ $last->body }}</span>
                                            @endif
                                        </span>
                                    </span>

                                    @if ($last)
                                        <span class="shrink-0 self-start pt-0.5 text-tiny font-medium text-ink-600" title="{{ $last->created_at->format('j F Y, g:ia') }}" data-last-time>
                                            {{ $last->created_at->isToday() ? $last->created_at->diffForHumans(short: true) : $last->created_at->format('j M') }}
                                        </span>
                                    @endif
                                </a>
                            @endforeach
                            <p class="px-4 py-6 text-center text-meta text-ink-400" x-show="q !== '' && ! [...$root.querySelectorAll('[data-group-row]')].some((row) => row.dataset.search.includes(q.toLowerCase().trim()))" x-cloak>No group matches that.</p>
                        </nav>
                    </aside>

                    {{-- Right: the conversation. --}}
                    <section class="flex min-h-0 min-w-0 flex-1 flex-col" aria-label="Conversation">

                        {{-- Narrow screens: the groups as a compact strip of chips; the same in-place switch. --}}
                        @if ($groups->count() > 1)
                            <div class="flex gap-1.5 overflow-x-auto border-b border-surface-line bg-surface-soft p-3 min-[900px]:hidden" data-group-strip>
                                @foreach ($groups as $g)
                                    <a href="{{ route('student.chat.show', $g) }}"
                                       title="{{ $g->subject->name }} · {{ $g->name }}"
                                       @if ($g->id === $group->id) aria-current="page" @endif
                                       x-bind:aria-current="current === {{ $g->id }} ? 'page' : null"
                                       x-on:click="go($event, {{ $g->id }})"
                                       class="interactive flex h-9 shrink-0 items-center gap-1.5 rounded-pill bg-surface-muted px-4 text-meta font-bold text-ink-600 transition-colors duration-150 aria-[current=page]:bg-ink aria-[current=page]:text-white">
                                        {{ $g->subject->code }} <span class="font-semibold opacity-70">{{ $g->name }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        {{-- The pane that swaps: it fades out, the new one fades and rises in, and a light
                             placeholder appears only if that takes more than about 150ms. --}}
                        <div class="cs-pane relative flex min-h-0 flex-1 flex-col" x-ref="pane" x-bind:class="{ 'is-leaving': leaving }" data-pane>
                            <livewire:group-chat :group="$group" />

                            <div x-show="skeleton" x-cloak class="absolute inset-0 z-10 flex flex-col gap-4 bg-surface-soft/95 p-6" aria-hidden="true" data-skeleton>
                                <div class="cs-shimmer h-10 w-1/3 rounded-pill"></div>
                                <div class="cs-shimmer h-14 w-3/5 rounded-[18px]"></div>
                                <div class="cs-shimmer ml-auto h-12 w-2/5 rounded-[18px]"></div>
                                <div class="cs-shimmer h-16 w-1/2 rounded-[18px]"></div>
                                <div class="cs-shimmer ml-auto h-10 w-1/3 rounded-[18px]"></div>
                            </div>
                        </div>
                    </section>
                </div>
        </div>

    @endif

</x-layouts.app>
