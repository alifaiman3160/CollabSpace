{{--
    The summary strip under the header: who the subject is (read-only, the administrator's) and how it stands, with
    quick links to the pages that already exist for it. Expects $subject (lecturer and sections loaded) and $summary.
--}}
@php
    $tiles = [
        ['key' => 'groups', 'label' => 'Groups', 'value' => $summary['groups'], 'warn' => false],
        ['key' => 'students', 'label' => 'Enrolled students', 'value' => $summary['students'], 'warn' => false],
        ['key' => 'ungrouped', 'label' => 'Without a group', 'value' => $summary['ungrouped'], 'warn' => $summary['ungrouped'] > 0],
        ['key' => 'assessments', 'label' => 'Assessments', 'value' => $summary['assessments'], 'warn' => false],
    ];
@endphp

<section class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface p-4 shadow-subtle" aria-label="Subject summary" data-subject-summary>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1.5" data-subject-readonly>
            <x-subject-chip :subject="$subject" />
            <span class="min-w-0 text-nav font-bold">{{ $subject->name }}</span>
            @if ($subject->lecturer)
                <span class="text-meta font-semibold text-ink-400" data-subject-lecturer>{{ $subject->lecturer->name }}</span>
            @endif
            @if ($subject->sections->isNotEmpty())
                <span class="flex flex-wrap items-center gap-1.5" data-subject-sections>
                    @foreach ($subject->sections as $section)
                        <x-section-chip :section="$section" :code="$subject->code" />
                    @endforeach
                </span>
            @endif
            <span class="text-meta text-ink-300">Managed by the administrator.</span>
        </div>

        <div class="flex flex-wrap items-center gap-2" data-subject-links>
            <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.groups', ['subject' => $subject->id])">Groups</x-button>
            <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.classlist', $subject)">Class list</x-button>
            <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.marking', ['subject' => $subject->id])">Marking</x-button>
            <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.reports', ['subject' => $subject->id])">Contribution</x-button>
        </div>
    </div>

    <div class="flex flex-wrap gap-2.5" data-subject-stats>
        @foreach ($tiles as $tile)
            <div class="flex min-w-[130px] flex-1 flex-col gap-0.5 rounded-card border px-3.5 py-2.5 {{ $tile['warn'] ? 'border-warning-bg bg-warning-bg text-warning-text' : 'border-surface-line bg-surface-soft' }}" data-stat="{{ $tile['key'] }}">
                <span class="text-label font-bold uppercase tracking-eyebrow {{ $tile['warn'] ? '' : 'text-ink-400' }}">{{ $tile['label'] }}</span>
                <span class="text-h2 font-extrabold leading-[1.1] tracking-stat">{{ $tile['value'] }}</span>
            </div>
        @endforeach
    </div>

    @if (count($summary['sections']) >= 2)
        <p class="text-meta font-medium text-ink-600" data-section-summary>
            {{ collect($summary['sections'])->map(fn ($c) => 'Section '.$c['name'].': '.$c['students'].' '.Str::plural('student', $c['students']).', '.$c['groups'].' '.Str::plural('group', $c['groups']))->implode(' · ') }}
        </p>
    @endif
</section>
