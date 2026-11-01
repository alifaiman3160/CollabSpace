<x-layouts.app :title="'Announcements — '.$subject->code.' — CollabSpace'"
               heading="Announcements"
               :subtitle="$subject->code.' — '.$subject->name"
               :back="route('lecturer.subjects')"
               back-label="My subjects">

    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        {{-- Write one, or change one. The counter and the busy flag keep a double click from posting twice. --}}
        <form method="POST"
              action="{{ $editing ? route('lecturer.announcements.update', [$subject, $editing]) : route('lecturer.announcements.store', $subject) }}"
              class="flex flex-col gap-4 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle"
              x-data="{ busy: false, count: {{ mb_strlen(old('body', $editing?->body ?? '')) }} }"
              x-on:submit="if (busy) { $event.preventDefault() } else { busy = true }"
              data-announcement-form>
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <h2 class="text-nav font-bold">{{ $editing ? 'Edit announcement' : 'New announcement' }}</h2>

            <div class="flex flex-col gap-2">
                <x-input-label for="title" value="Title" />
                <x-text-input id="title" name="title" type="text" maxlength="150" required
                              :value="old('title', $editing?->title)" placeholder="For example: Report deadline moved" />
                <x-input-error :messages="$errors->get('title')" />
            </div>

            {{-- Aimed at one section, or at all of them. Only offered when the subject has several. --}}
            @if ($sections->count() > 1)
                <div class="flex flex-col gap-2" data-announcement-section>
                    <x-input-label for="section_id" value="Send to section" />
                    <select id="section_id" name="section_id"
                            class="h-[52px] w-full max-w-xs rounded-[16px] bg-surface border border-surface-line px-[18px] text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                        <option value="">All sections</option>
                        @foreach ($sections as $option)
                            <option value="{{ $option->id }}" @selected((string) old('section_id', $editing?->section_id) === (string) $option->id)>Section {{ $option->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('section_id')" />
                </div>
            @endif

            <div class="flex flex-col gap-2">
                <x-input-label for="body" value="Message" />
                <textarea id="body" name="body" rows="5" maxlength="2000" required x-on:input="count = $event.target.value.length"
                          placeholder="What do your students need to know?"
                          class="w-full rounded-[16px] border border-surface-line bg-surface px-[18px] py-3.5 text-[14px] font-medium text-ink placeholder:text-ink-300 focus:outline-none focus:ring-2 focus:ring-ink/10">{{ old('body', $editing?->body) }}</textarea>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-label text-ink-400">Plain text only. Line breaks are kept.</span>
                    <span class="text-label font-semibold tabular-nums text-ink-400" data-counter><span x-text="count">0</span> / 2000</span>
                </div>
                <x-input-error :messages="$errors->get('body')" />
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <x-button type="submit" x-bind:disabled="busy" data-post>{{ $editing ? 'Save changes' : 'Post' }}</x-button>
                @if ($editing)
                    <x-button variant="secondary" :href="route('lecturer.announcements', $subject)">Cancel</x-button>
                    <span class="text-meta text-ink-400">Students are not notified again when you edit.</span>
                @else
                    <span class="text-meta text-ink-400">Each student in this subject gets one notification.</span>
                @endif
            </div>
        </form>

        {{-- Newest first. --}}
        <section class="flex flex-col gap-3" aria-label="Posted announcements">
            @forelse ($announcements as $announcement)
                <article class="flex flex-col gap-2 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle" data-announcement>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <h3 class="flex flex-wrap items-center gap-2 text-nav font-bold">{{ $announcement->title }}
                            @if ($announcement->section)<x-section-chip :section="$announcement->section" :code="$subject->code" />@endif</h3>
                        <span class="text-label text-ink-400" title="{{ $announcement->created_at->format('j F Y, g:ia') }}">{{ $announcement->created_at->diffForHumans() }}</span>
                    </div>

                    <p class="whitespace-pre-line break-words text-body leading-[1.6] text-ink-600">{{ $announcement->body }}</p>

                    <p class="text-label font-semibold text-ink-400" data-opened-by>Opened by {{ $opened[$announcement->id] ?? 0 }} of {{ $audiences[$announcement->id] ?? $audience }}</p>

                    <div class="flex flex-wrap items-center gap-2.5">
                        <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                  :href="route('lecturer.announcements.edit', [$subject, $announcement])">Edit</x-button>
                        <x-table.quiet-action icon="trash" aria-label="Delete {{ $announcement->title }}"
                            x-on:click="confirm = {
                                url: '{{ route('lecturer.announcements.destroy', [$subject, $announcement]) }}',
                                method: 'DELETE',
                                title: 'Delete this announcement?',
                                body: {{ Js::from('Students will no longer see it, and the notifications about it are removed.') }},
                                action: 'Delete announcement',
                                tone: 'danger',
                            }">Delete</x-table.quiet-action>
                    </div>
                </article>
            @empty
                <x-empty-state icon="bell" title="Nothing posted yet"
                               body="Write your first announcement above. Your students will see it on their dashboard." />
            @endforelse
        </section>

        <x-confirm-dialog />
    </div>

</x-layouts.app>
