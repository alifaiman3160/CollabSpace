<x-layouts.app title="Edit subject — CollabSpace"
               :heading="$subject->name"
               :back="route('lecturer.subjects')"
               back-label="My subjects">

    {{-- The subject's code and sections, under the title. --}}
    <x-slot:meta>
        <x-subject-chip :subject="$subject" />
        @foreach ($subject->sections as $section)
            <x-section-chip :section="$section" :code="$subject->code" />
        @endforeach
    </x-slot:meta>

    {{-- The same form as the Save button at the end of the settings card: nothing to scroll to. --}}
    <x-slot:actions>
        <x-button type="submit" form="subject-form" data-save-header>Save changes</x-button>
        <x-button variant="secondary" :href="route('lecturer.subjects')">Cancel</x-button>
    </x-slot:actions>

    {{-- One full-width stack: the summary, the subject's settings, then the assessments. --}}
    <div class="flex flex-col gap-[18px]" data-edit-stack>

        <x-flash />

        @include('lecturer.subjects._summary', ['subject' => $subject, 'summary' => $summary])

        <form id="subject-form" method="POST" action="{{ route('lecturer.subjects.update', $subject) }}" class="flex flex-col">
            @csrf
            @method('PUT')

            @include('lecturer.subjects._form', ['subject' => $subject])
        </form>

        @include('lecturer.subjects._assessments', ['subject' => $subject])
    </div>

</x-layouts.app>
