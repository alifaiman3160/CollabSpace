/*
 * The page model of a document, as pure functions on strings (no DOM), so they can be tested with Node.
 *
 * The stored body is ONE html string. Pages are the segments between page-break markers, the same marker
 * the print layout turns into a real page break. Nothing here changes the stored format.
 */

export const PAGE_BREAK = '<p data-page-break="1"><br></p>'

const MARKER = /<p\b[^>]*\bdata-page-break\b[^>]*>(?:\s|<br\s*\/?>)*<\/p>/gi

/** 'a<marker>b' becomes ['a', 'b']. No marker is one page; consecutive markers leave empty pages. */
export const splitPages = (html) => String(html ?? '').split(MARKER)

export const joinPages = (pages) => pages.join(PAGE_BREAK)

/** Blocks that are never cut through when a new page is started inside them. */
const WHOLE = ['table', 'ul', 'ol', 'figure', 'blockquote', 'img', 'hr']
const VOID = ['br', 'img', 'hr', 'input', 'wbr']

const TOKEN = /<[^>]*>|&#?[a-zA-Z0-9]+;|[\s\S]/gu

const isTag = (token) => token[0] === '<' && token.length > 1

/** What a token adds to the text: nothing for a tag, one for an entity, its own length otherwise. */
const weight = (token) => (isTag(token) ? 0 : token.length > 1 && token[0] === '&' && token.endsWith(';') ? 1 : token.length)

/** How many characters of text an html string shows. */
export const textLength = (html) => (html.match(TOKEN) ?? []).reduce((n, token) => n + weight(token), 0)

const dropEmptyInline = (html) => {
    let previous

    do {
        previous = html
        html = html.replace(/<(b|i|u|s|em|strong|span|a|font)\b[^>]*><\/\1>/gi, '')
    } while (html !== previous)

    return html
}

/**
 * Cuts inner html after `offset` characters of text, closing the tags open at the cut in the first half and
 * reopening them in the second, so both halves are well formed ('<b>ab|cd</b>' gives '<b>ab</b>' and '<b>cd</b>').
 */
export const splitHtmlAtTextOffset = (html, offset) => {
    if (offset <= 0) return ['', html]

    const open = []
    let seen = 0
    let cut = -1
    let position = 0

    for (const token of html.match(TOKEN) ?? []) {
        position += token.length

        if (isTag(token)) {
            const name = (token.match(/^<\/?([a-zA-Z0-9]+)/) ?? [])[1]?.toLowerCase()

            if (!name || VOID.includes(name) || token.endsWith('/>')) continue

            if (token[1] === '/') {
                open.pop()
            } else {
                open.push([name, token])
            }

            continue
        }

        seen += weight(token)

        if (seen >= offset) {
            cut = position
            break
        }
    }

    if (cut === -1 || cut >= html.length) return [html, '']

    const closers = [...open].reverse().map(([name]) => `</${name}>`).join('')
    const openers = open.map(([, tag]) => tag).join('')

    return [dropEmptyInline(html.slice(0, cut) + closers), dropEmptyInline(openers + html.slice(cut))]
}

const parseBlock = (block) => {
    const match = block.match(/^<([a-zA-Z0-9]+)([^>]*)>([\s\S]*)<\/\1>$/)

    return match ? { tag: match[1].toLowerCase(), attributes: match[2], inner: match[3] } : null
}

/**
 * "New page" at the cursor. `blocks` are the page's top-level blocks (outer html), the cursor is `offset`
 * characters into block number `blockIndex`. Returns [what stays, what moves to the new page]: text after the
 * cursor moves, like a page break in Word. A cursor inside a table, list, picture or quote starts the new page
 * after that block rather than cutting through it.
 */
export const splitPageAtCaret = (blocks, blockIndex, offset) => {
    if (blockIndex < 0 || blockIndex >= blocks.length) return [blocks.join(''), '']

    const before = blocks.slice(0, blockIndex)
    const after = blocks.slice(blockIndex + 1)
    const block = blocks[blockIndex]
    const parsed = parseBlock(block)

    if (!parsed || WHOLE.includes(parsed.tag) || /data-(toc|page-break)/.test(parsed.attributes)) {
        return [[...before, block].join(''), after.join('')]
    }

    if (offset <= 0) return [before.join(''), [block, ...after].join('')]
    if (offset >= textLength(parsed.inner)) return [[...before, block].join(''), after.join('')]

    const [head, tail] = splitHtmlAtTextOffset(parsed.inner, offset)
    const wrap = (inner) => `<${parsed.tag}${parsed.attributes}>${inner}</${parsed.tag}>`

    return [[...before, wrap(head)].join(''), [wrap(tail), ...after].join('')]
}

/**
 * Sends the overflow of a page forward. `carry` is html to put at the start of page `from`; `fit(html)` says how
 * much of it that page keeps ({ kept, moved }), and what it moves is carried on to the next page, and so on until
 * nothing is left over. Pages are created at the end when needed. The input array is not changed.
 */
export const cascadeOverflow = (pages, from, carry, fit) => {
    const out = [...pages]
    let index = from
    let rest = carry
    let guard = 0

    while (rest !== '' && guard++ < 10000) {
        const { kept, moved } = fit(rest + (out[index] ?? ''))

        out[index] = kept
        rest = moved
        index++
    }

    return out
}

/** A page with nothing in it: no text, no picture, no table. */
export const isPageEmpty = (html) => {
    const withoutBreaks = html.replace(/<br\s*\/?>/gi, '')

    if (/<(img|table|hr)\b/i.test(withoutBreaks)) return false

    return withoutBreaks.replace(/<[^>]*>/g, '').replace(/&nbsp;|\s/g, '') === ''
}

/** The pages without page `index`, or null when it is the last page left (a document always has one). */
export const removePage = (pages, index) => {
    if (pages.length <= 1 || index < 0 || index >= pages.length) return null

    return pages.filter((_, i) => i !== index)
}

/** Text shown by a page, for the word and character counts. */
export const textOf = (html) => {
    return html
        .replace(/<\/(p|h[1-6]|li|div|figcaption|td|th|tr|blockquote)>|<br\s*\/?>/gi, ' ')
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;/g, ' ')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&amp;/g, '&')
}

/**
 * What the book look shows around page `current` of `total` (indexes from 0): at most two neighbours as
 * miniatures, and at most three plain edge layers behind each, so a long document costs no more than a short one.
 * Nothing on the left of the first page and nothing on the right of the last, which instead offers a new page.
 */
export const peekPlan = (current, total) => ({
    previous: current > 0 ? current - 1 : null,
    next: current < total - 1 ? current + 1 : null,
    edgesLeft: Math.max(0, Math.min(3, current - 1)),
    edgesRight: Math.max(0, Math.min(3, total - current - 2)),
    newSheet: current === total - 1,
})
