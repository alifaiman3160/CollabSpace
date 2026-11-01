@props([
    'groups',              // every group of the lecturer's (with ->subject), already loaded by the page
    'current',             // the group being looked at
    'route',               // route name each option goes to (the group is its parameter)
])

{{--
    The lecturer's way to hop between groups from a single group's page: a pill with the current group, a card of all
    groups under their subject, and previous / next arrows through the same order (subject code, then group name; no
    wrap-around). Real links, so a choice is an ordinary wire:navigate visit. Replaces the row of every group the page
    used to repeat. Alt+Left / Alt+Right are not used: browsers take them for Back / Forward.
--}}
@php
    $switch = \App\Support\GroupSwitcher::for($groups, $current, $route);
    $palette = \App\Support\SubjectPalette::for($current->subject_id);
    $arrow = 'flex size-9 shrink-0 items-center justify-center rounded-pill bg-surface text-ink-600 shadow-subtle interactive btn-motion focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-periwinkle-link';
@endphp

@if ($switch['count'] > 1)
    <div class="flex items-center gap-2" data-group-switcher x-data="groupSwitcher" x-on:keydown="key($event)" x-on:click.outside="close()">
        @if ($switch['previous'])
            <a href="{{ $switch['previous'] }}" wire:navigate class="{{ $arrow }}" aria-label="Previous group" data-tooltip="Previous group" data-switch-previous>
                <x-icon name="arrow-left" size="16" />
            </a>
        @else
            <span class="{{ $arrow }} pointer-events-none opacity-40" role="link" aria-disabled="true" aria-label="Previous group" data-switch-previous data-disabled>
                <x-icon name="arrow-left" size="16" />
            </span>
        @endif

        <div class="relative">
            <button type="button" x-ref="button" x-on:click="toggle()"
                    aria-haspopup="listbox" aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="group-switcher-card"
                    class="flex h-10 max-w-[16rem] items-center gap-2 rounded-pill border border-surface-line bg-surface px-3.5 text-meta font-bold interactive btn-motion focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-periwinkle-link"
                    data-switch-button>
                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $palette['accent'] }};" aria-hidden="true" data-switch-dot></span>
                <span class="min-w-0 truncate" data-tooltip="{{ $current->name }}">{{ $current->name }}</span>
                <x-icon name="chevron" size="14" class="shrink-0 rotate-90 text-ink-400" />
            </button>

            <div id="group-switcher-card" x-ref="card" x-show="open" style="display: none;"
                 class="cs-menu fixed z-40 flex max-h-[360px] w-[300px] flex-col overflow-hidden rounded-[14px] border border-surface-line bg-surface shadow-[0_12px_32px_rgba(17,17,20,0.10)]"
                 data-switch-card>
                @if ($switch['searchable'])
                    <div class="border-b border-surface-line p-2.5">
                        <label for="group-switcher-search" class="sr-only">Search groups</label>
                        <input id="group-switcher-search" type="search" x-ref="search" x-model="query" x-on:input="filter()"
                               placeholder="Search groups or subjects" autocomplete="off"
                               class="h-9 w-full rounded-pill border border-surface-line bg-surface-soft px-3.5 text-meta font-medium outline-none focus-visible:outline-2 focus-visible:outline-periwinkle-link"
                               data-switch-search>
                    </div>
                @endif

                <div class="cs-scroll flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto p-2" role="listbox" aria-label="Groups">
                    @foreach ($switch['sections'] as $section)
                        <div class="flex flex-col gap-0.5" data-switch-section role="group" aria-label="{{ $section['subject']->code }} {{ $section['subject']->name }}">
                            <div class="flex items-center gap-2 px-2 pb-0.5 pt-1.5">
                                <span class="shrink-0 rounded-pill px-2 py-px text-label font-extrabold text-ink" style="background-color: {{ $section['palette']['accent'] }};" data-switch-heading>{{ $section['subject']->code }}</span>
                                <span class="min-w-0 truncate text-label font-bold text-ink-400">{{ $section['subject']->name }}</span>
                            </div>

                            @foreach ($section['groups'] as $g)
                                @php($isCurrent = $g->is($current))
                                <div data-switch-item data-search="{{ $g->name }} {{ $section['subject']->code }} {{ $section['subject']->name }}{{ $g->relationLoaded('section') && $g->section ? ' '.$g->section->name : '' }}">
                                    <a href="{{ route($route, $g) }}" wire:navigate role="option" tabindex="-1"
                                       aria-selected="{{ $isCurrent ? 'true' : 'false' }}" @if ($isCurrent) aria-current="page" @endif
                                       class="flex min-h-9 items-center gap-2 rounded-[10px] px-2.5 py-1.5 text-meta font-semibold interactive focus-visible:outline-2 focus-visible:outline-periwinkle-link {{ $isCurrent ? 'bg-surface-muted' : 'hover:bg-surface-soft' }}"
                                       data-switch-option @if ($isCurrent) data-current @endif>
                                        <span class="min-w-0 flex-1 truncate">{{ $g->name }}</span>
                                        @if ($g->relationLoaded('section'))<x-section-chip :section="$g->section" :code="$section['subject']->code" compact class="shrink-0" />@endif
                                        @if ($isCurrent)
                                            <x-icon name="check" size="15" class="shrink-0" />
                                        @endif
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @endforeach

                    <p class="px-3 py-3 text-meta font-medium text-ink-400" x-show="empty" style="display: none;" data-switch-empty>No groups match</p>
                </div>
            </div>
        </div>

        @if ($switch['next'])
            <a href="{{ $switch['next'] }}" wire:navigate class="{{ $arrow }}" aria-label="Next group" data-tooltip="Next group" data-switch-next>
                <x-icon name="arrow-right" size="16" />
            </a>
        @else
            <span class="{{ $arrow }} pointer-events-none opacity-40" role="link" aria-disabled="true" aria-label="Next group" data-switch-next data-disabled>
                <x-icon name="arrow-right" size="16" />
            </span>
        @endif
    </div>
@endif
