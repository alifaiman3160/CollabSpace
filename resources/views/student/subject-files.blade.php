<x-layouts.app title="Files — CollabSpace"
               heading="Files"
               :subtitle="$subject->code.' — '.$subject->name"
               :back="route('student.dashboard')" back-label="Dashboard">

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-[18px]">

        <x-flash />

        @if ($files->isEmpty())
            <x-empty-state class="!border-solid !border-surface-line" icon="file" title="No files yet"
                           body="Your lecturer has not attached any files to this subject." />
        @else
            <section class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle" aria-label="Files">
                <p class="text-meta text-ink-400">From your lecturer. Read only.</p>

                @foreach ($groups as $group)
                    <div class="flex flex-col gap-2" data-files-group="{{ $group['title'] }}">
                        <h2 class="text-label font-bold uppercase tracking-eyebrow text-ink-400">{{ $group['title'] }}</h2>

                        <ul class="flex flex-col gap-2" data-files-list>
                            @foreach ($group['files'] as $file)
                                <x-subject-file-row :file="$file" :scope="$group['title'] === 'Course materials' && $subject->assessments->count() > 1 ? $file->audienceLabel() : null" />
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </section>
        @endif
    </div>

</x-layouts.app>
