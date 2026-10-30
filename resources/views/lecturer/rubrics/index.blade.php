<x-layouts.app title="Rubrics — CollabSpace"
               heading="Marking rubric"
               :subtitle="$subject->code.' — '.$subject->name"
               :back="route('lecturer.subjects')"
               back-label="My subjects">

    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        {{-- Upload --}}
        <form method="POST" action="{{ route('lecturer.rubrics.store', $subject) }}" enctype="multipart/form-data"
              class="flex flex-col gap-4 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle">
            @csrf

            <div class="flex flex-col gap-1">
                <h2 class="text-h3 font-bold tracking-title">
                    {{ $rubrics->isEmpty() ? 'Upload a rubric' : 'Upload a new version' }}
                </h2>
                <p class="text-body leading-[1.55] text-ink-400">
                    An .xlsx file with one or more tables, each with a header row of performance levels such as
                    “Poor (1)” and “Excellent (4–5)”. You review everything it contains before it is saved.
                    @if ($rubrics->isNotEmpty())
                        A new version never changes the earlier ones.
                    @endif
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <x-input-label for="file" value="Rubric file (.xlsx)" />
                <input id="file" name="file" type="file" accept=".xlsx" required
                       class="block w-full rounded-[16px] border border-surface-line bg-surface p-3 text-meta text-ink-600
                              file:mr-4 file:rounded-pill file:border-0 file:bg-surface-muted file:px-4 file:py-2
                              file:text-meta file:font-bold file:text-ink hover:file:bg-surface-hover">
                <x-input-error :messages="$errors->get('file')" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit" icon="file">Read the file</x-button>
                <span class="text-meta text-ink-400">Nothing is saved until you confirm.</span>
            </div>
        </form>

        {{-- Saved versions --}}
        @if ($rubrics->isNotEmpty())
            <section class="flex flex-col gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Saved versions</h2>

                <x-data-table :headings="['Version', ['Sections', 'right'], ['Criteria', 'right'], 'Saved', '']" min-width="680px">
                    @foreach ($rubrics as $rubric)
                        @php
                            $deleteBody = "Version {$rubric->version} of the rubric for {$subject->code} is removed. "
                                .'Other versions are not affected.';
                        @endphp
                        <tr>
                            <td>
                                <x-table.stack :href="route('lecturer.rubrics.show', [$subject, $rubric])">
                                    <x-slot:primary>Version {{ $rubric->version }}</x-slot:primary>
                                    <span class="truncate">{{ $rubric->name }}</span>
                                    @if ($rubric->is_current)
                                        <x-table.dot-pill tone="success">Current</x-table.dot-pill>
                                    @endif
                                </x-table.stack>
                            </td>

                            <td class="num text-meta font-semibold text-ink-400">{{ $rubric->sections_count }}</td>
                            <td class="num text-meta font-semibold text-ink-400">{{ $rubric->criteria_count }}</td>

                            <td>
                                <x-table.stack>
                                    <x-slot:primary><span class="text-meta font-medium text-ink-600">{{ $rubric->created_at->format('j M Y') }}</span></x-slot:primary>
                                    @if ($rubric->creator)<span class="truncate">{{ $rubric->creator->name }}</span>@endif
                                </x-table.stack>
                            </td>

                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    @unless ($rubric->isLocked())
                                        <x-table.quiet-action icon="trash" aria-label="Delete version {{ $rubric->version }}"
                                            x-on:click="confirm = {
                                                url: '{{ route('lecturer.rubrics.destroy', [$subject, $rubric]) }}',
                                                method: 'DELETE',
                                                title: 'Delete this version?',
                                                body: {{ Js::from($deleteBody) }},
                                                action: 'Delete version',
                                                tone: 'danger',
                                            }">Delete</x-table.quiet-action>
                                    @endunless

                                    <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                              :href="route('lecturer.rubrics.show', [$subject, $rubric])">View</x-button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </section>
        @endif

        <x-confirm-dialog />
    </div>

</x-layouts.app>
