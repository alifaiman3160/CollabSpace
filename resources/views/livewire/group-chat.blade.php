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
<div class="flex min-h-0 flex-1 flex-col"
     wire:poll.5s
     x-data="{
         lightbox: null,
         atBottom: true,
         unseen: false,
         toBottom(smooth = false) {
             const el = $refs.thread
             if (el) { el.scrollTo({ top: el.scrollHeight, behavior: smooth && ! window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'smooth' : 'auto' }) }
             this.unseen = false
         },
         watch() {
             const el = $refs.thread
             if (! el) { return }
             el.addEventListener('scroll', () => {
                 this.atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80
                 if (this.atBottom) { this.unseen = false }
             }, { passive: true })
             // A poll that adds messages keeps a reader at the bottom where they are, and leaves
             // someone who has scrolled up exactly where they are, with a button to jump down.
             new MutationObserver(() => {
                 if (this.atBottom) { this.toBottom() } else { this.unseen = true }
             }).observe(el, { childList: true, subtree: true })
         },
     }"
     x-init="$nextTick(() => { toBottom(); watch() })"
     x-on:chat-message-sent.window="$nextTick(() => toBottom(true))"
     x-on:chat-opened.window="atBottom = true; $nextTick(() => toBottom())"
     x-on:keydown.escape.window="lightbox = null">

    {{-- The group this conversation is for. It is part of the component, so it changes in the same
         step as the messages and one can never sit under the other's header. --}}
    @php
        $pal = \App\Support\SubjectPalette::for($group->subject_id);
        $initials = \Illuminate\Support\Str::of($group->name)->explode(' ')->filter()->take(2)
            ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
    @endphp

    <header class="flex flex-wrap items-center gap-3 border-b border-surface-line bg-surface-soft px-5 py-3" data-chat-header>
        <span class="flex size-10 shrink-0 items-center justify-center rounded-pill text-meta font-extrabold text-ink" style="background-color: {{ $pal['accent'] }};" aria-hidden="true">{{ $initials }}</span>
        <div class="flex min-w-0 flex-1 flex-col">
            <span class="flex items-center gap-2">
                <h2 class="truncate text-nav font-extrabold tracking-[-0.02em]" wire:key="chat-name-{{ $group->id }}" data-chat-name>{{ $group->name }}</h2>
                <span class="shrink-0 rounded-pill px-2 py-px text-micro font-bold text-ink" style="background-color: {{ $pal['bg'] }}; box-shadow: inset 0 0 0 1px {{ $pal['border'] }};">{{ $group->subject->code }}</span>
            </span>
            <span class="text-label text-ink-600">{{ $memberCount }} {{ \Illuminate\Support\Str::plural('member', $memberCount) }}</span>
        </div>
        <a href="{{ route('student.workspace.show', $group) }}"
           class="interactive rounded-pill bg-surface-muted px-4 py-2 text-meta font-semibold text-ink hover:bg-surface-hover">Group workspace</a>
    </header>

    @if ($missing)
        <div class="flex flex-1 flex-col items-center justify-center gap-2.5 bg-surface-soft p-8 text-center" data-chat-missing>
            <span class="flex size-[52px] items-center justify-center rounded-pill bg-surface-muted text-ink-400"><x-icon name="chat" size="24" /></span>
            <span class="text-[15px] font-extrabold tracking-[-0.02em]">That chat is not available</span>
            <span class="max-w-[280px] text-meta leading-[1.5] text-ink-600">You can only open the chats of groups you belong to. Pick one from the list.</span>
        </div>
    @else
    {{-- Thread. The soft surface is what lets the white bubbles stand out; its colour is left alone
         because the per-member name colours are contrast-checked against it. --}}
    <div class="cs-surface-soft relative min-h-0 flex-1">
        <div x-ref="thread"
             class="flex h-full flex-col gap-1 overflow-y-auto bg-transparent px-5 py-4"
             role="log"
             aria-label="Group chat messages">

            @forelse ($days as $date => $messages)
                @php
                    $day = \Illuminate\Support\Carbon::parse($date);
                    $list = $messages->values();
                @endphp

                {{-- Date divider: a small centred chip. --}}
                <div class="flex justify-center py-1.5 first:pt-0" role="separator">
                    <span class="rounded-pill bg-white/85 px-2.5 py-[3px] text-micro font-bold uppercase tracking-eyebrow text-ink-600">
                        @if ($day->isToday())
                            Today
                        @elseif ($day->isYesterday())
                            Yesterday
                        @else
                            {{ $day->format('j M Y') }}
                        @endif
                    </span>
                </div>

                @foreach ($list as $i => $message)
                    @php
                        $mine = $message->user_id === $me;
                        $tone = MemberColour::for($message->user_id);
                        $previous = $list[$i - 1] ?? null;
                        $next = $list[$i + 1] ?? null;

                        // The same person, close in time, folds into a run: one name and avatar at the start,
                        // one time at the end, bubbles joined by square corners between them.
                        $continues = $previous
                            && $previous->user_id === $message->user_id
                            && $previous->created_at->diffInMinutes($message->created_at) < $groupWithinMinutes;
                        $endsRun = ! $next
                            || $next->user_id !== $message->user_id
                            || $message->created_at->diffInMinutes($next->created_at) >= $groupWithinMinutes;

                        // A few emoji on their own are shown big, without a bubble.
                        $emojiOnly = ! $message->hasAttachment()
                            && filled($message->body)
                            && mb_strlen($message->body) <= 12
                            && preg_match('/^[\p{Extended_Pictographic}\p{Emoji_Presentation}\x{200D}\x{FE0F}\s]+$/u', $message->body);

                        $corners = $mine
                            ? ($continues ? 'rounded-tr-md ' : '').(! $endsRun ? 'rounded-br-md' : '')
                            : ($continues ? 'rounded-tl-md ' : '').(! $endsRun ? 'rounded-bl-md' : '');
                    @endphp

                    <article wire:key="msg-{{ $message->id }}" data-cs-item @if ($emojiOnly) data-emoji-only @endif
                             class="flex items-end gap-2.5 {{ $mine ? 'flex-row-reverse' : '' }} {{ $continues ? 'mt-0.5' : 'mt-3' }}">

                        {{-- Other people's avatar leads a run; my own messages carry none. --}}
                        @unless ($mine)
                            @if ($continues)
                                <span class="size-8 shrink-0" aria-hidden="true"></span>
                            @else
                                <x-avatar :name="$message->user->name" size="sm" :tone="$tone['avatar']" class="shrink-0 self-start" />
                            @endif
                        @endunless

                        <div class="flex min-w-0 max-w-[68%] flex-col gap-0.5 {{ $mine ? 'items-end' : 'items-start' }}">

                            @if (! $mine && ! $continues)
                                <span class="rounded-pill bg-white/85 px-2 py-px text-meta font-bold {{ $tone['name'] }}" data-sender>{{ $message->user->name }}</span>
                            @endif

                            @if ($emojiOnly)
                                <p class="px-1 text-[2rem] leading-[1.2]">{{ $message->body }}</p>
                            @else
                                <div class="flex w-fit max-w-full flex-col gap-2 rounded-[18px] px-3.5 py-2 {{ $corners }}
                                            {{ $mine ? 'border border-periwinkle-deep/60 bg-periwinkle-soft text-ink' : 'border border-surface-line bg-surface text-ink shadow-subtle' }}"
                                     data-bubble>

                                    @if ($message->hasAttachment())
                                        @php
                                            $href = route('student.chat.file', ['message' => $message, 'token' => $message->fileToken()]);
                                            $inline = $href.'?inline=1';
                                        @endphp

                                        @if ($message->isImage())
                                            {{-- Thumbnail and lightbox both go through the authenticated route; nothing is public. --}}
                                            <button type="button"
                                                    x-on:click="lightbox = { src: @js($inline), name: @js($message->file_name), size: @js($readableSize($message->file_size)), download: @js($href) }"
                                                    class="interactive group/img -mx-1 block overflow-hidden rounded-[14px]">
                                                <img src="{{ $inline }}"
                                                     alt="{{ $message->file_name }}"
                                                     loading="lazy"
                                                     class="max-h-64 w-auto max-w-[320px] rounded-[14px] object-cover transition-opacity group-hover/img:opacity-90">
                                                <span class="sr-only">Open {{ $message->file_name }} full size</span>
                                            </button>
                                        @else
                                            <a href="{{ $href }}" data-file-card
                                               class="motion-colors flex items-center gap-3 rounded-[14px] p-2 {{ $mine ? 'bg-white/70 hover:bg-white' : 'bg-surface-soft hover:bg-surface-muted' }}">
                                                <span class="flex size-9 shrink-0 items-center justify-center rounded-[12px] bg-periwinkle-soft text-periwinkle-link">
                                                    <x-icon name="file" size="17" />
                                                </span>
                                                <span class="flex min-w-0 flex-col">
                                                    <span class="truncate text-meta font-bold text-ink">{{ $message->file_name }}</span>
                                                    <span class="text-tiny text-ink-600">{{ $readableSize($message->file_size) }}</span>
                                                </span>
                                                <span class="ml-auto flex size-8 shrink-0 items-center justify-center rounded-pill bg-periwinkle-soft text-periwinkle-link" title="Download {{ $message->file_name }}" data-download-icon><x-icon name="download" size="16" /><span class="sr-only">Download {{ $message->file_name }}</span></span>
                                            </a>
                                        @endif
                                    @endif

                                    {{-- The text, or the caption under an image: in the same bubble. --}}
                                    @if ($message->body)
                                        <p class="whitespace-pre-wrap break-words text-body leading-[1.45]">{{ $message->body }}</p>
                                    @endif
                                </div>
                            @endif

                            @if ($endsRun)
                                <span class="rounded-pill bg-white/75 px-2 py-px text-tiny font-medium text-ink-600" title="{{ $message->created_at->format('j F Y, g:ia') }}" data-time>
                                    {{ $message->created_at->format('g:ia') }}
                                </span>
                            @endif
                        </div>
                    </article>
                @endforeach
            @empty
                <div class="flex flex-1 flex-col items-center justify-center" data-empty-chat>
                    <div class="flex max-w-[300px] flex-col items-center gap-2.5 rounded-panel bg-white/80 px-6 py-7 text-center">
                        <span class="flex size-[52px] items-center justify-center rounded-pill bg-periwinkle text-ink">
                            <x-icon name="chat" size="24" />
                        </span>
                        <span class="text-[15px] font-extrabold tracking-[-0.02em]">No messages yet</span>
                        <span class="text-meta leading-[1.5] text-ink-600">
                            Say hello. What you write here is seen by everyone in the group.
                        </span>
                    </div>
                </div>
            @endforelse
        </div>

        {{-- A reader who has scrolled up is not yanked down: they get this instead. --}}
        <button type="button" x-show="unseen" x-cloak x-on:click="toBottom(true)" data-new-messages
                x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to"
                class="interactive absolute bottom-3 left-1/2 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-pill bg-ink px-4 py-2 text-meta font-bold text-white shadow-raised hover:bg-ink-hover">
            New messages
            <x-icon name="arrow-right" size="14" class="rotate-90" />
        </button>
    </div>

    {{-- Composer: one rounded bar, with attach and emoji inside it. Enter sends; Shift+Enter makes a new line. --}}
    <form wire:submit="send" class="flex flex-col gap-2 border-t border-surface-line bg-surface-soft px-4 pb-3 pt-3" data-composer>

        @if ($attachment)
            <div class="flex flex-wrap items-center gap-3 rounded-card border border-surface-line bg-surface-soft px-4 py-2.5">
                <x-icon name="file" size="17" class="shrink-0 text-ink-400" />
                <span class="min-w-0 flex-1 truncate text-meta font-semibold">
                    {{ $attachment->getClientOriginalName() }}
                </span>
                <button type="button" wire:click="removeAttachment"
                        class="interactive text-meta font-bold text-danger-text hover:underline">Remove</button>
            </div>
        @endif

        <div class="flex items-end gap-1.5 rounded-[24px] border border-surface-line bg-surface p-1.5 shadow-lift transition-colors focus-within:border-ink/30 focus-within:ring-2 focus-within:ring-ink/10">

            {{-- Attach --}}
            <label class="interactive flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-pill text-ink-600 hover:bg-surface-hover"
                   title="Attach a file ({{ $this->allowedLabel() }}, up to 10 MB)">
                <span class="sr-only">Attach a file</span>
                <x-icon name="file" size="18" />
                <input type="file" wire:model="attachment" class="hidden"
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.zip">
            </label>

            {{-- Emoji picker --}}
            <div class="relative shrink-0"
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
                        class="flex size-10 items-center justify-center rounded-pill text-lead interactive hover:bg-surface-hover"
                        title="Insert an emoji">
                    <span class="sr-only">Insert an emoji</span>
                    <span aria-hidden="true">🙂</span>
                </button>

                <div x-show="open" x-cloak
                     x-transition:enter="transition ease-motion duration-motion-slow"
                     x-transition:enter-start="opacity-0 scale-[.96]"
                     x-transition:enter-end="opacity-100 scale-100"
                     class="absolute bottom-full left-0 z-30 mb-2 w-[268px] rounded-panel border border-surface-line bg-surface p-3 shadow-card"
                     role="dialog"
                     aria-label="Emoji picker">
                    @foreach ($emoji as $row)
                        <div class="flex justify-between">
                            @foreach ($row as $char)
                                <button type="button"
                                        x-on:click="insert(@js($char))"
                                        class="flex size-8 items-center justify-center rounded-[10px] text-lead interactive hover:bg-surface-muted"
                                        title="{{ $char }}">
                                    <span aria-hidden="true">{{ $char }}</span>
                                    <span class="sr-only">Insert {{ $char }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            <label class="min-w-0 flex-1">
                <span class="sr-only">Message</span>
                <textarea wire:model="body"
                          x-ref="body" data-chat-body
                          rows="1"
                          placeholder="Write a message…"
                          x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 144) + 'px'"
                          x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); $wire.send() }"
                          class="block max-h-36 w-full resize-none border-none bg-transparent px-2 py-2.5 text-[14px] font-medium leading-[1.4] text-ink placeholder:text-ink-300 focus:outline-none focus:ring-0"></textarea>
            </label>

            <x-button type="submit" class="shrink-0 !px-5 !py-2.5">
                <span wire:loading.remove wire:target="send">Send</span>
                <span wire:loading wire:target="send">Sending…</span>
                <x-icon name="arrow-right" size="16" wire:loading.remove wire:target="send" />
            </x-button>
        </div>

        {{-- Upload progress --}}
        <div wire:loading wire:target="attachment" class="text-meta text-ink-400">Uploading…</div>

        <x-input-error :messages="$errors->get('body')" />
        <x-input-error :messages="$errors->get('attachment')" />

        <p class="px-2 text-label text-ink-600" data-attach-help>
            {{ $this->allowedLabel() }} · up to 10 MB · private to {{ $group->name }}.
        </p>
    </form>
    @endif

    {{-- Lightbox --}}
    <div x-show="lightbox" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-6"
         role="dialog" aria-modal="true" aria-label="Image preview">

        <div class="absolute inset-0 bg-ink/80" x-on:click="lightbox = null"></div>

        <div class="relative flex max-h-full max-w-4xl flex-col gap-3">
            <img :src="lightbox?.src" :alt="lightbox?.name"
                 class="max-h-[78vh] w-auto rounded-panel object-contain shadow-modal">

            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="flex min-w-0 items-baseline gap-1.5 text-meta font-semibold text-white/80">
                    <span class="truncate" x-text="lightbox?.name"></span>
                    <span class="shrink-0 text-white/50" x-text="lightbox?.size"></span>
                </span>

                <div class="flex items-center gap-2">
                    <a :href="lightbox?.download"
                       class="inline-flex items-center gap-2 rounded-pill bg-white/15 px-4 py-2 text-meta font-bold text-white transition-colors hover:bg-white/25">
                        <x-icon name="download" size="15" />
                        Download
                    </a>
                    <button type="button" x-on:click="lightbox = null"
                            class="inline-flex items-center rounded-pill bg-white px-4 py-2 text-meta font-bold text-ink btn-motion hover:bg-surface-hover">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
