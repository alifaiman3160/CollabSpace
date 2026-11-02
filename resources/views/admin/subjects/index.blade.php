<x-layouts.app title="Subjects — CollabSpace"
               heading="Subjects"
               :subtitle="$subjects->count().' '.Str::plural('subject', $subjects->count()).' '.($search !== '' || $lecturerId ? 'match the current filter.' : 'in CollabSpace.')">

    <x-slot:actions>
        <x-button :href="route('admin.subjects.create')" icon="plus">New subject</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        <form method="GET" action="{{ route('admin.subjects') }}" class="flex flex-wrap items-center gap-3">
            <label class="flex h-11 min-w-[260px] flex-1 items-center gap-2.5 rounded-pill bg-surface-muted px-[18px]">
                <x-icon name="search" size="17" class="text-ink-300" />
                <input type="search" name="search" value="{{ $search }}"
                       placeholder="Search code, name or lecturer"
                       class="w-full min-w-0 border-none bg-transparent p-0 text-body text-ink outline-none placeholder:text-ink-300 focus:ring-0">
            </label>

            <select name="lecturer" aria-label="Filter by lecturer" x-on:change="$el.form.requestSubmit()"
                    class="h-11 max-w-[260px] rounded-pill border-none bg-surface-muted px-4 text-meta font-bold text-ink-600 focus:ring-0">
                <option value="">All lecturers</option>
                @foreach ($lecturers as $lecturer)
                    <option value="{{ $lecturer->id }}" @selected($lecturerId === $lecturer->id)>{{ $lecturer->name }}</option>
                @endforeach
            </select>

            <x-button type="submit" variant="secondary">Search</x-button>

            @if ($search !== '' || $lecturerId)
                <x-button variant="secondary" :href="route('admin.subjects')">Clear</x-button>
            @endif
        </form>

        @if ($subjects->isEmpty())
            @if ($search !== '' || $lecturerId)
                <x-empty-state class="!border-solid !border-surface-line" icon="book" title="No subjects match that"
                               body="Try another search, or clear the filter." />
            @else
                <x-empty-state class="!border-solid !border-surface-line" icon="book" title="No subjects yet"
                               body="Create a subject, give it to the lecturer who teaches it, and add its sections."
                               cta="New subject" :href="route('admin.subjects.create')" />
            @endif
        @else
            <section class="grid grid-cols-[repeat(auto-fill,minmax(22rem,1fr))] gap-[18px]" aria-label="Subjects" data-subject-grid>
                @foreach ($subjects as $i => $subject)
                    @php
                        $deleteBody = "Deleting {$subject->code} removes the subject, its sections and its announcements. This cannot be undone.";
                        $blocked = $subject->groups_count > 0 || $subject->enrolments_count > 0;
                    @endphp

                    <x-subject-card :subject="$subject" :i="$i" :href="route('admin.subjects.edit', $subject)"
                        :stats="[
                            ['label' => 'Students enrolled', 'value' => $subject->enrolments_count],
                            ['label' => 'Groups', 'value' => $subject->groups_count],
                        ]">
                        <p class="truncate text-meta font-semibold text-ink-600" data-subject-lecturer title="{{ $subject->lecturer?->email }}">
                            {{ $subject->lecturer?->name ?? 'No lecturer' }}
                        </p>

                        <div class="mt-1 flex flex-wrap items-center gap-1.5" data-subject-sections>
                            @forelse ($subject->sections as $section)
                                <x-section-chip :section="$section" :code="$subject->code" />
                            @empty
                                <span class="text-label font-medium text-ink-400">No sections yet</span>
                            @endforelse
                        </div>

                        <x-slot:footer>
                            <x-button :href="route('admin.subjects.edit', $subject)" class="!px-4 !py-2 !text-meta">Edit</x-button>

                            <x-table.quiet-action icon="trash" class="ml-auto"
                                aria-label="Delete {{ $subject->code }}"
                                x-on:click="confirm = {
                                    url: '{{ route('admin.subjects.destroy', $subject) }}',
                                    method: 'DELETE',
                                    title: 'Delete this subject?',
                                    body: {{ Js::from($deleteBody) }},
                                    confirmText: {{ Js::from($subject->code) }},
                                    action: 'Delete subject',
                                    tone: 'danger',
                                }">Delete</x-table.quiet-action>
                        </x-slot:footer>
                    </x-subject-card>
                @endforeach
            </section>
        @endif

        <x-confirm-dialog />
    </div>

</x-layouts.app>
