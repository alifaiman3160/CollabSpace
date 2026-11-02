@props([
    'subject',      // needs semester_start_date
])

{{--
    "Week 5": the current teaching week of the subject, counted from the semester start date its lecturer entered.
    A small neutral chip, display only. Nothing at all when there is no start date, the semester has not begun, or it
    is over (see Subject::currentWeek).
--}}
@php($week = $subject->currentWeek())
@if ($week !== null)
    <span {{ $attributes->class('inline-flex w-fit shrink-0 items-center whitespace-nowrap rounded-pill bg-white/70 px-2.5 py-0.5 text-label font-bold text-ink-600') }}
          data-week-chip
          data-tooltip="Week {{ $week }} of the semester, counted from {{ $subject->semester_start_date->format('j M Y') }}">Week {{ $week }}</span>
@endif
