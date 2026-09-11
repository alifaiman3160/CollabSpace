@props(['role'])

@php
    $tones = ['admin' => 'dark', 'lecturer' => 'info', 'student' => 'neutral'];
    $labels = ['admin' => 'Administrator', 'lecturer' => 'Lecturer', 'student' => 'Student'];
@endphp

<x-badge :tone="$tones[$role] ?? 'neutral'">{{ $labels[$role] ?? ucfirst($role) }}</x-badge>
