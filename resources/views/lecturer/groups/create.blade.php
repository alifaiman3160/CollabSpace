<x-layouts.app title="Add one group — CollabSpace"
               heading="Add one group"
               subtitle="The size is suggested from the subject's default, and you can override it.">

    @php
        // Feeds the Alpine size suggestion: subject id => its default size.
        $defaults = $subjects->pluck('group_size', 'id');
    @endphp

    <form method="POST" action="{{ route('lecturer.groups.store') }}" class="mx-auto flex w-full max-w-xl flex-col gap-7"
          x-data="{
              defaults: {{ Js::from($defaults) }},
              subject: '{{ old('subject_id', $selected) }}',
              size: '{{ old('group_size', $subjects->firstWhere('id', $selected)?->group_size ?? 4) }}',
              touched: {{ old('group_size') ? 'true' : 'false' }},
              suggest() {
                  // Follow the subject's default until the lecturer types a size.
                  if (! this.touched) { this.size = this.defaults[this.subject] ?? this.size }
              },
          }">
        @csrf

        <div class="flex max-w-xl flex-col gap-5 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle">

            <div class="flex flex-col gap-2">
                <x-input-label for="subject_id" value="Subject" />
                <select id="subject_id" name="subject_id" x-model="subject" x-on:change="suggest()"
                        class="h-[52px] w-full rounded-[16px] bg-surface border border-surface-line px-[18px] text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                    @foreach ($subjects as $subject)
                        <option value="{{ $subject->id }}">{{ $subject->code }} — {{ $subject->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('subject_id')" />
            </div>

            <div class="flex flex-col gap-2">
                <x-input-label for="name" value="Group name" />
                <x-text-input id="name" name="name" type="text" required autofocus
                              :value="old('name')"
                              placeholder="Group A — CollabSpace" />
                <x-input-error :messages="$errors->get('name')" />
            </div>

            <div class="flex flex-col gap-2">
                <x-input-label for="group_size" value="Group size" />
                <x-text-input id="group_size" name="group_size" type="number" min="2" max="10" required
                              x-model="size" x-on:input="touched = true"
                              class="max-w-[140px]" />
                <p class="text-meta text-ink-400">
                    Suggested from the subject's default
                    (<span x-text="defaults[subject] ?? '—'"></span>). Assigning more members than
                    this is blocked.
                </p>
                <x-input-error :messages="$errors->get('group_size')" />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit">Create group</x-button>
            <x-button variant="secondary" :href="route('lecturer.groups')">Cancel</x-button>
        </div>
    </form>

</x-layouts.app>
