import {
    splitPages, joinPages, splitPageAtCaret, cascadeOverflow, isPageEmpty, removePage, textOf, peekPlan,
} from './document-pages'
import {
    withStyle, parseStyle, commonValue, clampLineHeight, spacingOf, stepIndent, applyToBlocks, firstLineChanges,
    indentOf, spaceOf, sizeStep, hexOf, lengthOf, COMPACT, isCompact,
} from './document-format'

/*
 * The Documents editor: a paged, book-like contenteditable plus a toolbar, no editor library.
 *
 * The stored body is ONE html string. The editor shows one A4 sheet at a time; pages are the segments between
 * page-break markers (document-pages.js has the pure split/join/overflow logic and is tested with Node).
 * Only the page on show is editable; the other pages are strings. A hidden sheet of the same size is used to
 * measure how much of a page fits, so overflow is measured only on the page being edited and the pages after it.
 *
 * Kept in a module rather than inline in the x-data attribute: the code carries quotes of both kinds, which
 * would end an attribute early. The view passes only a small config: { value, uploadUrl, csrf }.
 *
 * Everything this emits is sanitised again on the server when saved, so this is convenience and never the
 * trust boundary (App\Support\DocumentSanitizer).
 */

const HISTORY_LIMIT = 100
const SLIDE_MS = 320
const EASE = 'cubic-bezier(0.22, 1, 0.36, 1)'
const EMPTY_PAGE = '<p><br></p>'
const INLINE = ['A', 'B', 'I', 'U', 'S', 'EM', 'STRONG', 'SPAN', 'FONT', 'STRIKE', 'SUB', 'SUP']
const WHOLE_BLOCKS = 'blockquote[data-toc], p[data-page-break], figure'
const BLOCKS = 'p, h1, h2, h3, li, blockquote, td, th'
const FONT_LABELS = {
    'plus jakarta sans': 'Plus Jakarta Sans', arial: 'Arial', georgia: 'Georgia', 'times new roman': 'Times New Roman', 'courier new': 'Courier New',
}
const EMPTY_FORMAT = {
    bold: false, italic: false, underline: false, strike: false, sup: false, sub: false, bullets: false, numbers: false,
    font: '', size: '', color: '', highlight: '', align: '', heading: '', spacing: '', customSpacing: null,
    before: '', after: '', indent: 0, firstLine: false,
}

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches

