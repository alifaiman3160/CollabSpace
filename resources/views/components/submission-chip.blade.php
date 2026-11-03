@props([
    'submission' => null,   // the latest Submission of the group for the assessment, or null
    'long' => false,        // the words for a card ("Submitted on time") rather than a list ("Submitted")
])

@php
    $status = \App\Models\Submission::statusOf($submission);
    $words = $long
        ? ['submitted' => 'Submitted on time', 'late' => 'Submitted late', 'withdrawn' => 'Withdrawn', 'none' => 'Not submitted']
        : ['submitted' => 'Submitted', 'late' => 'Late', 'withdrawn' => 'Withdrawn', 'none' => 'Not submitted'];
    $tone = ['submitted' => 'success', 'late' => 'warning', 'withdrawn' => 'neutral', 'none' => 'neutral'][$status];
@endphp

{{-- A group's submission state for an assessment, as a small pill. Display only. --}}
<x-table.dot-pill :tone="$tone" {{ $attributes->class('shrink-0') }} data-submission-chip="{{ $status }}">{{ $words[$status] }}</x-table.dot-pill>
