@props([
    'name' => null,   // the leader's name, for the tooltip
])

{{-- "Leader": a star and the word, in the small chip style used for sections and assessments. Display only. --}}
<span {{ $attributes->class('inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-pill bg-warning-bg px-2 py-px text-label font-bold text-warning-text') }}
      data-leader-badge data-tooltip="{{ $name ? 'Leader: '.$name : 'Group leader' }}"><x-icon name="star" size="11" class="shrink-0" />Leader</span>