export default (config) => ({
    pages: splitPages(config.value ?? ''),
    current: 0,
    content: config.value ?? '',
    original: config.value ?? '',
    baseline: '',
    words: 0,
    characters: 0,
    notice: '',
    noticeTimer: null,
    picked: null,
    pickedSize: '',
    pickedCaption: false,
    hasToc: false,
    uploading: false,
    touched: false,

    history: [],
    historyAt: 0,
    recordTimer: null,

    slide: null,
    wheelAt: 0,
    wheelSwitchedAt: 0,

    range: null,
    fmt: { ...EMPTY_FORMAT },
    fmtFrame: 0,
    selectionListener: null,

    plan: peekPlan(0, 1),
    peekPrev: '',
    peekNext: '',
    peekTimer: null,

    destroy() {
        clearTimeout(this.noticeTimer)
        clearTimeout(this.recordTimer)
        clearTimeout(this.peekTimer)
        cancelAnimationFrame(this.fmtFrame)
        if (this.selectionListener) document.removeEventListener('selectionchange', this.selectionListener)
        this.cancelSlide()
    },

    init() {
        this.baseline = joinPages(this.pages)
        this.load(0)

        /* Paragraphs, not <div>, when Enter is pressed. */
        try { document.execCommand('defaultParagraphSeparator', false, 'p') } catch (e) { /* older engines */ }

        /* Emit spans carrying inline styles rather than <font> tags.
           Without this, execCommand produces markup from 1997 that the
           sanitizer does not allow and the formatting vanishes on save. */
        try { document.execCommand('styleWithCSS', false, true) } catch (e) { /* older engines */ }

        this.commitContent()
        this.updateCounts()
        this.queuePeeks(true)
        this.history = [this.snapshot()]

        /* The toolbar shows what the selection has, and keeps the selection when a control takes the focus. */
        this.selectionListener = () => this.onSelectionChange()
        document.addEventListener('selectionchange', this.selectionListener)

        /* An older document has no page markers: once fonts are in and the sheet can be measured, it is flowed
           into pages by the same rule as typing. The saved body is only replaced if the writer then edits. */
        const flow = () => requestAnimationFrame(() => this.flowAll())
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(flow) } else { flow() }
    },

    /* ----- The page on show ----- */

    load(index) {
        const editor = this.$refs.editor

        editor.innerHTML = this.pages[index] || EMPTY_PAGE
        this.tidy(editor)
        editor.scrollTop = 0
        this.picked = null
        this.pickedSize = ''
    },

    /* Loose text, inline tags and line breaks at the top of the sheet belong in paragraphs, so every top-level child is
       a block. This is how a document saved as plain text (lines separated by line breaks) is brought in: each line
       stays a line of the same paragraph and each blank line stays one blank line, with no extra gap added, so
       the spacing is exactly what it was. */
    tidy(el) {
        let run = []

        const flush = () => {
            if (run.length === 0) return

            const nodes = run
            run = []

            const lines = [[]]

            nodes.forEach((node) => {
                if (node.nodeName === 'BR') { lines.push([]) } else { lines[lines.length - 1].push(node) }
            })

            if (lines.length > 1 && lines[lines.length - 1].length === 0) lines.pop()

            const made = []
            let paragraph = null

            lines.forEach((line) => {
                const blank = line.every((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim() === '')

                if (blank) {
                    paragraph = null

                    const empty = document.createElement('p')
                    empty.style.marginBottom = '0pt'
                    empty.appendChild(document.createElement('br'))
                    made.push(empty)

                    return
                }

                if (! paragraph) {
                    paragraph = document.createElement('p')
                    paragraph.style.marginBottom = '0pt'
                    made.push(paragraph)
                } else {
                    paragraph.appendChild(document.createElement('br'))
                }

                line.forEach((node) => paragraph.appendChild(node))
            })

            const before = nodes[0]

            made.forEach((block) => el.insertBefore(block, before))
            nodes.forEach((node) => node.remove())
        }

        for (const node of [...el.childNodes]) {
            const text = node.nodeType === Node.TEXT_NODE
            const loose = (text && node.textContent.trim() !== '')
                || (node.nodeType === Node.ELEMENT_NODE && (INLINE.includes(node.tagName) || node.tagName === 'BR'))

            // White space between loose pieces belongs to the run; white space on its own is nothing.
            if (loose || (text && run.length > 0)) { run.push(node) } else { flush() }
        }

        flush()
    },

    /* A sheet's html as it is stored: the picked-picture outline removed, and an empty sheet stored as nothing. */
    serialise(el = this.$refs.editor) {
        const copy = el.cloneNode(true)

        copy.querySelectorAll('figure.is-picked').forEach((figure) => {
            figure.classList.remove('is-picked')
            if (! figure.getAttribute('class')) figure.removeAttribute('class')
        })

        copy.querySelectorAll('figcaption').forEach((caption) => {
            if (caption.textContent.trim() === '') caption.remove()
        })

        const html = copy.innerHTML

        return /^(<p><br\s*\/?><\/p>|<br\s*\/?>|\s)*$/.test(html) ? '' : html
    },

    /* Until the writer changes something, the page strings stay exactly as they were opened (so saving with no edits
       is a no-op, whatever the document looked like when it was written). */
    syncCurrent() {
        if (! this.touched) return

        this.tidy(this.$refs.editor)
        this.pages[this.current] = this.serialise()
    },

    /* What the form posts. While the body is exactly what it was when the page was opened, the original is sent
       back untouched, so saving without editing is a no-op. */
    commitContent() {
        const joined = joinPages(this.pages)

        this.content = joined === this.baseline ? this.original : joined
        this.hasToc = this.pages.some((html) => html.includes('data-toc'))
        this.queuePeeks()
    },

    sync() {
        this.syncCurrent()
        this.commitContent()
        this.updateCounts()
    },

    /* The whole document, not the page on show. */
    updateCounts() {
        const text = this.pages.map((html) => textOf(html).trim()).filter(Boolean)

        this.characters = text.reduce((n, page) => n + page.length, 0)
        this.words = text.reduce((n, page) => n + page.split(/\s+/).length, 0)
    },

    onInput(event) {
        this.touched = true
        if (event && event.isComposing) return

        this.sync()
        this.reflowLive()
        this.queueRecord()
    },

    /* Anything that changes the page from the toolbar. */
    afterChange() {
        this.touched = true
        this.sync()
        this.reflowLive()
        this.record()
    },

    exec(command, argument = null) {
        this.restoreRange()
        document.execCommand(command, false, argument)
        this.afterChange()
        this.readFormat()
    },

    /* ----- Formatting that follows the selection ----- */

    /* The toolbar's buttons must not take the focus (that would drop the selection); selects and the colour pickers
       do, so the selection is remembered as it changes and put back before anything is applied. */
    keepSelection(event) {
        if (event.target.closest('button') && ! event.target.closest('select, input, label')) event.preventDefault()
    },

    onSelectionChange() {
        const selection = window.getSelection()
        if (! selection.rangeCount) return

        const range = selection.getRangeAt(0)
        if (! this.$refs.editor.contains(range.commonAncestorContainer)) return

        this.range = range.cloneRange()

        cancelAnimationFrame(this.fmtFrame)
        this.fmtFrame = requestAnimationFrame(() => this.readFormat())
    },

    currentRange() {
        const selection = window.getSelection()

        if (selection.rangeCount && this.$refs.editor.contains(selection.getRangeAt(0).commonAncestorContainer)) {
            return selection.getRangeAt(0)
        }

        return this.range
    },

    /* Puts the focus and the remembered selection back in the sheet. */
    restoreRange() {
        this.$refs.editor.focus({ preventScroll: true })

        if (! this.caretInEditor() && this.range) {
            const selection = window.getSelection()
            selection.removeAllRanges()
            selection.addRange(this.range)
        }
    },

    /* The blocks (paragraphs, headings, list items, quotes, cells) the selection touches, innermost only. */
    touchedBlocks() {
        const range = this.currentRange()
        if (! range) return []

        const found = [...this.$refs.editor.querySelectorAll(BLOCKS)]
            .filter((el) => ! el.closest('blockquote[data-toc]') && range.intersectsNode(el))

        return found.filter((el) => ! found.some((other) => other !== el && el.contains(other)))
    },

    /* The elements that carry the text the selection covers (or the caret's own element). */
    textElements() {
        const range = this.currentRange()
        if (! range) return []

        if (range.collapsed) {
            const node = range.startContainer

            return [node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement].filter(Boolean)
        }

        const walker = document.createTreeWalker(this.$refs.editor, NodeFilter.SHOW_TEXT)
        const found = []
        let node

        while ((node = walker.nextNode()) && found.length < 300) {
            if (node.textContent.trim() !== '' && range.intersectsNode(node)) found.push(node.parentElement)
        }

        return found.length ? found : [range.startContainer.nodeType === Node.ELEMENT_NODE ? range.startContainer : range.startContainer.parentElement]
    },

    highlightOf(element) {
        for (let el = element; el && el !== this.$refs.editor; el = el.parentElement) {
            const colour = hexOf(getComputedStyle(el).backgroundColor)

            if (colour) return colour
        }

        return ''
    },

    /* Reads what the selection has, for the toolbar: one value, or 'mixed' when the selection has several. */
    readFormat() {
        if (! this.currentRange()) return

        const state = (command) => { try { return document.queryCommandState(command) } catch (e) { return false } }
        const texts = this.textElements().filter((el) => this.$refs.editor.contains(el))
        const blocks = this.touchedBlocks()
        const styles = texts.map((el) => getComputedStyle(el))
        const objects = blocks.map((el) => ({ style: el.getAttribute('style') || '' }))
        const spacing = spacingOf(blocks.map((el) => {
            const style = getComputedStyle(el)

            return Number.parseFloat(style.lineHeight) / Number.parseFloat(style.fontSize)
        }))

        this.fmt = {
            bold: state('bold'),
            italic: state('italic'),
            underline: state('underline'),
            strike: state('strikeThrough'),
            sup: state('superscript'),
            sub: state('subscript'),
            bullets: state('insertUnorderedList'),
            numbers: state('insertOrderedList'),
            font: commonValue(styles.map((s) => FONT_LABELS[s.fontFamily.split(',')[0].replace(/["']/g, '').trim().toLowerCase()] ?? 'other')),
            size: commonValue(styles.map((s) => sizeStep(Number.parseFloat(s.fontSize)) || 'other')),
            color: commonValue(styles.map((s) => hexOf(s.color) || 'other')),
            highlight: commonValue(texts.map((el) => this.highlightOf(el) || 'none')),
            align: commonValue(blocks.map((el) => { const a = getComputedStyle(el).textAlign; return a === 'start' ? 'left' : a === 'end' ? 'right' : a })),
            heading: commonValue(blocks.map((el) => (['H1', 'H2', 'H3'].includes(el.tagName) ? el.tagName.toLowerCase() : el.tagName === 'P' ? 'p' : 'other'))),
            spacing: objects.length > 0 && objects.every((o) => isCompact(o.style)) ? 'compact' : spacing.value,
            customSpacing: spacing.custom,
            before: spaceOf(objects, 'margin-top'),
            after: spaceOf(objects, 'margin-bottom'),
            indent: indentOf(objects),
            firstLine: objects.length > 0 && objects.every((o) => lengthOf(parseStyle(o.style)['text-indent'], 'cm') > 0),
        }
    },

    /* Applies style changes to every block the selection touches, and to no other. */
    applyBlocks(changes) {
        this.restoreRange()

        const blocks = this.touchedBlocks()
        if (blocks.length === 0) return

        const updated = applyToBlocks(blocks.map((el) => ({ style: el.getAttribute('style') || '' })), blocks.map((_, i) => i), changes)

        blocks.forEach((el, i) => { if (updated[i].style) { el.setAttribute('style', updated[i].style) } else { el.removeAttribute('style') } })

        this.afterChange()
        this.restoreRange()
        this.readFormat()
    },

    setSpacing(value) {
        if (value === 'mixed' || value === '') return
        if (value === 'custom') return this.customSpacing()
        if (value === 'compact') return this.applyBlocks({ ...COMPACT })

        this.applyBlocks({ 'line-height': value })
    },

    customSpacing() {
        const typed = window.prompt('Line spacing, a number from 1.0 to 3.0', this.fmt.customSpacing ?? '1.5')
        if (typed === null) return

        const number = clampLineHeight(typed)

        if (number === null) return this.say('Line spacing must be a number from 1.0 to 3.0.')

        this.applyBlocks({ 'line-height': String(number) })
    },

    setSpace(side, points) {
        if (points === 'mixed') return

        this.applyBlocks({ ['margin-' + side]: points === '' ? null : points + 'pt' })
    },

    /* A centimetre more or less, block by block, from each block's own indent. */
    indent(direction) {
        this.restoreRange()

        const blocks = this.touchedBlocks()
        if (blocks.length === 0) return

        blocks.forEach((el) => {
            const next = stepIndent(lengthOf(parseStyle(el.getAttribute('style'))['margin-left'], 'cm'), direction)
            const style = withStyle(el.getAttribute('style') || '', { 'margin-left': next > 0 ? next + 'cm' : null })

            if (style) { el.setAttribute('style', style) } else { el.removeAttribute('style') }
        })

        this.afterChange()
        this.restoreRange()
        this.readFormat()
    },

    toggleFirstLine() {
        const blocks = this.touchedBlocks().map((el) => ({ style: el.getAttribute('style') || '' }))

        this.applyBlocks(firstLineChanges(blocks))
    },

    applyFont(value) {
        if (value && value !== 'mixed' && value !== 'other') this.exec('fontName', value)
    },

    applySize(value) {
        if (value && value !== 'mixed' && value !== 'other') this.exec('fontSize', value)
    },

    stepSize(direction) {
        const current = Number(this.fmt.size) || 3

        this.exec('fontSize', String(Math.min(6, Math.max(2, current + direction))))
    },
    insertTable() {
        const cell = '<td>&nbsp;</td>'
        const row = '<tr>' + cell.repeat(3) + '</tr>'
        const head = '<tr><th>&nbsp;</th><th>&nbsp;</th><th>&nbsp;</th></tr>'

        this.exec('insertHTML', '<table><thead>' + head + '</thead><tbody>' + row.repeat(2) + '</tbody></table><p><br></p>')
    },

    say(message) {
        this.notice = message
        clearTimeout(this.noticeTimer)
        this.noticeTimer = setTimeout(() => this.notice = '', 6000)
    },

    /* ----- Caret ----- */

    blockOf(node) {
        const editor = this.$refs.editor

        while (node && node.parentNode !== editor) node = node.parentNode

        return node && node.nodeType === Node.ELEMENT_NODE ? node : null
    },

    caretInEditor() {
        const selection = window.getSelection()

        return selection.rangeCount > 0 && this.$refs.editor.contains(selection.anchorNode)
    },

    /* Characters of text between the start of `root` and the caret. */
    textOffsetWithin(root) {
        const selection = window.getSelection()
        const range = document.createRange()

        range.selectNodeContents(root)
        range.setEnd(selection.anchorNode, selection.anchorOffset)

        return range.toString().length
    },

    placeCaret(root, offset) {
        const range = document.createRange()
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
        let left = offset
        let node

        range.selectNodeContents(root)
        range.collapse(true)

        if (offset > 0) {
            range.collapse(false)

            while ((node = walker.nextNode())) {
                if (left <= node.length) {
                    range.setStart(node, left)
                    range.collapse(true)
                    break
                }

                left -= node.length
            }
        }

        const selection = window.getSelection()
        selection.removeAllRanges()
        selection.addRange(range)
    },

    /* ----- Moving between pages ----- */

    cancelSlide() {
        if (! this.slide) return

        this.slide.animations.forEach((animation) => animation.cancel())
        this.slide.ghost.remove()
        this.slide = null
    },

    /* A still copy of the sheet that is leaving, so it can slide away while the real sheet slides in. */
    ghostOf(editor) {
        const ghost = editor.cloneNode(true)

        for (const name of ghost.getAttributeNames()) {
            if (name.startsWith('x-') || name.startsWith('@') || name.startsWith(':') || name === 'contenteditable' || name === 'id') {
                ghost.removeAttribute(name)
            }
        }

        ghost.setAttribute('aria-hidden', 'true')
        ghost.style.cssText = `position:absolute;margin:0;pointer-events:none;left:${editor.offsetLeft}px;top:${editor.offsetTop}px;width:${editor.offsetWidth}px;height:${editor.offsetHeight}px`
        editor.parentNode.appendChild(ghost)

        return ghost
    },

    /* Shows page `index`. Direction is -1, 0 or 1; 0 (or reduced motion) changes the sheet at once. */
    show(index, { dir = 0, caret = 'start', instant = false } = {}) {
        const editor = this.$refs.editor

        this.cancelSlide()

        const animate = dir !== 0 && ! instant && ! reduced() && typeof editor.animate === 'function'
        const ghost = animate ? this.ghostOf(editor) : null

        this.current = index
        this.load(index)

        if (ghost) {
            const distance = (editor.offsetWidth + 40) * dir
            const timing = { duration: SLIDE_MS, easing: EASE, fill: 'both' }
            const entering = editor.animate([
                { transform: `translateX(${distance}px)`, opacity: 0.25 },
                { transform: 'translateX(0)', opacity: 1 },
            ], timing)
            const leaving = ghost.animate([
                { transform: 'translateX(0)', opacity: 1 },
                { transform: `translateX(${-distance}px)`, opacity: 0.25 },
            ], timing)

            this.slide = { ghost, animations: [entering, leaving] }
            leaving.onfinish = () => { if (this.slide && this.slide.ghost === ghost) this.cancelSlide() }
        }

        editor.focus({ preventScroll: true })

        if (caret === 'mark') {
            const target = editor.querySelector('[data-caret-mark]')

            if (target) {
                const offset = Number(target.getAttribute('data-caret-mark')) || 0
                target.removeAttribute('data-caret-mark')
                this.placeCaret(target, offset)
                this.pages[index] = this.serialise()
            }
        } else if (caret === 'end') {
            const range = document.createRange()
            range.selectNodeContents(editor)
            range.collapse(false)
            window.getSelection().removeAllRanges()
            window.getSelection().addRange(range)
        } else {
            this.placeCaret(editor.firstElementChild ?? editor, 0)
        }

        this.updateCounts()
        this.queuePeeks(true)
    },

    goto(index, options = {}) {
        if (index < 0 || index >= this.pages.length || index === this.current) return

        this.syncCurrent()
        this.show(index, { dir: index > this.current ? 1 : -1, ...options })
    },

    next() { this.goto(this.current + 1, { caret: 'start' }) },

    prev() { this.goto(this.current - 1, { caret: 'end' }) },

    /* A new page right after this one. Text after the cursor moves onto it, like a page break in Word. */
    newPage() {
        const editor = this.$refs.editor

        this.touched = true
        this.syncCurrent()

        const blocks = [...editor.children].map((node) => node.outerHTML)
        let index = blocks.length - 1
        let offset = Number.MAX_SAFE_INTEGER

        if (this.caretInEditor()) {
            const block = this.blockOf(window.getSelection().anchorNode)

            if (block) {
                index = [...editor.children].indexOf(block)
                offset = this.textOffsetWithin(block)
            }
        }

        const [stay, move] = splitPageAtCaret(blocks, index, offset)

        this.pages[this.current] = stay
        this.pages.splice(this.current + 1, 0, move)
        this.load(this.current)

        this.show(this.current + 1, { dir: 1, caret: 'start' })
        this.commitContent()
        this.record()
        this.say('New page added.')
    },

    deletePage() {
        if (this.pages.length <= 1) return this.say('The last page cannot be deleted.')

        this.syncCurrent()

        if (! isPageEmpty(this.pages[this.current])
            && ! window.confirm('Delete page ' + (this.current + 1) + '? Everything on it will be removed.')) {
            return
        }

        this.touched = true
        this.pages = removePage(this.pages, this.current)
        this.show(Math.min(this.current, this.pages.length - 1), { instant: true })
        this.commitContent()
        this.record()
    },

    /* ----- Overflow ----- */

    /* True when the last block of a sheet reaches below its bottom margin. */
    overflows(el) {
        const last = el.lastElementChild
        if (! last) return false

        const limit = el.getBoundingClientRect().top + el.clientHeight - parseFloat(getComputedStyle(el).paddingBottom) + el.scrollTop

        return last.getBoundingClientRect().bottom > limit + 1
    },

    /* Takes blocks off the end of a sheet until it fits; one block taller than a page stays alone. */
    takeOverflow(el) {
        const taken = []

        while (el.children.length > 1 && this.overflows(el)) {
            taken.unshift(el.removeChild(el.lastElementChild))
        }

        return taken
    },

    /* How much of `html` one page keeps, measured on the hidden sheet. */
    fit(html) {
        const sheet = this.$refs.measure

        sheet.innerHTML = html
        this.tidy(sheet)

        const moved = this.takeOverflow(sheet).map((node) => node.outerHTML).join('')

        return { kept: this.serialise(sheet), moved }
    },

    /* Typing or pasting made the page on show too tall: the blocks that no longer fit go to the next page, and the
       cursor goes with them if it was in one. Only this page and the pages it pushes into are measured. */
    reflowLive() {
        const editor = this.$refs.editor

        this.tidy(editor)

        if (! this.overflows(editor)) return

        let caretBlock = null
        let caretOffset = 0

        if (this.caretInEditor()) {
            caretBlock = this.blockOf(window.getSelection().anchorNode)
            if (caretBlock) caretOffset = this.textOffsetWithin(caretBlock)
        }

        const taken = this.takeOverflow(editor)
        if (taken.length === 0) return

        if (caretBlock && taken.includes(caretBlock)) caretBlock.setAttribute('data-caret-mark', String(caretOffset))

        this.pages[this.current] = this.serialise()
        this.pages = cascadeOverflow(this.pages, this.current + 1, taken.map((node) => node.outerHTML).join(''), (html) => this.fit(html))

        const target = this.pages.findIndex((html) => html.includes('data-caret-mark'))

        this.commitContent()
        this.updateCounts()

        if (target !== -1) {
            this.show(target, { dir: target > this.current ? 1 : -1, caret: 'mark' })
            this.commitContent()
        }

        this.record()
    },

    /* The page at `index` changed size elsewhere (the contents list): push any overflow forward. */
    refit(index) {
        const { kept, moved } = this.fit(this.pages[index])

        if (! moved) return

        this.pages[index] = kept
        this.pages = cascadeOverflow(this.pages, index + 1, moved, (html) => this.fit(html))

        if (index === this.current) this.load(index)
    },

    /* An older document with no markers is flowed into pages the first time it is opened. */
    flowAll() {
        if (this.touched) return

        const pages = [...this.pages]

        for (let i = 0; i < pages.length; i++) {
            const { kept, moved } = this.fit(pages[i])

            if (! moved) continue

            pages[i] = kept
            if (i + 1 < pages.length) { pages[i + 1] = moved + pages[i + 1] } else { pages.push(moved) }
        }

        if (pages.length === this.pages.length && pages.every((html, i) => html === this.pages[i])) return

        this.pages = pages
        this.baseline = joinPages(pages)
        this.load(this.current)
        this.commitContent()
        this.updateCounts()
        this.history = [this.snapshot()]
        this.historyAt = 0
    },

    /* ----- Undo and redo, across pages ----- */

    snapshot() {
        return {
            pages: [...this.pages],
            current: this.current,
            caret: this.caretInEditor() ? this.textOffsetWithin(this.$refs.editor) : null,
        }
    },

    queueRecord() {
        clearTimeout(this.recordTimer)
        this.recordTimer = setTimeout(() => this.record(), 500)
    },

    record() {
        clearTimeout(this.recordTimer)
        this.syncCurrent()

        const last = this.history[this.historyAt]

        if (last && last.current === this.current && joinPages(last.pages) === joinPages(this.pages)) return

        this.history = this.history.slice(0, this.historyAt + 1)
        this.history.push(this.snapshot())

        if (this.history.length > HISTORY_LIMIT) this.history.shift()

        this.historyAt = this.history.length - 1
    },

    restore(snapshot) {
        this.pages = [...snapshot.pages]
        this.current = Math.min(snapshot.current, this.pages.length - 1)
        this.load(this.current)
        this.$refs.editor.focus({ preventScroll: true })

        if (snapshot.caret !== null) this.placeCaret(this.$refs.editor, snapshot.caret)

        this.touched = true
        this.commitContent()
        this.updateCounts()
    },

    undo() {
        this.record()

        if (this.historyAt <= 0) return

        this.historyAt--
        this.restore(this.history[this.historyAt])
    },

    redo() {
        if (this.historyAt >= this.history.length - 1) return

        this.historyAt++
        this.restore(this.history[this.historyAt])
    },

    /* Ctrl+Z and Ctrl+Y arrive here as input types, so the browser's own per-page undo never runs. */
    onBeforeInput(event) {
        if (event.inputType === 'historyUndo') { event.preventDefault(); this.undo() }
        if (event.inputType === 'historyRedo') { event.preventDefault(); this.redo() }
    },

    /* ----- Keyboard and trackpad ----- */

    lineHeight() {
        return parseFloat(getComputedStyle(this.$refs.editor).lineHeight) || 24
    },

    caretRect() {
        const selection = window.getSelection()
        if (! selection.rangeCount || ! selection.isCollapsed) return null

        const range = selection.getRangeAt(0).cloneRange()
        range.collapse(true)

        let rect = range.getClientRects()[0]

        if (! rect) {
            const node = range.startContainer
            rect = (node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement)?.getBoundingClientRect()
        }

        return rect ?? null
    },

    atFirstLine() {
        const rect = this.caretRect()
        const first = this.$refs.editor.firstElementChild

        return !! rect && (! first || rect.top < first.getBoundingClientRect().top + this.lineHeight())
    },

    atLastLine() {
        const rect = this.caretRect()
        const last = this.$refs.editor.lastElementChild

        return !! rect && (! last || rect.bottom > last.getBoundingClientRect().bottom - this.lineHeight())
    },

    /* Shortcuts as in Word. Ctrl+B, I and U are the browser's own. They are only taken while the cursor is in the sheet. */
    shortcut(event) {
        const mod = event.ctrlKey || event.metaKey
        if (! mod) return false

        const run = (command, argument = null) => { event.preventDefault(); this.exec(command, argument); return true }

        if (event.altKey && ! event.shiftKey) {
            const level = { Digit1: 'h1', Digit2: 'h2', Digit3: 'h3', Digit0: 'p' }[event.code]

            return level ? run('formatBlock', level) : false
        }

        if (event.shiftKey && ! event.altKey) {
            if (event.code === 'Digit7') return run('insertOrderedList')
            if (event.code === 'Digit8') return run('insertUnorderedList')

            return false
        }

        if (event.altKey) return false

        const align = { l: 'justifyLeft', e: 'justifyCenter', r: 'justifyRight', j: 'justifyFull' }[event.key.toLowerCase()]

        if (align) return run(align)

        if (event.key === ']' || event.key === '[') {
            event.preventDefault()
            this.stepSize(event.key === ']' ? 1 : -1)

            return true
        }

        return false
    },

    onKey(event) {
        const plain = ! event.ctrlKey && ! event.metaKey && ! event.shiftKey

        if (this.shortcut(event)) return

        if (event.altKey && plain && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
            event.preventDefault()
            if (event.key === 'ArrowRight') { this.next() } else { this.prev() }
            return
        }

        if (plain && ! event.altKey && event.key === 'PageDown' && this.atLastLine() && this.current < this.pages.length - 1) {
            event.preventDefault()
            this.next()
            return
        }

        if (plain && ! event.altKey && event.key === 'PageUp' && this.atFirstLine() && this.current > 0) {
            event.preventDefault()
            this.prev()
            return
        }

        this.deleteSelected(event)
    },
    /* A two-finger sideways swipe moves one page. A swipe is many events, so only the first after a pause counts,
       and a short cooldown follows. Mouse drag is not used: it belongs to selecting text. */
    onWheel(event) {
        if (Math.abs(event.deltaX) <= Math.abs(event.deltaY) || Math.abs(event.deltaX) < 8) return

        event.preventDefault()

        const now = Date.now()
        const pause = now - this.wheelAt

        this.wheelAt = now

        if (pause > 250 && Math.abs(event.deltaX) > 25 && now - this.wheelSwitchedAt > 350) {
            this.wheelSwitchedAt = now
            if (event.deltaX > 0) { this.next() } else { this.prev() }
        }
    },

    /* A picture finishing loading can make the page taller than it measured. */
    onImageLoad() {
        this.reflowLive()
    },

    /* ----- The open-book look: the neighbouring pages peek out either side ----- */

    /* Only the two neighbours are ever copied into the page, and only after a short pause. The edge layers are plain CSS. */
    queuePeeks(now = false) {
        clearTimeout(this.peekTimer)

        const run = () => {
            const plan = peekPlan(this.current, this.pages.length)

            this.plan = plan
            this.peekPrev = plan.previous === null ? '' : this.pages[plan.previous]
            this.peekNext = plan.next === null ? '' : this.pages[plan.next]
        }

        if (now) { run() } else { this.peekTimer = setTimeout(run, 200) }
    },

    /* The faint sheet after the last page: a click adds a page at the end. */
    appendPage() {
        this.touched = true
        this.syncCurrent()
        this.pages.push('')
        this.show(this.pages.length - 1, { dir: 1, caret: 'start' })
        this.commitContent()
        this.record()
        this.say('New page added.')
    },
    /* ----- Pictures ----- */

    async insertImage(event) {
        const file = event.target.files[0]
        event.target.value = ''
        if (! file || ! config.uploadUrl) return

        if (! ['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
            return this.say('Only PNG, JPG or WebP pictures can be inserted.')
        }
        if (file.size > 2 * 1024 * 1024) {
            return this.say('That picture is too large. Pictures can be up to 2 MB.')
        }

        const form = new FormData()
        form.append('image', file)
        this.uploading = true

        try {
            const response = await fetch(config.uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                body: form,
            })
            const data = await response.json().catch(() => ({}))

            if (! response.ok) {
                return this.say(data.message || 'The picture could not be uploaded. Try again.')
            }

            this.exec('insertHTML', '<figure data-size="medium"><img src="' + data.url + '" data-image="' + data.id + '" alt=""></figure><p><br></p>')
            this.say('Picture inserted. Click it to change its width or add a caption.')
        } catch (e) {
            this.say('The picture could not be uploaded. Check your connection and try again.')
        } finally {
            this.uploading = false
        }
    },

    /* Clicking a picture picks it, so its width and remove buttons apply. */
    pick(event) {
        const figure = event.target.closest ? event.target.closest('figure') : null
        const onPicture = figure && event.target.tagName === 'IMG'

        this.$refs.editor.querySelectorAll('figure.is-picked').forEach((f) => f.classList.remove('is-picked'))

        if (onPicture) {
            figure.classList.add('is-picked')
            this.picked = figure
            this.pickedSize = figure.dataset.size || 'medium'
            this.pickedCaption = !! figure.querySelector('figcaption')
        } else {
            this.picked = null
            this.pickedSize = ''
            this.pickedCaption = false
        }
    },

    sizePicture(size) {
        if (! this.picked) return
        this.picked.dataset.size = size
        this.pickedSize = size
        this.afterChange()
    },

    /* A picture has no caption until one is asked for; taking it away removes the caption with its text. */
    toggleCaption() {
        if (! this.picked) return

        const existing = this.picked.querySelector('figcaption')

        if (existing) {
            existing.remove()
            this.pickedCaption = false
            this.afterChange()

            return
        }

        const caption = document.createElement('figcaption')

        this.picked.appendChild(caption)
        this.pickedCaption = true
        this.$refs.editor.focus({ preventScroll: true })
        this.placeCaret(caption, 0)
        this.afterChange()
    },

    removePicture() {
        if (! this.picked) return
        this.picked.remove()
        this.picked = null
        this.pickedSize = ''
        this.afterChange()
    },

    /* ----- Link ----- */

    insertLink() {
        const selection = window.getSelection()
        const saved = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null
        const typed = window.prompt('Link address (it must start with http:// or https://)', 'https://')
        if (typed === null) return

        const address = typed.trim()
        if (! /^https?:\/\/[^\s"<>]+$/i.test(address)) {
            return this.say('Links must start with http:// or https://.')
        }

        this.$refs.editor.focus()
        if (saved) { selection.removeAllRanges(); selection.addRange(saved) }

        if (selection.isCollapsed) {
            const label = address.replace(/&/g, '&amp;')
            this.exec('insertHTML', '<a href="' + label + '">' + label + '</a>')
        } else {
            this.exec('createLink', address)
        }
    },

    /* ----- Table of contents: built from the headings of every page ----- */

    headings() {
        const box = document.createElement('div')
        const found = []

        this.syncCurrent()

        this.pages.forEach((html) => {
            box.innerHTML = html

            box.querySelectorAll('h1, h2, h3').forEach((h) => {
                const text = h.textContent.trim().replace(/\s+/g, ' ')

                if (text !== '' && ! h.closest('blockquote[data-toc]')) found.push({ level: Number(h.tagName.slice(1)), text })
            })
        })

        return found
    },

    tocHtml() {
        const headings = this.headings()
        const esc = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;')

        let html = '<p><strong>Table of contents</strong></p>'

        if (headings.length === 0) {
            return html + '<ul><li>No headings yet. Mark a line as H1, H2 or H3, then update the contents.</li></ul>'
        }

        /* Nested lists that follow the heading levels, in document order. */
        const open = []
        headings.forEach(({ level, text }) => {
            if (open.length === 0 || level > open[open.length - 1]) {
                html += '<ul><li>' + esc(text)
                open.push(level)
                return
            }

            while (open.length > 1 && level < open[open.length - 1]) { html += '</li></ul>'; open.pop() }
            html += '</li><li>' + esc(text)
        })
        while (open.length) { html += '</li></ul>'; open.pop() }

        return html
    },

    insertToc() {
        if (this.hasToc) return this.refreshToc()

        this.exec('insertHTML', '<blockquote data-toc="1">' + this.tocHtml() + '</blockquote><p><br></p>')
        this.say('Contents added. Choose "Update contents" after you change a heading.')
    },

    /* The contents block may be on any page. */
    changeTocOn(change) {
        this.syncCurrent()

        const index = this.pages.findIndex((html) => html.includes('data-toc'))
        if (index === -1) return false

        const box = document.createElement('div')
        box.innerHTML = this.pages[index]

        const block = box.querySelector('blockquote[data-toc]')
        if (! block) return false

        change(block)
        this.pages[index] = this.serialise(box)

        if (index === this.current) this.load(index)

        this.touched = true
        this.refit(index)
        this.commitContent()
        this.updateCounts()
        this.record()

        return true
    },

    refreshToc() {
        const html = this.tocHtml()

        if (this.changeTocOn((block) => { block.innerHTML = html })) this.say('Contents updated.')
    },

    removeToc() {
        if (this.changeTocOn((block) => block.remove())) this.say('Contents removed.')
    },

    /* Delete or Backspace over a selected picture or table of contents removes the whole thing, so it can be
       cleared by selecting it like any other text. Anything else about the key press is left alone. */
    deleteSelected(event) {
        if (event.key !== 'Delete' && event.key !== 'Backspace') return

        const editor = this.$refs.editor

        if (this.picked && editor.contains(this.picked)) {
            event.preventDefault()
            this.removePicture()
            return
        }

        const selection = window.getSelection()
        if (! selection.rangeCount || selection.isCollapsed) return

        const range = selection.getRangeAt(0)
        let removed = false

        editor.querySelectorAll(WHOLE_BLOCKS).forEach((block) => {
            const target = block.tagName === 'FIGURE' ? block.querySelector('img') : block
            if (target && range.intersectsNode(target)) { block.remove(); removed = true }
        })

        if (removed) {
            /* Nothing else was selected: the removal was the whole delete. */
            if (selection.rangeCount === 0 || selection.isCollapsed || ! editor.contains(range.commonAncestorContainer)) {
                event.preventDefault()
            }
            this.afterChange()
        }
    },

    /* Paste as plain text: keeps someone's Word styling out of the
       group's document, and removes the most common route for pasted
       markup. Unchanged by the new formatting — the toolbar is the only
       way to style anything here. */
    pastePlain(event) {
        event.preventDefault()
        const text = (event.clipboardData || window.clipboardData).getData('text/plain')
        document.execCommand('insertText', false, text)
        this.afterChange()
    },
})
