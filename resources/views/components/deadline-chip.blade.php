@props([
    'subject',      // the project deadline comes from Subject::projectDeadline()
])

{{--
    The deadline as a small chip on a subject card: "Due 14 Nov · 12 days left". With several assessments it is the active
    one's: "Assignment 1 · Due 14 Nov" (a long title is cut, the tooltip has it all with the countdown). Shows nothing when
    there is no date. Warning colour when three days or fewer are left, the overdue colour once it has passed, otherwise
    neutral. Informational only: nothing is locked by it.
--}}
@php $deadline = $subject->projectDeadline(); @endphp
@if ($deadline)
    @php
        $days = $subject->daysToProjectDeadline();
        $label = $subject->projectDeadlineLabel();
        $date = $deadline->format('j M');
        $active = $subject->activeAssessment();
        $several = $active !== null && $subject->assessments->count() > 1;

        if ($several) {
            $text = \Illuminate\Support\Str::limit($active->title, 18, '…').' · Due '.$date;
            $tip = $active->title.' · Due '.$date.' · '.$label;
        } else {
            $text = str_starts_with($label, 'Due ') || str_starts_with($label, 'Overdue') ? $label.' · '.$date : 'Due '.$date.' · '.$label;
            $tip = null;
        }
    @endphp
    <span @class([
            'inline-flex w-fit max-w-full items-center gap-1.5 rounded-pill px-2.5 py-1 text-label font-bold',
            'bg-danger-bg text-danger-text' => $days < 0,
            'bg-warning-bg text-warning-text' => $days >= 0 && $days <= 3,
            'bg-white/70 text-ink-600' => $days > 3,
          ])
          @if ($tip) data-tooltip="{{ $tip }}" @endif
          data-deadline-chip>
        <x-icon name="calendar" size="12" class="shrink-0" /><span class="truncate">{{ $text }}</span>
    </span>
@endif