@props([
    'name',                  // the form field: sections[0][criteria][1][evidence_type]
    'selected' => null,      // the saved or posted evidence type
    'criterionName' => '',   // the criterion's wording, which the advice reads
    'mode' => 'suggested',   // suggested | manual
    'idPrefix' => 'evidence',
    // On the review screen the criterion card owns `name` and `cmode` (so typing
    // in them updates the advice). On a saved rubric nothing does, so the control
    // carries them itself.
    'standalone' => false,
    'disabled' => false,
])

@php
    $selected = in_array($selected, \App\Support\Marking\EvidenceType::ALL, true) ? $selected : '';
    $options = ['' => 'Not set (treated as none)'] + array_combine(\App\Support\Marking\EvidenceType::ALL, array_map(\App\Support\Marking\EvidenceType::label(...), \App\Support\Marking\EvidenceType::ALL));
    $hint = \App\Support\Marking\EvidenceType::hint($criterionName, $mode, $selected);
    $isDefault = $selected !== '' && $selected === \App\Support\Marking\EvidenceType::guess($criterionName);
@endphp

{{--
    What recorded activity a system-suggested criterion looks at, kept small so a
    criterion's row is only as tall as its performance levels.

    A select, with one line under it describing the option chosen now. The amber
    hint is advice from the wording and never prevents a choice; it is rendered
    for the first paint and then kept up to date by evidencePicker as the
    selection changes. The note about Manual is not here: it is said once per
    section, above the criteria.
--}}
<div x-data="evidencePicker(@js([
         'ev' => $selected,
         'rules' => \App\Support\Marking\EvidenceType::hintConfig(),
         'standalone' => $standalone,
         'name' => $criterionName,
         'cmode' => $mode,
     ]))"
     class="flex min-w-0 flex-col gap-1.5">

    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
        <label for="{{ $idPrefix }}" class="text-label font-bold uppercase tracking-eyebrow text-ink-300">Evidence</label>

        <select id="{{ $idPrefix }}" name="{{ $name }}" x-model="ev" @disabled($disabled)
                class="w-full min-w-0 rounded-[12px] border border-surface-line bg-surface px-3 py-2 text-meta text-ink focus:border-ink focus:ring-0 sm:w-auto sm:min-w-[14rem]">
            @foreach ($options as $value => $label)
                <option value="{{ $value }}" @selected($selected === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <span class="text-label leading-[1.4] text-ink-300 {{ $isDefault ? '' : 'hidden' }}"
              :class="{ hidden: ! isDefault(name, ev) }" data-evidence-default>
            A default chosen from the wording. Change it if it is not right.
        </span>
    </div>

    {{-- One line, for the option chosen now. --}}
    <p class="text-label leading-[1.4] text-ink-400" data-evidence-description
       x-text="describe(ev)">{{ \App\Support\Marking\EvidenceType::description($selected) }}</p>

    <p data-evidence-hint role="status"
       class="flex items-start gap-2 rounded-[12px] bg-warning-bg px-3 py-2 text-meta font-semibold leading-[1.45] text-warning-text {{ $hint ? '' : 'hidden' }}"
       :class="{ hidden: ! hintFor(name, cmode, ev) }">
        <x-icon name="alert" size="15" class="mt-0.5 shrink-0" />
        <span x-text="hintFor(name, cmode, ev)">{{ $hint }}</span>
    </p>
</div>
