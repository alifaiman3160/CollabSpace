@props([
    'subject',      // needs project_deadline
])

{{--
    The project deadline as a small chip on a subject card: "Due 14 Nov · 12 days left". Shows nothing when the subject
    has no deadline. Warning colour when three days or fewer are left, the overdue colour once it has passed, otherwise
    neutral. Informational only: nothing is locked by it.
--}}
@if ($subject->project_deadline)
    @php
        $days = $subject->daysToProjectDeadline();
        $label = $subject->projectDeadlineLabel();
        $date = $subject->project_deadline->format('j M');
        $text = str_starts_with($label, 'Due ') || str_starts_with($label, 'Overdue') ? $label.' · '.$date : 'Due '.$date.' · '.$label;
    @endphp
    <span @class([
            'inline-flex w-fit items-center gap-1.5 rounded-pill px-2.5 py-1 text-label font-bold',
            'bg-danger-bg text-danger-text' => $days < 0,
            'bg-warning-bg text-warning-text' => $days >= 0 && $days <= 3,
            'bg-white/70 text-ink-600' => $days > 3,
          ])
          data-deadline-chip>
        <x-icon name="calendar" size="12" class="shrink-0" />{{ $text }}
    </span>
@endif
