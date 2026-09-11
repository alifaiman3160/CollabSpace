<x-layouts.app title="New subject — CollabSpace"
               heading="Create subject"
               subtitle="Groups and students are added to the subject once it exists.">

    <form method="POST" action="{{ route('lecturer.subjects.store') }}" class="flex flex-col gap-7">
        @csrf

        @include('lecturer.subjects._form', ['subject' => null])

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit">Create subject</x-button>
            <x-button variant="secondary" :href="route('lecturer.subjects')">Cancel</x-button>
        </div>
    </form>

</x-layouts.app>
