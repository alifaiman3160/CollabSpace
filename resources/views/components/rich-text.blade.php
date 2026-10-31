@props([
    'name' => 'content',
    'value' => '',
    'label' => 'Body',
    // Where a picture is posted. Empty when the editor has nowhere to keep pictures.
    'uploadUrl' => '',
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

    It sits on an A4-width sheet with 2.5 cm margins so it looks like the
    printed report, but it is still a continuous flow: the editor does not
    paginate by itself. "New page" inserts the marker the print layout turns into a real page break
    (see App\Support\DocumentPrintBody), and page numbers exist in that layout
    only.

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
     x-data="documentEditor(@js(['value' => $value, 'uploadUrl' => $uploadUrl, 'csrf' => csrf_token()]))">

    <span class="text-meta font-bold text-ink-700">{{ $label }}</span>

    {{-- overflow-clip, not -hidden: it rounds the corners without making this a scroll container, so the ribbon can stay stuck to the top of the screen while a long page is written. --}}
    <div class="overflow-clip rounded-[16px] border border-surface-line bg-surface focus-within:ring-2 focus-within:ring-ink/10">

        @php
            $btn = 'flex size-8 shrink-0 items-center justify-center rounded-pill text-ink-600 hover:bg-surface-hover hover:text-ink';
            $pill = 'flex h-8 shrink-0 items-center gap-1.5 rounded-pill px-2.5 text-meta font-semibold text-ink-600 hover:bg-surface-hover hover:text-ink';
            $select = 'h-8 shrink-0 rounded-pill border-none bg-surface py-0 pl-2.5 pr-7 text-meta font-semibold text-ink-600 focus:outline-none focus:ring-2 focus:ring-ink/20';
            $on = '!bg-ink !text-white';

            // One group of the ribbon: its controls in up to two rows, its name centred underneath. A group wraps as a
            // whole block when the window is narrow, never half of it.
            $group = 'flex grow basis-auto flex-col justify-between gap-1 border-l border-surface-line px-3';
            $row = 'flex h-8 flex-nowrap items-center justify-center gap-1';
            $name = 'text-center text-[10px] font-bold uppercase leading-none tracking-wide text-ink-400';
        @endphp

        {{-- The ribbon: formatting, paragraph, spacing, styles and insert groups, then (on the right) the page controls and
             the counts. Every control acts on the selection (character controls) or on the paragraphs the selection
             touches (paragraph controls) and shows what the selection currently has, "Mixed" when it has several. The
             buttons do not take the focus, so the selection is kept. Sticky: it stays at the top while a long page is written. --}}
        <div class="sticky top-0 z-20 flex flex-wrap items-stretch justify-between gap-y-2 border-b border-surface-line bg-surface-soft px-2.5 py-2"
             role="toolbar" aria-label="Formatting" x-on:mousedown="keepSelection($event)">

            {{-- History, outside the groups --}}
            <div class="flex shrink-0 flex-col justify-between gap-1 pr-3">
                <div class="flex flex-col gap-1">
                  <div class="{{ $row }}">
                    <button type="button" x-on:click="undo()" title="Undo (Ctrl + Z)" aria-label="Undo" class="interactive {{ $btn }}">
                        <x-icon name="undo" size="15" />
                    </button>
                  </div>
                  <div class="{{ $row }}">
                    <button type="button" x-on:click="redo()" title="Redo (Ctrl + Y)" aria-label="Redo" class="interactive {{ $btn }}">
                        <x-icon name="redo" size="15" />
                    </button>
                  </div>
                </div>
                <span class="{{ $name }}" aria-hidden="true">&nbsp;</span>
            </div>

            {{-- FONT --}}
            <div class="{{ $group }}" role="group" aria-label="Font">
                <div class="flex flex-col gap-1">
                    <div class="{{ $row }}">
                        <select class="{{ $select }}" aria-label="Font" x-bind:value="fmt.font" x-on:change="applyFont($event.target.value)">
                            <option value="">Font</option>
                            @foreach ($fonts as $family => $label)
                                <option value="{{ $family }}">{{ $label }}</option>
                            @endforeach
                            <option value="other" disabled>Other</option>
                            <option value="mixed" disabled>Mixed</option>
                        </select>

                        <select class="{{ $select }}" aria-label="Text size" x-bind:value="fmt.size" x-on:change="applySize($event.target.value)">
                            <option value="">Size</option>
                            @foreach ($sizes as $step => $label)
                                <option value="{{ $step }}">{{ $label }}</option>
                            @endforeach
                            <option value="other" disabled>Other</option>
                            <option value="mixed" disabled>Mixed</option>
                        </select>

                        @foreach ([['bold', 'Bold (Ctrl + B)', 'B', 'font-extrabold', 'bold'], ['italic', 'Italic (Ctrl + I)', 'I', 'italic font-serif', 'italic'], ['underline', 'Underline (Ctrl + U)', 'U', 'underline', 'underline'], ['strikeThrough', 'Strikethrough', 'S', 'line-through', 'strike']] as [$command, $title, $glyph, $style, $key])
                            <button type="button" x-on:click="exec('{{ $command }}')" title="{{ $title }}" aria-label="{{ Str::before($title, ' (') }}"
                                    x-bind:aria-pressed="fmt.{{ $key }}" x-bind:class="{ '{{ $on }}': fmt.{{ $key }} }"
                                    class="interactive {{ $btn }} text-body {{ $style }}">{{ $glyph }}</button>
                        @endforeach
                        <button type="button" x-on:click="exec('superscript')" title="Superscript" aria-label="Superscript"
                                x-bind:aria-pressed="fmt.sup" x-bind:class="{ '{{ $on }}': fmt.sup }" class="interactive {{ $btn }} text-meta font-bold">x<sup class="text-tiny">2</sup></button>
                        <button type="button" x-on:click="exec('subscript')" title="Subscript" aria-label="Subscript"
                                x-bind:aria-pressed="fmt.sub" x-bind:class="{ '{{ $on }}': fmt.sub }" class="interactive {{ $btn }} text-meta font-bold">x<sub class="text-tiny">2</sub></button>
                    </div>

                    {{-- Colour and highlight. Native pickers for anything, with a few swatches in front so the common
                         case is one click. The one the selection has is ringed. --}}
                    <div class="{{ $row }}">
                        <div class="flex shrink-0 items-center gap-0.5">
                            @foreach ($swatches as $colour)
                                <button type="button"
                                        x-on:click="exec('foreColor', '{{ $colour }}')"
                                        x-bind:class="{ 'ring-2 ring-ink ring-offset-1': fmt.color === '{{ $colour }}' }"
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
                            <span class="text-tiny font-semibold text-ink-400" x-show="fmt.color === 'mixed'" x-cloak>Mixed</span>
                        </div>

                        <span class="mx-1 h-5 w-px shrink-0 bg-surface-line" aria-hidden="true"></span>

                        <div class="flex shrink-0 items-center gap-0.5">
                            @foreach ($highlights as $colour)
                                <button type="button"
                                        x-on:click="exec('hiliteColor', '{{ $colour }}')"
                                        x-bind:class="{ 'ring-2 ring-ink ring-offset-1': fmt.highlight === '{{ $colour }}' }"
                                        title="Highlight {{ $colour }}"
                                        aria-label="Highlight {{ $colour }}"
                                        class="size-5 rounded-[6px] border border-surface-line interactive hover:border-ink-300"
                                        style="background-color: {{ $colour }}"></button>
                            @endforeach
                            <button type="button" x-on:click="exec('hiliteColor', '#ffffff')" title="Remove highlight"
                                    aria-label="Remove highlight" class="interactive {{ $pill }} !px-2 !text-label">None</button>
                            <span class="text-tiny font-semibold text-ink-400" x-show="fmt.highlight === 'mixed'" x-cloak>Mixed</span>
                        </div>

                        <button type="button" x-on:click="exec('removeFormat')" title="Clear formatting of the selection" aria-label="Clear formatting"
                                class="interactive {{ $pill }} !text-ink-400">Clear</button>
                    </div>
                </div>
                <span class="{{ $name }}">Font</span>
            </div>

            {{-- PARAGRAPH --}}
            <div class="{{ $group }}" role="group" aria-label="Paragraph">
                <div class="flex flex-col gap-1">
                    <div class="{{ $row }}">
                        @foreach ([['justifyLeft', 'Align left (Ctrl + L)', 'align-left', 'left'], ['justifyCenter', 'Centre (Ctrl + E)', 'align-center', 'center'], ['justifyRight', 'Align right (Ctrl + R)', 'align-right', 'right'], ['justifyFull', 'Justify (Ctrl + J)', 'align-justify', 'justify']] as [$command, $title, $glyph, $value])
                            <button type="button" x-on:click="exec('{{ $command }}')" title="{{ $title }}" aria-label="{{ Str::before($title, ' (') }}"
                                    x-bind:aria-pressed="fmt.align === '{{ $value }}'" x-bind:class="{ '{{ $on }}': fmt.align === '{{ $value }}' }"
                                    class="interactive {{ $btn }}">
                                <x-icon :name="$glyph" size="15" />
                            </button>
                        @endforeach

                        <button type="button" x-on:click="exec('insertUnorderedList')" title="Bulleted list (Ctrl + Shift + 8)" aria-label="Bulleted list"
                                x-bind:aria-pressed="fmt.bullets" x-bind:class="{ '{{ $on }}': fmt.bullets }" class="interactive {{ $btn }}">
                            <x-icon name="list" size="16" />
                        </button>
                        <button type="button" x-on:click="exec('insertOrderedList')" title="Numbered list (Ctrl + Shift + 7)" aria-label="Numbered list"
                                x-bind:aria-pressed="fmt.numbers" x-bind:class="{ '{{ $on }}': fmt.numbers }" class="interactive {{ $btn }}">
                            <x-icon name="list-ordered" size="16" />
                        </button>
                        <button type="button" x-on:click="exec('formatBlock', 'blockquote')" title="Quote" aria-label="Quote" class="interactive {{ $btn }}">
                            <x-icon name="quote" size="16" />
                        </button>
                    </div>

                    <div class="{{ $row }}">
                        <button type="button" x-on:click="indent(-1)" title="Decrease indent (1 cm)" aria-label="Decrease indent" class="interactive {{ $pill }}">&minus; Indent</button>
                        <button type="button" x-on:click="indent(1)" title="Increase indent (1 cm)" aria-label="Increase indent" class="interactive {{ $pill }}">+ Indent</button>
                        <button type="button" x-on:click="toggleFirstLine()" title="First-line indent (1.25 cm)" aria-label="First-line indent"
                                x-bind:aria-pressed="fmt.firstLine" x-bind:class="{ '{{ $on }}': fmt.firstLine }" class="interactive {{ $pill }}">First line</button>
                        <span class="text-tiny font-semibold text-ink-400" x-show="fmt.indent === 'mixed'" x-cloak>Mixed indent</span>
                    </div>
                </div>
                <span class="{{ $name }}">Paragraph</span>
            </div>

            {{-- SPACING: line spacing, then the space before and after the paragraph --}}
            <div class="{{ $group }}" role="group" aria-label="Spacing">
                <div class="flex flex-col gap-1">
                    <div class="{{ $row }}">
                        <select class="{{ $select }}" aria-label="Line spacing" title="Line spacing" x-bind:value="fmt.spacing" x-on:change="setSpacing($event.target.value)">
                            <option value="">Choose spacing</option>
                            @foreach ($spacings as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                            <option value="compact">Compact, no gaps</option>
                            <option value="custom" x-text="fmt.spacing === 'custom' ? 'Custom (' + fmt.customSpacing + ')' : 'Custom…'">Custom…</option>
                            <option value="mixed" disabled>Mixed</option>
                        </select>
                    </div>

                    <div class="{{ $row }}">
                        <select class="{{ $select }}" aria-label="Space before paragraph" title="Space before the paragraph"
                                x-bind:value="fmt.before" x-on:change="setSpace('top', $event.target.value)">
                            <option value="">Before</option>
                            @foreach ([0, 6, 12, 18] as $points)
                                <option value="{{ $points }}">Before {{ $points }} pt</option>
                            @endforeach
                            <option value="mixed" disabled>Mixed</option>
                        </select>

                        <select class="{{ $select }}" aria-label="Space after paragraph" title="Space after the paragraph"
                                x-bind:value="fmt.after" x-on:change="setSpace('bottom', $event.target.value)">
                            <option value="">After</option>
                            @foreach ([0, 6, 12, 18] as $points)
                                <option value="{{ $points }}">After {{ $points }} pt</option>
                            @endforeach
                            <option value="mixed" disabled>Mixed</option>
                        </select>
                    </div>
                </div>
                <span class="{{ $name }}">Spacing</span>
            </div>

            {{-- STYLES --}}
            <div class="{{ $group }}" role="group" aria-label="Styles">
                <div class="flex flex-col gap-1">
                    @foreach ([[['p', 'Text', 'Normal text (Ctrl + Alt + 0)', 'Normal text'], ['h1', 'H1', 'Title heading (Ctrl + Alt + 1)', 'Title heading (H1)']], [['h2', 'H2', 'Heading (Ctrl + Alt + 2)', 'Heading (H2)'], ['h3', 'H3', 'Subheading (Ctrl + Alt + 3)', 'Subheading (H3)']]] as $pair)
                        <div class="{{ $row }}">
                            @foreach ($pair as [$tag, $glyph, $title, $aria])
                                <button type="button" x-on:click="exec('formatBlock', '{{ $tag }}')" title="{{ $title }}" aria-label="{{ $aria }}"
                                        x-bind:aria-pressed="fmt.heading === '{{ $tag }}'" x-bind:class="{ '{{ $on }}': fmt.heading === '{{ $tag }}' }"
                                        class="interactive {{ $pill }} min-w-[3rem] justify-center {{ $tag === 'p' ? '' : 'font-extrabold' }}">{{ $glyph }}</button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <span class="{{ $name }}">Styles</span>
            </div>

            {{-- INSERT: things that are put into the document rather than applied to text --}}
            <div class="{{ $group }}" role="group" aria-label="Insert">
                <div class="flex flex-col gap-1">
                    <div class="{{ $row }}">
                        <button type="button" x-on:click="insertTable()" title="Insert a table" class="interactive {{ $pill }}">
                            <x-icon name="table" size="15" />Table
                        </button>

                        @if ($uploadUrl !== '')
                            <label class="interactive {{ $pill }} cursor-pointer" title="Insert a picture (PNG, JPG or WebP, up to 2 MB)"
                                   :class="uploading ? 'pointer-events-none opacity-60' : ''">
                                <x-icon name="image" size="15" />
                                <span x-text="uploading ? 'Uploading…' : 'Image'">Image</span>
                                <input type="file" class="sr-only" accept="image/png,image/jpeg,image/webp"
                                       aria-label="Choose a picture to insert" x-on:change="insertImage($event)">
                            </label>
                        @endif
                    </div>

                    <div class="{{ $row }}">
                        <button type="button" x-on:click="insertLink()" title="Insert a link (http or https)" class="interactive {{ $pill }}">
                            <x-icon name="link" size="15" />Link
                        </button>

                        <button type="button" x-on:click="insertToc()" x-show="! hasToc" title="Add a table of contents built from H1, H2 and H3 headings" class="interactive {{ $pill }}">
                            <x-icon name="list" size="15" />Table of contents
                        </button>
                        <button type="button" x-on:click="refreshToc()" x-show="hasToc" x-cloak title="Rebuild the table of contents from the current headings" class="interactive {{ $pill }}">
                            <x-icon name="list" size="15" />Update contents
                        </button>
                        <button type="button" x-on:click="removeToc()" x-show="hasToc" x-cloak title="Remove the table of contents" aria-label="Remove contents" class="interactive {{ $btn }} !text-danger-text"><x-icon name="trash" size="15" /></button>
                    </div>
                </div>
                <span class="{{ $name }}">Insert</span>
            </div>

            {{-- PICTURE: appears only while a picture is picked --}}
            <template x-if="picked">
                <div class="{{ $group }}" role="group" aria-label="Picture">
                    <div class="flex flex-col gap-1">
                        <div class="{{ $row }}">
                            @foreach (['small' => 'Small', 'medium' => 'Medium', 'full' => 'Full'] as $size => $sizeLabel)
                                <button type="button" x-on:click="sizePicture('{{ $size }}')" :aria-pressed="pickedSize === '{{ $size }}'"
                                        :class="pickedSize === '{{ $size }}' ? 'bg-ink text-white' : ''"
                                        class="interactive {{ $pill }}">{{ $sizeLabel }}</button>
                            @endforeach
                        </div>
                        <div class="{{ $row }}">
                            <button type="button" x-on:click="toggleCaption()" class="interactive {{ $pill }}"
                                    x-text="pickedCaption ? 'Remove caption' : 'Add caption'">Add caption</button>
                            <button type="button" x-on:click="removePicture()" class="interactive {{ $pill }} !text-danger-text">Remove</button>
                        </div>
                    </div>
                    <span class="{{ $name }}">Picture</span>
                </div>
            </template>

        </div>

        <p x-show="notice" x-cloak x-text="notice" role="status"
           class="border-b border-surface-line bg-surface px-[18px] py-2 text-meta font-semibold text-ink-600"></p>
        {{-- The editable surface --}}
        {{-- One A4 sheet at a time. A sideways two-finger swipe over the desk moves one page. The second sheet is
             hidden and only used to measure how much of a page fits. --}}
        <div class="doc-stage" x-on:wheel="onWheel($event)">
            <div class="doc-viewport">

                {{-- Left of the sheet: the previous page peeks out, with up to three edge layers behind it. Nothing at all
                     before page 1. These are read-only miniatures: inert (no focus, no editing, no pointer), hidden from
                     screen readers, and only the two neighbours are ever drawn. --}}
                <div class="doc-side doc-side-left" aria-hidden="true">
                    <template x-for="n in plan.edgesLeft" :key="'l' + n">
                        <span class="doc-edge" x-bind:style="'--layer: ' + n"></span>
                    </template>
                    <div class="doc-peek doc-peek-left" x-show="plan.previous !== null" x-cloak x-on:click="goto(current - 1)" data-peek="previous">
                        <div class="doc-peek-inner doc-body" inert tabindex="-1" x-html="peekPrev"></div>
                    </div>
                </div>

                <div x-ref="editor"
                     contenteditable="true"
                     role="textbox"
                     aria-multiline="true"
                     aria-label="{{ $label }}"
                     x-on:input="onInput($event)"
                     x-on:beforeinput="onBeforeInput($event)"
                     x-on:blur="sync()"
                     x-on:click="pick($event)"
                     x-on:keydown="onKey($event)"
                     x-on:paste="pastePlain($event)"
                     x-on:load.capture="onImageLoad()"
                     class="doc-body doc-page text-ink outline-none"></div>

                {{-- Right of the sheet: the next page, or on the last page a faint new sheet with a plus. --}}
                <div class="doc-side doc-side-right" aria-hidden="true">
                    <div class="doc-peek doc-peek-right" x-show="plan.next !== null" x-cloak x-on:click="goto(current + 1)" data-peek="next">
                        <div class="doc-peek-inner doc-body" inert tabindex="-1" x-html="peekNext"></div>
                    </div>
                    <div class="doc-peek doc-peek-new" x-show="plan.newSheet" x-cloak x-on:click="appendPage()" data-peek="new" title="Add a page at the end">
                        <span class="doc-peek-plus">+</span>
                    </div>
                    <template x-for="n in plan.edgesRight" :key="'r' + n">
                        <span class="doc-edge" x-bind:style="'--layer: ' + n"></span>
                    </template>
                </div>

                <div x-ref="measure" aria-hidden="true" class="doc-body doc-page doc-measure"></div>
            </div>
        </div>
        {{-- Page controls: centred under the sheet (the same three columns as the stage, so they line up with it and
             never sit under the peeking pages), outside the part that slides, so only the sheets move. Sticky to the
             bottom of the editor area, so on a long page they are still in reach. --}}
        <div class="doc-controls sticky bottom-0 z-10" x-data="{ jump: false }">
            <div class="doc-controls-grid">
                <div class="relative col-start-2 flex flex-wrap items-center justify-center gap-2" role="group" aria-label="Pages"
                     x-on:keydown.escape.window="jump = false">
                    <button type="button" x-on:click="prev()" :disabled="current === 0" title="Previous page (Alt + Left)" aria-label="Previous page"
                            class="interactive {{ $btn }} disabled:pointer-events-none disabled:opacity-40">
                        <x-icon name="arrow-left" size="15" />
                    </button>

                    {{-- The page indicator opens the list of pages, to jump straight to one. --}}
                    <button type="button" x-on:click="jump = ! jump" x-bind:aria-expanded="jump" :disabled="pages.length < 2"
                            title="Go to a page" aria-label="Go to a page"
                            class="interactive {{ $pill }} min-w-[104px] justify-center disabled:pointer-events-none" aria-live="polite">
                        Page <span x-text="current + 1"></span> of <span x-text="pages.length"></span>
                    </button>

                    <button type="button" x-on:click="next()" :disabled="current >= pages.length - 1" title="Next page (Alt + Right)" aria-label="Next page"
                            class="interactive {{ $btn }} disabled:pointer-events-none disabled:opacity-40">
                        <x-icon name="arrow-right" size="15" />
                    </button>

                    <span class="mx-0.5 h-5 w-px shrink-0 bg-surface-line" aria-hidden="true"></span>

                    <button type="button" x-on:click="newPage()" title="Add a page after this one; text after the cursor moves onto it"
                            class="interactive {{ $pill }}"><x-icon name="plus" size="14" />New page</button>
                    <button type="button" x-on:click="deletePage()" :disabled="pages.length < 2" title="Delete this page"
                            class="interactive {{ $pill }} !text-ink-400 hover:!bg-danger-bg hover:!text-danger-text disabled:pointer-events-none disabled:opacity-40">Delete page</button>

                    <div x-show="jump" x-cloak x-on:click.outside="jump = false" role="group" aria-label="Jump to a page"
                         class="absolute bottom-full left-1/2 z-20 mb-2 flex max-h-48 w-max max-w-[22rem] -translate-x-1/2 flex-wrap justify-center gap-1 overflow-y-auto rounded-card border border-surface-line bg-surface p-2 shadow-raised">
                        <template x-for="(page, i) in pages" :key="i">
                            <button type="button" x-on:click="goto(i); jump = false" :title="'Page ' + (i + 1)" :aria-label="'Go to page ' + (i + 1)"
                                    :aria-current="i === current ? 'page' : false"
                                    :class="i === current ? 'bg-ink text-white' : 'text-ink-600 hover:bg-surface-hover'"
                                    class="interactive flex size-8 items-center justify-center rounded-pill text-meta font-semibold" x-text="i + 1"></button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- Live counts, in the editor's own footer so they belong to the text
             rather than floating somewhere on the page. --}}
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-surface-line bg-surface-soft px-[18px] py-2">
            <span class="text-label text-ink-400">Pasted text arrives unformatted, on purpose.</span>
            <span class="text-label font-semibold text-ink-500" aria-live="polite" data-counts>
                <span x-text="words"></span> <span x-text="words === 1 ? 'word' : 'words'"></span>
                ·
                <span x-text="characters"></span> <span x-text="characters === 1 ? 'character' : 'characters'"></span>
            </span>
        </div>
    </div>
    {{-- What actually gets posted. --}}
    <input type="hidden" name="{{ $name }}" :value="content">
</div>
