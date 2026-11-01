<x-layouts.app title="Import groups — CollabSpace"
               :heading="'Import groups — Section '.$section->name"
               :subtitle="$subject->code.' — '.$subject->name"
               :back="route('lecturer.formation', [$subject, 'section' => $section->id])"
               back-label="Auto-assign students">

    @php
        $layout = old('layout', \App\Support\Formation\ImportBuilder::LAYOUT_GROUP_ROWS);
    @endphp

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-[18px]">

        <x-flash />

        <form method="POST" action="{{ route('lecturer.formation.import.store', $subject) }}"
              enctype="multipart/form-data"
              class="flex flex-col gap-6 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle">
            @csrf
            <input type="hidden" name="section" value="{{ $section->id }}">

            <div class="flex flex-col gap-1">
                <h2 class="text-h3 font-bold tracking-title">Upload the form's responses</h2>
                <p class="text-body leading-[1.55] text-ink-400">
                    In Google Forms open <span class="font-semibold text-ink-600">Responses</span>, use the spreadsheet
                    menu, and choose <span class="font-semibold text-ink-600">Download as CSV</span>.
                    Nothing is created from this screen: you will check every row first.
                </p>
            </div>

            <fieldset class="flex flex-col gap-2.5">
                <legend class="mb-1 text-meta font-bold text-ink-700">How is the form laid out?</legend>

                <label class="flex cursor-pointer items-start gap-3 rounded-card border border-surface-line bg-surface p-4 transition-colors has-[:checked]:border-ink has-[:checked]:bg-surface-soft">
                    <input type="radio" name="layout" value="{{ \App\Support\Formation\ImportBuilder::LAYOUT_GROUP_ROWS }}"
                           @checked($layout === \App\Support\Formation\ImportBuilder::LAYOUT_GROUP_ROWS)
                           class="mt-1 size-4 shrink-0 border-surface-strong text-ink focus:ring-ink/20">
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="text-nav font-bold">One row per group</span>
                        <span class="text-meta leading-[1.5] text-ink-400">
                            One student fills it in for the whole group: a group name, then a column for each member's
                            matric number or email.
                        </span>
                        <a href="{{ route('lecturer.formation.import.template', [$subject, 'layout' => \App\Support\Formation\ImportBuilder::LAYOUT_GROUP_ROWS]) }}"
                           class="w-fit text-meta font-semibold text-periwinkle-link hover:underline">Download an example</a>
                    </span>
                </label>

                <label class="flex cursor-pointer items-start gap-3 rounded-card border border-surface-line bg-surface p-4 transition-colors has-[:checked]:border-ink has-[:checked]:bg-surface-soft">
                    <input type="radio" name="layout" value="{{ \App\Support\Formation\ImportBuilder::LAYOUT_STUDENT_ROWS }}"
                           @checked($layout === \App\Support\Formation\ImportBuilder::LAYOUT_STUDENT_ROWS)
                           class="mt-1 size-4 shrink-0 border-surface-strong text-ink focus:ring-ink/20">
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="text-nav font-bold">One row per student</span>
                        <span class="text-meta leading-[1.5] text-ink-400">
                            Every student fills it in themselves: their matric number and/or email, and the name of the
                            group they chose. Students who give the same group name end up together.
                        </span>
                        <a href="{{ route('lecturer.formation.import.template', [$subject, 'layout' => \App\Support\Formation\ImportBuilder::LAYOUT_STUDENT_ROWS]) }}"
                           class="w-fit text-meta font-semibold text-periwinkle-link hover:underline">Download an example</a>
                    </span>
                </label>
                <x-input-error :messages="$errors->get('layout')" />
            </fieldset>

            <div class="flex flex-col gap-2">
                <x-input-label for="file" value="CSV file" />
                <input id="file" name="file" type="file" accept=".csv,.txt,text/csv" required
                       class="block w-full rounded-[16px] border border-surface-line bg-surface p-3 text-meta text-ink-600
                              file:mr-4 file:rounded-pill file:border-0 file:bg-surface-muted file:px-4 file:py-2
                              file:text-meta file:font-bold file:text-ink hover:file:bg-surface-hover">
                <x-input-error :messages="$errors->get('file')" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit">Read the file</x-button>
                <x-button variant="secondary" :href="route('lecturer.formation', $subject)">Cancel</x-button>
            </div>
        </form>

        <div class="flex items-start gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-4">
            <x-icon name="shield" size="17" class="mt-0.5 shrink-0 text-ink-300" />
            <p class="text-meta leading-[1.55] text-ink-400">
                <span class="font-bold text-ink-600">How students are matched.</span>
                By matric number first, then by email. Never by name: two students can share one, and a name can be
                spelt a dozen ways, so a name on its own is never enough to put somebody in a group. Anyone who cannot
                be matched is listed for you to sort out by hand, and so is anyone who is not enrolled in section {{ $section->name }}: they are reported as not in this section.
            </p>
        </div>
    </div>

</x-layouts.app>
