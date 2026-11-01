@php
    use App\Models\Task;

    $meta = [
        Task::STATUS_TODO => [
            'title' => 'To Do',
            'well' => 'bg-surface-muted',
            'bar' => 'bg-ink-200',
            'pill' => 'bg-surface-muted text-ink-500',
            'emptyTitle' => 'Nothing here yet',
        ],
        Task::STATUS_INPROGRESS => [
            'title' => 'In Progress',
            'well' => 'bg-periwinkle-soft',
            'bar' => 'bg-periwinkle-deep',
            'pill' => 'bg-periwinkle-soft text-periwinkle-link',
            'emptyTitle' => 'Nothing in progress',
        ],
        Task::STATUS_DONE => [
            'title' => 'Done',
            'well' => 'bg-success-bg',
            'bar' => 'bg-success/50',
            'pill' => 'bg-success-bg text-success-text',
            'emptyTitle' => 'Nothing done yet',
        ],
    ];

    $donePct = $total > 0 ? (int) round($doneCount / $total * 100) : 0;
@endphp

{{--
    Drag-and-drop is the fast path, but every card also carries an explicit
    status <select>. Drag alone is unusable with a keyboard or on a touchscreen,
    so the select is the real control and the drag is an accelerator.
--}}
<div class="flex flex-col gap-[18px]"
     x-data="{
         dragging: null,
         over: null,
         doneOpen: null,
         toggleDone(id, el) {
             this.doneOpen = this.doneOpen === id ? null : id
             if (this.doneOpen === null) return
             // Once it has grown, bring it into view inside its own column, only if it would be cut off.
             setTimeout(() => el.scrollIntoView({
                 block: 'nearest',
                 behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
             }), 300)
         },
         start(id, event) {
             this.dragging = id
             event.dataTransfer.effectAllowed = 'move'
             event.dataTransfer.setData('text/plain', String(id))
         },
         drop(status) {
             const id = this.dragging
             this.over = null
             this.dragging = null
             if (id) { $wire.moveTask(id, status) }
         },
     }">

    {{-- Hero progress panel --}}
    <section class="flex flex-col gap-[18px] rounded-panel bg-periwinkle p-6 sm:px-[26px]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">Group progress</span>
            <span class="rounded-pill bg-white/70 px-[11px] py-[5px] text-label font-bold">
                {{ $group->subject->code }} · {{ $group->name }}
            </span>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <span class="text-hero font-extrabold leading-[.95] tracking-stat">{{ $donePct }}%</span>
            <span class="pb-[7px] text-nav font-semibold text-ink/[.62]">
                @if ($total > 0)
                    {{ $doneCount }} of {{ $total }} {{ Str::plural('task', $total) }} complete
                @else
                    No tasks on the board yet
                @endif
            </span>
        </div>

        <x-progress-bar :done="$doneCount" :doing="$doingCount" :total="$total" on="periwinkle" />

        <div class="flex flex-wrap items-center gap-4">
            <div class="flex flex-col items-center gap-2">
                <button type="button"
                        wire:click="toggleForm"
                        class="flex size-[52px] items-center justify-center rounded-pill bg-ink text-white btn-motion hover:bg-ink-hover"
                        aria-label="{{ $showForm ? 'Close the new task form' : 'Add a task' }}">
                    <x-icon name="plus" size="20" class="[stroke-width:2]" />
                </button>
                <span class="text-label font-semibold">New task</span>
            </div>

            @if ($overdueCount > 0)
                <div class="flex items-center gap-2 rounded-pill bg-danger-bg px-4 py-2.5 text-label font-bold text-danger-text">
                    <x-icon name="clock" size="14" />
                    {{ $overdueCount }} overdue
                </div>
            @endif
        </div>
    </section>

    {{-- Create or edit a task — one form, because the fields are identical and
         two of them would drift apart the first time one was changed. --}}
    @if ($showForm)
        <form wire:submit="saveTask" class="cs-pop flex flex-col gap-5 rounded-panel border border-surface-line bg-surface shadow-subtle p-6">
            <h2 class="text-h3 font-bold tracking-title">
                @if ($editing)
                    Edit “{{ Str::limit($editing->title, 48) }}”
                @else
                    New task
                @endif
            </h2>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="flex flex-col gap-2 sm:col-span-2">
                    <x-input-label for="task-title" value="Title" />
                    <x-text-input id="task-title" wire:model="title" type="text"
                                  placeholder="What needs doing?" />
                    <x-input-error :messages="$errors->get('title')" />
                </div>

                <div class="flex flex-col gap-2">
                    <x-input-label for="task-assignee" value="Assign to" />
                    <select id="task-assignee" wire:model="assignedTo"
                            class="h-[52px] w-full rounded-[16px] bg-surface border border-surface-line px-[18px] text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                        <option value="">Nobody yet</option>
                        @foreach ($members as $member)
                            <option value="{{ $member->id }}">
                                {{ $member->name }}{{ $member->is(auth()->user()) ? ' (you)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('assignedTo')" />
                </div>

                {{-- A hint only: a task may be due after the group project is. Nothing is blocked or changed by it. --}}
                <div class="flex flex-col gap-2"
                     x-data="{ picked: '', limit: @js($group->subject->project_deadline?->format('Y-m-d')) }"
                     x-effect="picked = $wire.deadline ?? ''">
                    <x-input-label for="task-deadline" value="Deadline" />
                    <x-text-input id="task-deadline" wire:model="deadline" type="date" x-on:input="picked = $event.target.value" />
                    @if ($group->subject->project_deadline)
                        <p x-show="limit && picked && picked > limit" x-cloak role="status" data-task-after-project
                           class="text-label font-semibold text-warning-text">
                            This is after the group project due date, {{ $group->subject->project_deadline->format('j M Y') }}.
                        </p>
                    @endif
                    <x-input-error :messages="$errors->get('deadline')" />
                </div>

                <div class="flex flex-col gap-2 sm:col-span-2">
                    <x-input-label for="task-description" value="Description" />
                    <textarea id="task-description" wire:model="description" rows="3"
                              placeholder="Any detail the group needs."
                              class="w-full rounded-[16px] bg-surface border border-surface-line px-[18px] py-3.5 text-[14px] font-medium text-ink placeholder:text-ink-200 focus:outline-none focus:ring-2 focus:ring-ink/10"></textarea>
                    <x-input-error :messages="$errors->get('description')" />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit">{{ $editing ? 'Save changes' : 'Add task' }}</x-button>
                <x-button variant="secondary" type="button" wire:click="cancelEdit">Cancel</x-button>

                @if ($editing)
                    <span class="text-meta text-ink-400">
                        The column it sits in does not change — move it on the board instead.
                    </span>
                @endif
            </div>
        </form>
    @endif

    {{-- Columns --}}
    {{-- Each column is as tall as what is left of the screen and scrolls inside itself, so a long Done
         column never stretches the page. --}}
    <div class="flex gap-4 overflow-x-auto pb-2" style="--board-h: max(26rem, calc(100dvh - 31rem))" data-columns>
        @foreach ($columns as $status => $tasks)
            @php($m = $meta[$status])
            <section
                class="relative flex h-[var(--board-h)] min-w-[290px] flex-1 flex-col gap-3 overflow-hidden rounded-panel border p-4 pt-5 transition-colors
                       {{ $m['well'] }}"
                data-column="{{ $status }}"
                :class="over === '{{ $status }}' ? 'border-ink/40' : 'border-surface-line'"
                x-on:dragover.prevent="over = '{{ $status }}'"
                x-on:dragleave="over = null"
                x-on:drop.prevent="drop('{{ $status }}')"
                aria-label="{{ $m['title'] }} column">

                <span class="absolute inset-x-0 top-0 h-1.5 {{ $m['bar'] }}" aria-hidden="true" data-accent></span>

                <div class="flex items-center gap-2.5 px-1 pt-0.5">
                    <h2 class="text-[16px] font-extrabold tracking-[-0.025em]">{{ $m['title'] }}</h2>
                    <span class="flex h-[22px] min-w-6 items-center justify-center rounded-pill bg-surface px-[7px] text-label font-bold text-ink-500">
                        {{ $tasks->count() }}
                    </span>

                    @if ($status === Task::STATUS_TODO)
                        <button type="button" wire:click="toggleForm" data-column-new-task
                                class="interactive ml-auto inline-flex items-center gap-1.5 rounded-pill bg-surface px-3 py-1.5 text-label font-bold text-ink hover:bg-surface-hover">
                            <x-icon name="plus" size="13" class="[stroke-width:2.5]" />
                            New task
                        </button>
                    @endif
                </div>

                <div class="cs-scroll flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto pr-1" data-column-body>
                    @forelse ($tasks as $task)
                        {{-- A card assigned to somebody else can still be picked
                             up and dropped: the move is refused on the server
                             and the reason is said out loud. Blocking the drag
                             outright would leave people guessing why the card
                             would not lift. --}}
                        @php($mine = $task->assigned_to === null || $task->assigned_to === auth()->id())
                        @if ($status === Task::STATUS_DONE)
                            {{-- A finished task is a row that opens: one line shut, everything about it open. --}}
                            <x-task-done-row :task="$task" :mine="$mine"
                                wire:key="task-{{ $task->id }}"
                                draggable="true"
                                x-on:dragstart="start({{ $task->id }}, $event)"
                                x-on:dragend="dragging = null; over = null"
                                x-bind:class="dragging === {{ $task->id }} ? 'opacity-50' : ''"
                                :title="$mine ? null : 'Assigned to '.$task->assignee->name.' — only they can move this card'">
                                <x-slot:controls>
                        <div class="flex items-center gap-2">
                            <label class="sr-only" for="status-{{ $task->id }}">Status for {{ $task->title }}</label>
                            <select id="status-{{ $task->id }}"
                                    class="h-9 flex-1 rounded-pill border-none bg-surface-muted px-3 text-meta font-bold text-ink focus:outline-none focus:ring-2 focus:ring-ink/20"
                                    wire:change="moveTask({{ $task->id }}, $event.target.value)">
                                @foreach ($meta as $value => $option)
                                    <option value="{{ $value }}" @selected($task->status === $value)>{{ $option['title'] }}</option>
                                @endforeach
                            </select>

                            <button type="button" wire:click="startEdit({{ $task->id }})"
                                    class="flex size-9 shrink-0 items-center justify-center rounded-pill bg-surface-muted text-ink-400 interactive hover:bg-periwinkle-soft hover:text-periwinkle-link"
                                    aria-label="Edit {{ $task->title }}">
                                <x-icon name="edit" size="15" />
                            </button>

                            <button type="button" wire:click="confirmDelete({{ $task->id }})"
                                    class="flex size-9 shrink-0 items-center justify-center rounded-pill bg-surface-muted text-ink-400 interactive hover:bg-danger-bg hover:text-danger-text"
                                    aria-label="Delete {{ $task->title }}">
                                <x-icon name="trash" size="15" />
                            </button>
                        </div>
                                </x-slot:controls>
                            </x-task-done-row>
                        @else
                        <article
                            wire:key="task-{{ $task->id }}" data-cs-item
                            draggable="true"
                            x-on:dragstart="start({{ $task->id }}, $event)"
                            x-on:dragend="dragging = null; over = null"
                            :class="dragging === {{ $task->id }} ? 'opacity-50' : ''"
                            @unless ($mine)
                                title="Assigned to {{ $task->assignee->name }} — only they can move this card"
                            @endunless
                            class="relative flex shrink-0 cursor-grab flex-col overflow-hidden rounded-card bg-surface shadow-subtle gap-3 p-[15px] transition-shadow hover:shadow-card active:cursor-grabbing">

                            @if ($task->isOverdue())
                                <span class="absolute inset-y-0 left-0 w-1 bg-danger" aria-hidden="true"></span>
                            @endif

                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="rounded-pill px-[11px] py-[4.5px] text-label font-bold {{ $m['pill'] }}">
                                    {{ $m['title'] }}
                                </span>
                                @if ($task->isOverdue())
                                    <x-badge tone="danger">
                                        <x-icon name="clock" size="12" />
                                        Overdue
                                    </x-badge>
                                @endif
                            </div>

                            <h3 class="text-pretty text-[15px] font-bold leading-[1.35] tracking-[-0.015em]">
                                {{ $task->title }}
                            </h3>

                            @if ($task->description)
                                <p class="line-clamp-2 text-meta text-ink-400">{{ $task->description }}</p>
                            @endif

                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="flex min-w-0 items-center gap-2">
                                    @if ($task->assignee)
                                        <x-avatar :name="$task->assignee->name" size="sm" class="!size-[27px] !text-micro" />
                                        <span class="truncate text-meta font-semibold text-ink-600">
                                            {{ $mine ? 'You' : $task->assignee->name }}
                                        </span>
                                        @unless ($mine)
                                            {{-- Says whose card it is before anyone tries to move it. --}}
                                            <span class="flex shrink-0 items-center text-ink-300"
                                                  aria-label="Only {{ $task->assignee->name }} can move this card">
                                                <x-icon name="lock" size="12" />
                                            </span>
                                        @endunless
                                    @else
                                        <span class="flex size-[27px] items-center justify-center rounded-pill bg-surface-muted text-ink-300">
                                            <x-icon name="user" size="14" />
                                        </span>
                                        <span class="text-meta font-semibold text-ink-300">Unassigned</span>
                                    @endif
                                </span>

                                @if ($task->deadline)
                                    <span class="flex shrink-0 items-center gap-1.5 text-meta font-{{ $task->isOverdue() ? 'bold text-danger-text' : 'semibold text-ink-400' }}">
                                        <x-icon name="calendar" size="13" />
                                        {{ $task->deadline->format('j M') }}
                                    </span>
                                @endif
                            </div>

                            {{-- The accessible control: works with a keyboard and on touch. --}}
                            <div class="flex items-center gap-2 border-t border-surface-line pt-3">
                                <label class="sr-only" for="status-{{ $task->id }}">
                                    Status for {{ $task->title }}
                                </label>
                                <select id="status-{{ $task->id }}"
                                        class="h-9 flex-1 rounded-pill border-none bg-surface-muted px-3 text-meta font-bold text-ink focus:outline-none focus:ring-2 focus:ring-ink/20"
                                        wire:change="moveTask({{ $task->id }}, $event.target.value)">
                                    @foreach ($meta as $value => $option)
                                        <option value="{{ $value }}" @selected($task->status === $value)>
                                            {{ $option['title'] }}
                                        </option>
                                    @endforeach
                                </select>

                                <button type="button"
                                        wire:click="startEdit({{ $task->id }})"
                                        class="flex size-9 shrink-0 items-center justify-center rounded-pill bg-surface-muted text-ink-400 interactive hover:bg-periwinkle-soft hover:text-periwinkle-link"
                                        aria-label="Edit {{ $task->title }}">
                                    <x-icon name="edit" size="15" />
                                </button>

                                <button type="button"
                                        wire:click="confirmDelete({{ $task->id }})"
                                        class="flex size-9 shrink-0 items-center justify-center rounded-pill bg-surface-muted text-ink-400 interactive hover:bg-danger-bg hover:text-danger-text"
                                        aria-label="Delete {{ $task->title }}">
                                    <x-icon name="trash" size="15" />
                                </button>
                            </div>
                        </article>
                        @endif
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-card border-2 border-dashed border-ink/15 bg-white/50 px-5 py-8 text-center" data-empty>
                            <span class="text-[15px] font-bold tracking-[-0.02em] text-ink-600">{{ $m['emptyTitle'] }}</span>
                        </div>
                    @endforelse

                </div>
            </section>
        @endforeach
    </div>

    {{-- Delete confirmation --}}
    @if ($deletingTask)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true"
             x-on:keydown.escape.window="$wire.cancelDelete()">
            <div class="cs-pop-overlay absolute inset-0 bg-ink/40" wire:click="cancelDelete"></div>

            <div class="cs-pop relative w-full max-w-md rounded-panel bg-surface p-7 shadow-modal">
                <div class="flex items-start gap-3.5">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-[14px] bg-danger-bg text-danger-text">
                        <x-icon name="alert" size="20" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <h2 class="text-h3 font-bold tracking-title">Delete this task?</h2>
                        <p class="text-body leading-[1.55] text-ink-400">
                            “{{ $deletingTask->title }}” will be removed from the board for everyone in
                            {{ $group->name }}, along with the contribution credit it earned. This cannot be undone.
                        </p>
                    </div>
                </div>

                <div class="mt-7 flex flex-wrap justify-end gap-3">
                    <x-button variant="secondary" wire:click="cancelDelete">Cancel</x-button>
                    <button type="button" wire:click="deleteTask"
                            class="inline-flex items-center justify-center gap-2 rounded-pill bg-danger px-5 py-3 text-nav font-bold text-white btn-motion hover:bg-danger-deep">
                        Delete task
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
