@props([
    'name' => 'content',
    'value' => '',
    'label' => 'Body',
])

@php
    use App\Support\StyleAttributeSanitizer as Style;

    // Driven from the sanitizer's own allowlists, so the toolbar can never
    // offer a choice the server would throw away on save.
    $fonts = [
        'Plus Jakarta Sans' => 'Default',
        'Arial' => 'Arial',
        'Georgia' => 'Georgia',
        'Times New Roman' => 'Times New Roman',
        'Courier New' => 'Courier New',
    ];

    // execCommand speaks in seven steps rather than point sizes; these are its
    // steps, named for people rather than for CSS.
    $sizes = [
        '2' => 'Small',
        '3' => 'Normal',
        '4' => 'Medium',
        '5' => 'Large',
        '6' => 'Huge',
    ];

    $spacings = ['1' => 'Single', '1.15' => 'Comfortable', '1.5' => 'One and a half', '2' => 'Double'];

    $swatches = ['#111114', '#4d4d57', '#b7291f', '#1b6b45', '#4a5bd0', '#8f6b00'];
    $highlights = ['#fff3bf', '#d3f9d8', '#e7ebfc', '#fdeae8', '#f2f2f5'];
@endphp

{{--
    A deliberately small word processor: a contenteditable plus a toolbar, no
    editor library.

    It is a continuous flow, not a paginated document. No page breaks, no
    headers or footers, no margins — those need a page model, and a page model
    needs a renderer that agrees with the browser about where a page ends. A
    group writing a report together does not need that; it needs the writing to
    survive, which is what the version history is for.

    Everything the toolbar emits has a matching entry in
    App\Support\DocumentSanitizer, and every style value it emits has a
    validator in App\Support\StyleAttributeSanitizer. Whatever it produces is
    sanitised again server-side on save, so the editor is convenience and never
    the trust boundary. If the two ever disagree the server wins and the
    formatting is silently dropped, which is the right way round.

    document.execCommand is deprecated but remains the only thing every browser
    implements for this. The alternative is a large dependency for a toolbar.
--}}
<div class="flex flex-col gap-2"
     x-data="{
         content: @js($value),
         words: 0,
         characters: 0,

         init() {
             this.$refs.editor.innerHTML = this.content

             /* Emit spans carrying inline styles rather than <font> tags.
                Without this, execCommand produces markup from 1997 that the
                sanitizer does not allow and the formatting vanishes on save. */
             try { document.execCommand('styleWithCSS', false, true) } catch (e) { /* older engines */ }

             this.count()
         },

         exec(command, argument = null) {
             this.$refs.editor.focus()
             document.execCommand(command, false, argument)
             this.sync()
         },

         /* Line spacing belongs to a block, not to a run of text, so it is set
            on the paragraph the cursor sits in rather than through
            execCommand, which has no command for it. */
         setSpacing(value) {
             const editor = this.$refs.editor
             editor.focus()

             const selection = window.getSelection()
             if (! selection.rangeCount) return

             let node = selection.getRangeAt(0).startContainer
             if (node.nodeType === Node.TEXT_NODE) node = node.parentNode

             while (node && node !== editor && ! ['P', 'H2', 'H3', 'LI', 'BLOCKQUOTE', 'TD', 'TH'].includes(node.tagName)) {
                 node = node.parentNode
             }

             if (node && node !== editor) {
                 node.style.lineHeight = value
                 this.sync()
             }
         },

         insertTable() {
             const cell = '<td>&nbsp;</td>'
             const row = '<tr>' + cell.repeat(3) + '</tr>'
             const head = '<tr><th>&nbsp;</th><th>&nbsp;</th><th>&nbsp;</th></tr>'

             this.exec('insertHTML', '<table><thead>' + head + '</thead><tbody>' + row.repeat(2) + '</tbody></table><p><br></p>')
         },

         sync() {
             this.content = this.$refs.editor.innerHTML
             this.count()
         },

         count() {
             const text = (this.$refs.editor.innerText || '').replace(/ /g, ' ')

             this.characters = text.replace(/\s+$/, '').length
             this.words = text.trim() === '' ? 0 : text.trim().split(/\s+/).length
         },

         /* Paste as plain text: keeps someone's Word styling out of the
            group's document, and removes the most common route for pasted
            markup. Unchanged by the new formatting — the toolbar is the only
            way to style anything here. */
         pastePlain(event) {
             event.preventDefault()
             const text = (event.clipboardData || window.clipboardData).getData('text/plain')
             document.execCommand('insertText', false, text)
             this.sync()
         },
     }">

    <span class="text-meta font-bold text-ink-700">{{ $label }}</span>

    <div class="overflow-hidden rounded-[16px] border border-surface-line bg-surface focus-within:ring-2 focus-within:ring-ink/10">

        {{-- Toolbar. Wraps onto as many rows as it needs rather than
             overflowing, so a phone gets a taller toolbar and never a
             horizontally clipped one. --}}
        <div class="flex flex-wrap items-center gap-1 border-b border-surface-line bg-surface-soft px-2.5 py-2"
             role="toolbar" aria-label="Formatting">

            @php
                $btn = 'flex size-8 shrink-0 items-center justify-center rounded-pill text-ink-600 hover:bg-surface-hover hover:text-ink';
                $pill = 'flex h-8 shrink-0 items-center rounded-pill px-2.5 text-meta font-semibold text-ink-600 hover:bg-surface-hover hover:text-ink';
                $select = 'h-8 shrink-0 rounded-pill border-none bg-surface py-0 pl-2.5 pr-7 text-meta font-semibold text-ink-600 focus:outline-none focus:ring-2 focus:ring-ink/20';
                $rule = 'mx-0.5 h-5 w-px shrink-0 bg-surface-line';
            @endphp

            {{-- History --}}
            <button type="button" x-on:click="exec('undo')" title="Undo" aria-label="Undo" class="interactive {{ $btn }}">
                <x-icon name="undo" size="15" />
            </button>
            <button type="button" x-on:click="exec('redo')" title="Redo" aria-label="Redo" class="interactive {{ $btn }}">
                <x-icon name="redo" size="15" />
            </button>

            <span class="{{ $rule }}" aria-hidden="true"></span>

            {{-- Font, size, spacing --}}
            <select class="{{ $select }}" aria-label="Font"
                    x-on:change="exec('fontName', $event.target.value); $event.target.selectedIndex = 0">
                <option value="" disabled selected>Font</option>
                @foreach ($fonts as $family => $label)
                    <option value="{{ $family }}">{{ $label }}</option>
                @endforeach
            </select>

            <select class="{{ $select }}" aria-label="Text size"
                    x-on:change="exec('fontSize', $event.target.value); $event.target.selectedIndex = 0">
                <option value="" disabled selected>Size</option>
                @foreach ($sizes as $step => $label)
                    <option value="{{ $step }}">{{ $label }}</option>
                @endforeach
            </select>

            <select class="{{ $select }}" aria-label="Line spacing"
                    x-on:change="setSpacing($event.target.value); $event.target.selectedIndex = 0">
                <option value="" disabled selected>Spacing</option>
                @foreach ($spacings as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <span class="{{ $rule }}" aria-hidden="true"></span>

            {{-- Character formatting --}}
            @foreach ([['bold', 'Bold', 'B', 'font-extrabold'], ['italic', 'Italic', 'I', 'italic font-serif'], ['underline', 'Underline', 'U', 'underline'], ['strikeThrough', 'Strikethrough', 'S', 'line-through']] as [$command, $title, $glyph, $style])
                <button type="button" x-on:click="exec('{{ $command }}')" title="{{ $title }}" aria-label="{{ $title }}"
                        class="interactive {{ $btn }} text-body {{ $style }}">{{ $glyph }}</button>
            @endforeach

            <span class="{{ $rule }}" aria-hidden="true"></span>

            {{-- Colour and highlight. Native pickers for anything, with a few
                 swatches in front so the common case is one click. --}}
            <div class="flex shrink-0 items-center gap-0.5">
                @foreach ($swatches as $colour)
                    <button type="button"
                            x-on:click="exec('foreColor', '{{ $colour }}')"
                            title="Text colour {{ $colour }}"
                            aria-label="Text colour {{ $colour }}"
                            class="size-5 rounded-pill border border-surface-line interactive hover:border-ink-300"
                            style="background-color: {{ $colour }}"></button>
                @endforeach
                <label class="{{ $btn }} cursor-pointer" title="More text colours">
                    <x-icon name="edit" size="14" />
                    <input type="color" class="sr-only"
                           x-on:input="exec('foreColor', $event.target.value)"
                           aria-label="Pick a text colour">
                </label>
            </div>

            <span class="{{ $rule }}" aria-hidden="true"></span>

            <div class="flex shrink-0 items-center gap-0.5">
                @foreach ($highlights as $colour)
                    <button type="button"
                            x-on:click="exec('hiliteColor', '{{ $colour }}')"
                            title="Highlight {{ $colour }}"
                            aria-label="Highlight {{ $colour }}"
                            class="size-5 rounded-[6px] border border-surface-line interactive hover:border-ink-300"
                            style="background-color: {{ $colour }}"></button>
                @endforeach
                <button type="button" x-on:click="exec('hiliteColor', '#ffffff')" title="Remove highlight"
                        aria-label="Remove highlight" class="interactive {{ $pill }} !px-2 !text-label">None</button>
            </div>

            <span class="{{ $rule }}" aria-hidden="true"></span>

            {{-- Alignment --}}
            @foreach ([['justifyLeft', 'Align left', 'align-left'], ['justifyCenter', 'Centre', 'align-center'], ['justifyRight', 'Align right', 'align-right'], ['justifyFull', 'Justify', 'align-justify']] as [$command, $title, $glyph])
                <button type="button" x-on:click="exec('{{ $command }}')" title="{{ $title }}" aria-label="{{ $title }}"
                        class="interactive {{ $btn }}">
                    <x-icon :name="$glyph" size="15" />
                </button>
            @endforeach

            <span class="{{ $rule }}" aria-hidden="true"></span>

            {{-- Blocks --}}
            <button type="button" x-on:click="exec('formatBlock', 'h2')" title="Heading" aria-label="Heading" class="interactive {{ $pill }} font-extrabold">H2</button>
            <button type="button" x-on:click="exec('formatBlock', 'h3')" title="Subheading" aria-label="Subheading" class="interactive {{ $pill }} font-extrabold">H3</button>
            <button type="button" x-on:click="exec('formatBlock', 'p')" title="Normal text" aria-label="Normal text" class="interactive {{ $pill }}">Text</button>

            <button type="button" x-on:click="exec('insertUnorderedList')" title="Bulleted list" aria-label="Bulleted list" class="interactive {{ $btn }}">
                <x-icon name="list" size="16" />
            </button>
            <button type="button" x-on:click="exec('insertOrderedList')" title="Numbered list" aria-label="Numbered list" class="interactive {{ $btn }}">
                <x-icon name="list-ordered" size="16" />
            </button>
            <button type="button" x-on:click="exec('formatBlock', 'blockquote')" title="Quote" aria-label="Quote" class="interactive {{ $btn }}">
                <x-icon name="quote" size="16" />
            </button>
            <button type="button" x-on:click="insertTable()" title="Insert a table" aria-label="Insert a table" class="interactive {{ $btn }}">
                <x-icon name="grid" size="16" />
            </button>

            <span class="{{ $rule }}" aria-hidden="true"></span>

            <button type="button" x-on:click="exec('removeFormat')" title="Clear formatting" aria-label="Clear formatting"
                    class="interactive {{ $pill }} !text-ink-400">Clear</button>
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

        {{-- Live counts, in the editor's own footer so they belong to the text
             rather than floating somewhere on the page. --}}
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-surface-line bg-surface-soft px-[18px] py-2">
            <span class="text-label text-ink-400">Pasted text arrives unformatted, on purpose.</span>
            <span class="text-label font-semibold text-ink-500" aria-live="polite">
                <span x-text="words"></span> <span x-text="words === 1 ? 'word' : 'words'"></span>
                ·
                <span x-text="characters"></span> <span x-text="characters === 1 ? 'character' : 'characters'"></span>
            </span>
        </div>
    </div>

    {{-- What actually gets posted. --}}
    <input type="hidden" name="{{ $name }}" :value="content">
</div>
