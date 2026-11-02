<x-layouts.app title="Edit subject — CollabSpace"
               :heading="$subject->name"
               :back="route('admin.subjects')" back-label="Subjects">

    <x-slot:meta>
        <x-subject-chip :subject="$subject" />
        @foreach ($subject->sections as $section)
            <x-section-chip :section="$section" :code="$subject->code" />
        @endforeach
    </x-slot:meta>

    {{-- The same form as the buttons at its end: nothing to scroll to. --}}
    <x-slot:actions>
        <x-button type="submit" form="subject-form" data-save-header>Save changes</x-button>
        <x-button variant="secondary" :href="route('admin.subjects')">Cancel</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        <form id="subject-form" method="POST" action="{{ route('admin.subjects.update', $subject) }}" class="flex flex-col gap-[18px]">
            @csrf
            @method('PUT')

            @include('admin.subjects._form')

            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit">Save changes</x-button>
                <x-button variant="secondary" :href="route('admin.subjects')">Cancel</x-button>
            </div>
        </form>
    </div>

</x-layouts.app>
