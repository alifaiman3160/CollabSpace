<x-layouts.app title="Edit subject — CollabSpace"
               :heading="$subject->code"
               :subtitle="$subject->name"
               :back="route('lecturer.subjects')"
               back-label="My subjects">

    <div class="mx-auto flex w-full max-w-xl flex-col gap-7">

        <x-flash />

        <form method="POST" action="{{ route('lecturer.subjects.update', $subject) }}" class="flex flex-col gap-7">
            @csrf
            @method('PUT')

            @include('lecturer.subjects._form', ['subject' => $subject])

            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit">Save changes</x-button>
                <x-button variant="secondary" :href="route('lecturer.subjects')">Cancel</x-button>
            </div>
        </form>

        <section class="flex max-w-xl flex-col gap-3 rounded-panel border border-surface-line bg-surface shadow-subtle p-6">
            <h2 class="text-h3 font-bold tracking-title">Groups</h2>
            <p class="text-body text-ink-400">
                This subject has {{ $subject->groups_count }} {{ Str::plural('group', $subject->groups_count) }}.
            </p>
            <div>
                <x-button variant="secondary" :href="route('lecturer.groups', ['subject' => $subject->id])">
                    Manage its groups
                </x-button>
            </div>
        </section>
    </div>

</x-layouts.app>
