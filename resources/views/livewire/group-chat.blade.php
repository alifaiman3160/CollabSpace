@php
    use App\Support\MemberColour;

    $me = auth()->id();

    $readableSize = function (?int $bytes) {
        if (! $bytes) {
            return '';
        }
        $units = ['B', 'KB', 'MB'];
        $i = (int) min(floor(log(max($bytes, 1), 1024)), 2);

        return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1).' '.$units[$i];
    };

    // Two messages group under one header when the same person sends again
    // within this window. Anything longer reads as a separate turn.
    $groupWithinMinutes = 5;
@endphp

{{--
    Polling, not websockets: the thread re-fetches every five seconds. Nothing
    to broadcast, no queue worker, no third-party service — the trade is up to
    five seconds of latency, which is what "near real-time" means here.
--}}
<div class="flex flex-col gap-4"
     wire:poll.5s
     x-data="{
         lightbox: null,
         toBottom() {
             const el = $refs.thread
             if (el) { el.scrollTop = el.scrollHeight }
         },
     }"
     x-init="$nextTick(() => toBottom())"
     x-on:chat-message-sent.window="$nextTick(() => toBottom())"
     x-on:keydown.escape.window="lightbox = null">

    {{-- Thread: a defined surface, with white bubbles sitting on it — the same
         soft-panel-plus-cards treatment the workspace and document lists use.

         Its border is one step up from the standard card line, and it carries
         the same lift the composer below does. Everywhere else in the app a
         card is white on the soft page background, so the white-on-soft
         contrast defines the shape and the border is only a secondary cue. The
         thread cannot work that way: it has to stay soft so the white bubbles
         read against it, which leaves it the same colour as the page behind it
         and the border doing the whole job alone. At surface-line that edge sat
         at 1.24:1 against the page — below even the 1.32:1 an ordinary card
         gets, for a harder job — so the panel had no visible outline at all.
         surface-strong takes it to 1.50:1, which matches the weight of every
         other card by eye rather than by hex value.

         The thread background itself is deliberately left alone: the per-sender
         name colours in tailwind.config.js are contrast-checked against
         #f7f7fa, and darkening it would quietly invalidate all eight. --}}
    <div x-ref="thread"
         class="flex h-[min(62vh,620px)] flex-col gap-1 overflow-y-auto rounded-panel border border-surface-strong bg-surface-soft p-4 shadow-subtle"
         role="log"
         aria-label="Group chat messages">

        @forelse ($days as $date => $messages)
            @php
                $day = \Illuminate\Support\Carbon::parse($date);
            @endphp

            {{-- Date divider: a small centred chip, the way a phone messenger
                 does it. No rules across the thread — the lines drew more
                 attention than the date they were labelling. --}}
            <div class="flex justify-center py-1.5 first:pt-0" role="separator">
                <span class="rounded-pill bg-surface-muted px-2.5 py-[3px] text-micro font-bold uppercase tracking-eyebrow text-ink-600">
                    @if ($day->isToday())
                        Today
                    @elseif ($day->isYesterday())
                        Yesterday
                    @else
                        {{ $day->format('j M Y') }}
                    @endif
                </span>
            </div>

            @php
                $previous = null;
            @endphp
            @foreach ($messages as $message)
                @php
                    $mine = $message->user_id === $me;
                    $tone = MemberColour::for($message->user_id);

                    // Same sender, close in time, same day → fold into the run
                    // above rather than repeating avatar and name.
                    $continues = $previous
                        && $previous->user_id === $message->user_id
                        && $previous->created_at->diffInMinutes($message->created_at) < $groupWithinMinutes;

                    $previous = $message;
                @endphp

                <article wire:key="msg-{{ $message->id }}"
                         class="flex items-start gap-2.5 {{ $mine ? 'flex-row-reverse' : '' }} {{ $continues ? 'mt-px' : 'mt-2.5' }}">

                    {{-- The avatar only leads a run; continuations reserve its width. --}}
                    @if ($continues)
                        <span class="size-8 shrink-0" aria-hidden="true"></span>
                    @else
                        <x-avatar :name="$message->user->name"
                                  size="sm"
                                  :tone="$mine ? null : $tone['avatar']"
                                  class="mt-0.5 shrink-0" />
                    @endif

                    <div class="flex min-w-0 max-w-[min(72%,520px)] flex-col gap-1 {{ $mine ? 'items-end' : 'items-start' }}">

                        @unless ($continues)
                            <div class="flex items-baseline gap-2 {{ $mine ? 'flex-row-reverse' : '' }}">
                                <span class="text-meta font-bold {{ $mine ? 'text-ink' : $tone['name'] }}">
                                    {{ $mine ? 'You' : $message->user->name }}
                                </span>
                                <span class="text-tiny text-ink-300"
                                      title="{{ $message->created_at->format('j F Y, g:ia') }}">
                                    {{ $message->created_at->format('g:ia') }}
                                </span>
                            </div>
                        @endunless

                        <div class="flex w-fit max-w-full flex-col gap-2 rounded-card px-3.5 py-2
                                    {{ $mine ? 'bg-ink text-white' : 'bg-surface text-ink shadow-subtle' }}">

                            @if ($message->body)
                                {{-- The message text carries the sender's colour, not just
                                     their name — so a run of replies is attributable at a
                                     glance. Your own stays white on the dark bubble. Every
                                     member hue clears AA on the white bubble surface. --}}
                                <p class="whitespace-pre-wrap break-words text-body leading-[1.45] {{ $mine ? '' : $tone['name'] }}">{{ $message->body }}</p>
                            @endif

                            @if ($message->hasAttachment())
                                @php
                                    $href = route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]);
                                    $inline = $href.'?inline=1';
                                @endphp

                                @if ($message->isImage())
                                    {{-- Thumbnail and lightbox both go through the
                                         authenticated route; nothing is public. --}}
                                    <button type="button"
                                            x-on:click="lightbox = { src: @js($inline), name: @js($message->file_name), download: @js($href) }"
                                            class="group/img block overflow-hidden rounded-[14px]">
                                        <img src="{{ $inline }}"
                                             alt="{{ $message->file_name }}"
                                             loading="lazy"
                                             class="max-h-64 w-auto max-w-full rounded-[14px] object-cover transition-opacity group-hover/img:opacity-90">
                                        <span class="sr-only">Open {{ $message->file_name }} full size</span>
                                    </button>
                                    {{-- The caption must not decide how wide the bubble is.
                                         The bubble is w-fit, so it takes the width of its
                                         widest child — and a long filename is far wider than
                                         a portrait thumbnail, which left the image floating in
                                         a bubble sized to the text.

                                         w-0 keeps this line out of that calculation (a
                                         percentage min-width counts as nothing while the
                                         container is being measured), then min-w-full stretches
                                         it back to whatever width the image settled on. The
                                         name ellipsizes; the size stays readable. --}}
                                    <span class="flex w-0 min-w-full items-baseline gap-1 text-tiny
                                                 {{ $mine ? 'text-white/60' : 'text-ink-300' }}">
                                        <span class="min-w-0 truncate" title="{{ $message->file_name }}">{{ $message->file_name }}</span>
                                        <span class="shrink-0">· {{ $readableSize($message->file_size) }}</span>
                                    </span>
                                @else
                                    <a href="{{ $href }}"
                                       class="flex items-center gap-3 rounded-[14px] p-2 transition-colors
                                              {{ $mine ? 'bg-white/10 hover:bg-white/20' : 'bg-surface-soft hover:bg-surface-muted' }}">
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-[12px]
                                                     {{ $mine ? 'bg-white/15 text-white' : 'bg-surface-muted text-periwinkle-link' }}">
                                            <x-icon name="file" size="17" />
                                        </span>
                                        <span class="flex min-w-0 flex-col">
                                            <span class="truncate text-meta font-bold">{{ $message->file_name }}</span>
                                            <span class="text-tiny {{ $mine ? 'text-white/60' : 'text-ink-300' }}">
                                                {{ $readableSize($message->file_size) }} · Download
                                            </span>
                                        </span>
                                    </a>
                                @endif
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        @empty
            <div class="flex flex-1 flex-col items-center justify-center gap-2.5 text-center">
                <span class="flex size-[52px] items-center justify-center rounded-pill bg-periwinkle text-ink">
                    <x-icon name="chat" size="24" />
                </span>
                <span class="text-[15px] font-extrabold tracking-[-0.02em]">No messages yet</span>
                <span class="max-w-[260px] text-meta leading-[1.5] text-ink-400">
                    This is your group's thread. Anything posted here is visible to every member.
                </span>
            </div>
        @endforelse
    </div>

    {{-- Composer --}}
    <form wire:submit="send" class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface shadow-subtle p-4">

        @if ($attachment)
            <div class="flex flex-wrap items-center gap-3 rounded-card border border-surface-line bg-surface-soft px-4 py-3">
                <x-icon name="file" size="17" class="shrink-0 text-ink-400" />
                <span class="min-w-0 flex-1 truncate text-meta font-semibold">
                    {{ $attachment->getClientOriginalName() }}
                </span>
                <button type="button" wire:click="removeAttachment"
                        class="text-meta font-bold text-danger-text hover:underline">Remove</button>
            </div>
        @endif

        <div class="flex flex-wrap items-end gap-3">
            <label class="min-w-[240px] flex-1">
                <span class="sr-only">Message</span>
                <textarea wire:model="body"
                          x-ref="body"
                          rows="2"
                          placeholder="Write a message…"
                          class="w-full resize-y rounded-[16px] bg-surface border border-surface-line px-[18px] py-3 text-[14px] font-medium text-ink placeholder:text-ink-300 focus:outline-none focus:ring-2 focus:ring-ink/10"></textarea>
            </label>

            <div class="flex items-center gap-2">
                {{-- Emoji picker --}}
                <div class="relative"
                     x-data="{
                         open: false,
                         insert(char) {
                             const el = $refs.body
                             const start = el.selectionStart ?? el.value.length
                             const end = el.selectionEnd ?? el.value.length

                             el.value = el.value.slice(0, start) + char + el.value.slice(end)

                             // Tell Livewire, then put the caret after the emoji.
                             el.dispatchEvent(new Event('input', { bubbles: true }))
                             $nextTick(() => {
                                 el.focus()
                                 const at = start + char.length
                                 el.setSelectionRange(at, at)
                             })
                         },
                     }"
                     x-on:click.outside="open = false"
                     x-on:keydown.escape="open = false">

                    <button type="button"
                            x-on:click="open = ! open"
                            :aria-expanded="open"
                            class="flex size-11 items-center justify-center rounded-pill bg-surface-muted text-lead transition-colors hover:bg-surface-hover"
                            title="Insert an emoji">
                        <span class="sr-only">Insert an emoji</span>
                        <span aria-hidden="true">🙂</span>
                    </button>

                    <div x-show="open" x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="absolute bottom-full right-0 z-30 mb-2 w-[268px] rounded-panel border border-surface-line bg-surface p-3 shadow-card"
                         role="dialog"
                         aria-label="Emoji picker">
                        @foreach ($emoji as $row)
                            <div class="flex justify-between">
                                @foreach ($row as $char)
                                    <button type="button"
                                            x-on:click="insert(@js($char))"
                                            class="flex size-8 items-center justify-center rounded-[10px] text-lead transition-colors hover:bg-surface-muted"
                                            title="{{ $char }}">
                                        <span aria-hidden="true">{{ $char }}</span>
                                        <span class="sr-only">Insert {{ $char }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Attach --}}
                <label class="flex size-11 cursor-pointer items-center justify-center rounded-pill bg-surface-muted text-ink transition-colors hover:bg-surface-hover"
                       title="Attach a file ({{ $this->allowedLabel() }}, up to 10 MB)">
                    <span class="sr-only">Attach a file</span>
                    <x-icon name="file" size="18" />
                    <input type="file" wire:model="attachment" class="hidden"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.zip">
                </label>

                <x-button type="submit">
                    <span wire:loading.remove wire:target="send">Send</span>
                    <span wire:loading wire:target="send">Sending…</span>
                    <x-icon name="arrow-right" size="16" wire:loading.remove wire:target="send" />
                </x-button>
            </div>
        </div>

        {{-- Upload progress --}}
        <div wire:loading wire:target="attachment" class="text-meta text-ink-400">Uploading…</div>

        <x-input-error :messages="$errors->get('body')" />
        <x-input-error :messages="$errors->get('attachment')" />

        <p class="text-label text-ink-400">
            Attachments: {{ $this->allowedLabel() }} · up to 10 MB. Files are private to
            {{ $group->name }} and are not available through a public link.
        </p>
    </form>

    {{-- Lightbox --}}
    <div x-show="lightbox" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-6"
         role="dialog" aria-modal="true" aria-label="Image preview">

        <div class="absolute inset-0 bg-ink/80" x-on:click="lightbox = null"></div>

        <div class="relative flex max-h-full max-w-4xl flex-col gap-3">
            <img :src="lightbox?.src" :alt="lightbox?.name"
                 class="max-h-[78vh] w-auto rounded-panel object-contain shadow-modal">

            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="truncate text-meta font-semibold text-white/80" x-text="lightbox?.name"></span>

                <div class="flex items-center gap-2">
                    <a :href="lightbox?.download"
                       class="inline-flex items-center gap-2 rounded-pill bg-white/15 px-4 py-2 text-meta font-bold text-white transition-colors hover:bg-white/25">
                        <x-icon name="download" size="15" />
                        Download
                    </a>
                    <button type="button" x-on:click="lightbox = null"
                            class="inline-flex items-center rounded-pill bg-white px-4 py-2 text-meta font-bold text-ink transition-colors hover:bg-surface-hover">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
