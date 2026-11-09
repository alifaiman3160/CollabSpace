<x-layouts.app title="Check the columns — CollabSpace"
               :heading="'Check the columns'.($section ? ' — Section '.$section->name : '')"
               :subtitle="$subject->code.' — '.$held['filename']"
               :back="route('lecturer.formation.import', $subject)"
               back-label="Upload a different file">

    @php
        $perStudent = $held['layout'] === \App\Support\Formation\ImportBuilder::LAYOUT_STUDENT_ROWS;
        $memberLabel = $perStudent ? 'Student (matric no. or email)' : 'Member (matric no. or email)';
        $roles = old('roles', $held['roles']);
    @endphp

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        <div class="flex items-start gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-4">
            <x-icon name="alert" size="17" class="mt-0.5 shrink-0 text-ink-300" />
            <p class="text-meta leading-[1.55] text-ink-400">
                We guessed what each column is from its heading, but a form can word its questions any way at all, so
                check it. Mark <span class="font-bold text-ink-600">one</span> column as the group name and every column
                that holds a
                {{ $perStudent ? 'student\'s matric number or email' : 'member\'s matric number or email' }}.
                Columns of names are best left out: names are never used to match anyone.
            </p>
        </div>

        <form method="POST" action="{{ route('lecturer.formation.import.map', [$subject, $token]) }}" class="flex flex-col gap-5">
            @csrf

            <x-input-error :messages="$errors->get('roles')" />

            <x-data-table :headings="['Column', 'First rows', 'This column is']">
                @foreach ($held['headers'] as $i => $header)
                    <tr>
                        <td class="align-top">
                            <span class="text-nav font-bold">{{ $header }}</span>
                        </td>

                        <td class="max-w-[320px] align-top">
                            <ul class="flex flex-col gap-0.5 text-meta text-ink-400">
                                @foreach ($sample as $row)
                                    <li class="truncate" title="{{ $row['cells'][$i] ?? '' }}">
                                        {{ ($row['cells'][$i] ?? '') !== '' ? $row['cells'][$i] : '—' }}
                                    </li>
                                @endforeach
                            </ul>
                        </td>

                        <td class="align-top">
                            <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="What column {{ $header }} holds">
                                @foreach ([\App\Support\Formation\ImportBuilder::ROLE_IGNORE => 'Ignore', \App\Support\Formation\ImportBuilder::ROLE_GROUP => 'Group name', \App\Support\Formation\ImportBuilder::ROLE_MEMBER => $memberLabel, \App\Support\Formation\ImportBuilder::ROLE_LEADER => 'Leader (optional)'] as $value => $label)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="roles[{{ $i }}]" value="{{ $value }}"
                                               @checked(($roles[$i] ?? \App\Support\Formation\ImportBuilder::ROLE_IGNORE) === $value)
                                               class="peer sr-only">
                                        <span class="inline-flex h-8 items-center rounded-pill bg-surface-muted px-3.5 text-meta font-bold text-ink-600
                                                     transition-colors hover:bg-surface-hover peer-checked:bg-ink peer-checked:text-white
                                                     peer-focus-visible:ring-2 peer-focus-visible:ring-ink/30">
                                            {{ $label }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-data-table>

            <p class="text-label text-ink-400">
                {{ count($held['rows']) }} {{ Str::plural('response', count($held['rows'])) }} in the file.
                Reading it creates nothing — the next screen is a review.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit">Read the groups</x-button>
                <x-button variant="secondary" :href="route('lecturer.formation.import', $subject)">Back</x-button>
            </div>
        </form>
    </div>

</x-layouts.app>
