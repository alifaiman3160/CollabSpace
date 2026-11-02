<x-layouts.app title="New subject — CollabSpace"
               heading="New subject"
               subtitle="Give it to the lecturer who teaches it, and add its sections."
               :back="route('admin.subjects')" back-label="Subjects">

    {{-- The same form as the buttons at its end: nothing to scroll to. --}}
    <x-slot:actions>
        <x-button type="submit" form="subject-form" data-save-header>Create subject</x-button>
        <x-button variant="secondary" :href="route('admin.subjects')">Cancel</x-button>
    </x-slot:actions>

    <form id="subject-form" method="POST" action="{{ route('admin.subjects.store') }}" class="flex flex-col gap-[18px]">
        @csrf

        @include('admin.subjects._form')

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit">Create subject</x-button>
            <x-button variant="secondary" :href="route('admin.subjects')">Cancel</x-button>
        </div>
    </form>

</x-layouts.app>
