{{-- Subjects the student is enrolled in (by the administrator) but has no group in yet: the same card as a group's,
     without the workspace link, with its section, the project deadline and, only when there is one, the subject's
     announcements. Cards only: the page puts them in its grid. Expects $waiting (Enrolment with subject and section),
     $announcements, $readAnnouncementIds. --}}
@php
    $announcementsBySubject = $announcements->groupBy('subject_id');
    $readIds = collect($readAnnouncementIds);
@endphp
@foreach ($waiting as $enrolment)
    @php
        $subject = $enrolment->subject;
        $items = $announcementsBySubject->get($subject->id, collect());
        $unreadIds = $items->pluck('id')->reject(fn ($id) => $readIds->contains($id))->values();
        $colour = \App\Support\SubjectPalette::for($subject->id);
    @endphp
    <div class="flex min-w-0 flex-col rounded-panel border text-ink"
         style="background-color: {{ $colour['bg'] }}; border-color: {{ $colour['border'] }};"
         data-subject-card data-waiting data-palette="{{ $colour['name'] }}">
        <div class="flex flex-1 flex-col justify-between gap-4 rounded-panel p-5">
            <span class="flex flex-wrap items-start justify-between gap-x-2 gap-y-1.5">
                <span class="flex min-w-0 items-center gap-1.5">
                    <span class="rounded-pill px-2.5 py-0.5 text-nav font-extrabold tracking-[-0.02em]" style="background-color: {{ $colour['accent'] }};">{{ $subject->code }}</span>
                    <x-section-chip :section="$enrolment->section" :code="$subject->code" />
                </span>
                <span class="ml-auto"><x-deadline-chip :subject="$subject" /></span>
            </span>

            <span class="flex flex-col gap-1">
                <span class="truncate text-label font-semibold text-ink-600">{{ $subject->name }}</span>
                <span class="text-h3 font-extrabold leading-[1.1] tracking-title">No group yet</span>
            </span>

            <span class="text-label font-semibold text-ink-600">Your lecturer will place you in a group of your section.</span>
        </div>

        <x-announcement-strip :items="$items" :colour="$colour" :suffix="'s'.$subject->id" :subject-id="$subject->id"
                              :unread-ids="$unreadIds" :read-url="route('student.announcements.read')" />
    </div>
@endforeach
