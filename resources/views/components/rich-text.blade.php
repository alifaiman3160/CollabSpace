@props([
    'name' => 'content',
    'value' => '',
    'label' => 'Body',
])

{{--
    Minimal rich-text field: a contenteditable plus a toolbar, no editor
    library. It can only produce the tags App\Support\DocumentSanitizer allows,
    and whatever it does produce is sanitised again server-side on save — the
    editor is convenience, never the trust boundary.

    document.execCommand is deprecated but is still the only thing every browser
    implements for this; the alternative is a large dependency for six buttons.
--}}
<div class="flex flex-col gap-2"
     x-data="{
         content: @js($value),
         syncing: false,
         exec(command, argument = null) {
             this.$refs.editor.focus()
             document.execCommand(command, false, argument)
             this.sync()
         },
         sync() {
             this.content = this.$refs.editor.innerHTML
         },
         /* Paste as plain text: keeps someone's Word styling out of the group's
            document, and removes the most common route for pasted markup. */
         pastePlain(event) {
             event.preventDefault()
             const text = (event.clipboardData || window.clipboardData).getData('text/plain')
             document.execCommand('insertText', false, text)
             this.sync()
         },
     }"
     x-init="$refs.editor.innerHTML = content">

    <span class="text-meta font-bold text-ink-700">{{ $label }}</span>

    <div class="overflow-hidden rounded-[16px] border border-surface-line bg-surface focus-within:ring-2 focus-within:ring-ink/10">

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center gap-1 border-b border-surface-line bg-surface-soft px-2.5 py-2"
             role="toolbar" aria-label="Formatting">

            @php
                $tools = [
                    ['bold', 'Bold', 'B', 'font-extrabold'],
                    ['italic', 'Italic', 'I', 'italic font-serif'],
                    ['underline', 'Underline', 'U', 'underline'],
                ];
            @endphp

            @foreach ($tools as [$command, $title, $glyph, $style])
                <button type="button"
                        x-on:click="exec('{{ $command }}')"
                        title="{{ $title }}"
                        aria-label="{{ $title }}"
                        class="flex size-8 items-center justify-center rounded-pill text-body {{ $style }} text-ink-600 transition-colors hover:bg-surface-hover hover:text-ink">
                    {{ $glyph }}
                </button>
            @endforeach

            <span class="mx-1 h-5 w-px bg-surface-line" aria-hidden="true"></span>

            <button type="button" x-on:click="exec('formatBlock', 'h2')" title="Heading" aria-label="Heading"
                    class="flex h-8 items-center rounded-pill px-2.5 text-meta font-extrabold text-ink-600 transition-colors hover:bg-surface-hover hover:text-ink">H2</button>
            <button type="button" x-on:click="exec('formatBlock', 'h3')" title="Subheading" aria-label="Subheading"
                    class="flex h-8 items-center rounded-pill px-2.5 text-meta font-extrabold text-ink-600 transition-colors hover:bg-surface-hover hover:text-ink">H3</button>
            <button type="button" x-on:click="exec('formatBlock', 'p')" title="Normal text" aria-label="Normal text"
                    class="flex h-8 items-center rounded-pill px-2.5 text-meta font-semibold text-ink-600 transition-colors hover:bg-surface-hover hover:text-ink">Text</button>

            <span class="mx-1 h-5 w-px bg-surface-line" aria-hidden="true"></span>

            <button type="button" x-on:click="exec('insertUnorderedList')" title="Bulleted list" aria-label="Bulleted list"
                    class="flex size-8 items-center justify-center rounded-pill text-ink-600 transition-colors hover:bg-surface-hover hover:text-ink">
                <x-icon name="list" size="16" />
            </button>
            <button type="button" x-on:click="exec('insertOrderedList')" title="Numbered list" aria-label="Numbered list"
                    class="flex size-8 items-center justify-center rounded-pill text-ink-600 transition-colors hover:bg-surface-hover hover:text-ink">
                <x-icon name="list-ordered" size="16" />
            </button>
            <button type="button" x-on:click="exec('formatBlock', 'blockquote')" title="Quote" aria-label="Quote"
                    class="flex size-8 items-center justify-center rounded-pill text-ink-600 transition-colors hover:bg-surface-hover hover:text-ink">
                <x-icon name="quote" size="16" />
            </button>

            <span class="mx-1 h-5 w-px bg-surface-line" aria-hidden="true"></span>

            <button type="button" x-on:click="exec('removeFormat')" title="Clear formatting" aria-label="Clear formatting"
                    class="flex h-8 items-center rounded-pill px-2.5 text-meta font-semibold text-ink-400 transition-colors hover:bg-surface-hover hover:text-ink">Clear</button>
        </div>

        {{-- The editable surface --}}
        <div x-ref="editor"
             contenteditable="true"
             role="textbox"
             aria-multiline="true"
             aria-label="{{ $label }}"
             x-on:input="sync()"
             x-on:blur="sync()"
             x-on:paste="pastePlain($event)"
             class="doc-body min-h-[420px] w-full overflow-y-auto px-[18px] py-4 text-[14.5px] leading-[1.65] text-ink outline-none"></div>
    </div>

    {{-- What actually gets posted. --}}
    <input type="hidden" name="{{ $name }}" :value="content">

    <p class="text-label text-ink-400">
        Formatting is limited to bold, italic, underline, headings, lists and quotes.
        Anything else is stripped when the document is saved.
    </p>
</div>
