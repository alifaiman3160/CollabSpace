<x-layouts.app title="Dashboard — CollabSpace"
               heading="Good morning, Alif"
               subtitle="You have 3 tasks due this week across 2 groups.">

    <x-slot:actions>
        <x-button variant="icon" aria-label="Notifications">
            <x-icon name="bell" />
        </x-button>
        <x-button icon="plus">New task</x-button>
    </x-slot:actions>

    {{-- Hero + stats --}}
    <section class="grid gap-5 lg:grid-cols-3">
        <div class="rounded-panel bg-periwinkle p-7 lg:col-span-2">
            <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">This semester</span>
            <p class="mt-3 text-hero font-extrabold tracking-stat">68%</p>
            <p class="mt-1 text-body font-medium text-ink/[.66]">17 of 25 tasks completed</p>
            <x-progress-bar :done="17" :doing="4" :total="25" on="periwinkle" class="mt-5" />
        </div>

        <div class="grid gap-5">
            <x-stat-card label="Overdue" value="3" meta="Across 2 subjects" tone="plain" icon="alert" />
            <x-stat-card label="Due soon" value="5" meta="Next 7 days" tone="dark" icon="clock" />
        </div>
    </section>

    {{-- Tasks table --}}
    <section class="mt-8">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-h3 font-bold tracking-title">Upcoming tasks</h2>
            <a href="#" class="text-meta font-bold text-ink-400 transition-colors hover:text-periwinkle-link">View all</a>
        </div>

        <x-data-table :headings="['Task', 'Subject', 'Group', 'Due', 'Status']">
            @foreach([
                ['Literature review draft', 'SEC2103', 'Team Nimbus', '12 Sep', 'danger', 'Overdue'],
                ['System sequence diagram', 'SEC2203', 'Team Orbit',  '15 Sep', 'neutral', 'In progress'],
                ['Sprint 2 retrospective',  'SEC2103', 'Team Nimbus', '18 Sep', 'success', 'Done'],
            ] as [$task, $subject, $group, $due, $tone, $status])
                <tr class="transition-colors hover:bg-surface-soft">
                    <td class="text-nav font-semibold">{{ $task }}</td>
                    <td><x-badge tone="info">{{ $subject }}</x-badge></td>
                    <td>
                        <span class="flex items-center gap-2">
                            <x-avatar :name="$group" size="sm" />
                            <span class="text-body">{{ $group }}</span>
                        </span>
                    </td>
                    <td class="text-meta font-medium text-ink-400">{{ $due }}</td>
                    <td><x-badge :tone="$tone">{{ $status }}</x-badge></td>
                </tr>
            @endforeach
        </x-data-table>
    </section>

    {{-- Empty state example --}}
    <section class="mt-8">
        <h2 class="mb-4 text-h3 font-bold tracking-title">Documents</h2>
        <x-empty-state
            icon="file"
            title="No documents yet"
            body="Documents your group creates will show up here, newest first."
            cta="Create a document"
            href="#" />
    </section>

    {{-- Floating add-task button --}}
    <a href="#" aria-label="Add task"
       class="fixed bottom-8 right-8 inline-flex size-[52px] items-center justify-center rounded-pill bg-ink text-white shadow-button transition-colors hover:bg-ink-hover">
        <x-icon name="plus" size="20" />
    </a>
</x-layouts.app>
