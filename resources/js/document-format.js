/*
 * Reading and applying formatting, as pure functions (no DOM) so they can be tested with Node.
 *
 * The editor reads the blocks and text a selection touches, hands plain values to these functions, and writes the
 * answer back. Everything it may write is something the sanitiser keeps (App\Support\StyleAttributeSanitizer).
 */

export const SPACINGS = { '1': 'Single', '1.15': 'Comfortable', '1.5': 'One and a half', '2': 'Double' }

export const LINE_HEIGHT = { min: 1, max: 3 }
export const SPACE_PRESETS_PT = [0, 6, 12, 18]
export const INDENT_STEP_CM = 1
export const INDENT_MAX_CM = 10
export const FIRST_LINE_CM = 1.25

/** The tight look of a list or a block of requirements: single spacing and no gap between paragraphs. */
export const COMPACT = { 'line-height': '1', 'margin-top': '0pt', 'margin-bottom': '0pt' }

/** True when a block's style is exactly the compact look (so the Spacing control can say "Compact"). */
export const isCompact = (text) => {
    const style = parseStyle(text)

    return Object.entries(COMPACT).every(([key, value]) => style[key] === value || (key === 'line-height' && ['1', '1.0'].includes(style[key])))
}

/** 'a: b; c: d' as { a: 'b', c: 'd' }. */
export const parseStyle = (text) => {
    const out = {}

    for (const part of String(text ?? '').split(';')) {
        const at = part.indexOf(':')

        if (at > 0) out[part.slice(0, at).trim().toLowerCase()] = part.slice(at + 1).trim()
    }

    return out
}

export const styleText = (map) => Object.entries(map).map(([key, value]) => `${key}: ${value}`).join('; ')

/** The style text with `changes` applied; a null value removes the property. Other properties are kept. */
export const withStyle = (text, changes) => {
    const map = parseStyle(text)

    for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === undefined || value === '') { delete map[key] } else { map[key] = String(value) }
    }

    return styleText(map)
}

/**
 * What a set of values says as one: the value when they all agree, 'mixed' when they do not, and '' when there are
 * none to read. Used so a control never shows a wrong value for a mixed selection.
 */
export const commonValue = (values) => {
    if (values.length === 0) return ''

    return values.every((value) => value === values[0]) ? values[0] : 'mixed'
}

/** A line-height number as a typed value between 1 and 3 (two decimals), or null if it cannot be one. */
export const clampLineHeight = (input) => {
    const number = Number.parseFloat(String(input).replace(',', '.'))

    if (!Number.isFinite(number) || number < LINE_HEIGHT.min || number > LINE_HEIGHT.max) return null

    return Math.round(number * 100) / 100
}

/** What the Spacing control should show for a line-height ratio: a preset key, or 'custom' with the number. */
export const spacingChoice = (ratio) => {
    if (!Number.isFinite(ratio)) return { value: '', custom: null }

    const rounded = Math.round(ratio * 100) / 100
    const key = String(rounded)

    return key in SPACINGS ? { value: key, custom: null } : { value: 'custom', custom: rounded }
}

/** The spacing of several blocks (their ratios) as one choice, or 'mixed'. */
export const spacingOf = (ratios) => {
    const rounded = ratios.map((ratio) => Math.round(ratio * 100) / 100)
    const common = commonValue(rounded)

    if (common === 'mixed') return { value: 'mixed', custom: null }
    if (common === '') return { value: '', custom: null }

    return spacingChoice(common)
}

/** A length like '2.5cm' or '12pt' as a number, or 0. */
export const lengthOf = (text, unit) => {
    const match = String(text ?? '').trim().match(new RegExp(`^(\\d+(?:\\.\\d+)?)${unit}$`, 'i'))

    return match ? Number.parseFloat(match[1]) : 0
}

/** The indent after pressing increase (+1) or decrease (-1): a centimetre at a time, from 0 up to the maximum. */
export const stepIndent = (currentCm, direction) => {
    const next = Math.round((currentCm + direction * INDENT_STEP_CM) * 100) / 100

    return Math.min(INDENT_MAX_CM, Math.max(0, next))
}

/**
 * Applies `changes` to the blocks at `selected` (indexes) and leaves every other block exactly as it was. A block is
 * { style: 'text' } here; the editor maps its real elements to these and back.
 */
export const applyToBlocks = (blocks, selected, changes) =>
    blocks.map((block, index) => (selected.includes(index) ? { ...block, style: withStyle(block.style, changes) } : block))

/** Turns the first-line indent on for every block, or off if they all have it already (like the Word button). */
export const firstLineChanges = (blocks) => {
    const all = blocks.length > 0 && blocks.every((block) => lengthOf(parseStyle(block.style)['text-indent'], 'cm') > 0)

    return { 'text-indent': all ? null : `${FIRST_LINE_CM}cm` }
}

/** The indent of blocks, for the toolbar: centimetres, or 'mixed'. */
export const indentOf = (blocks) => commonValue(blocks.map((block) => lengthOf(parseStyle(block.style)['margin-left'], 'cm')))

/** Space before or after: the points set on every block, '' when none is set, 'mixed' otherwise. */
export const spaceOf = (blocks, property) =>
    commonValue(blocks.map((block) => {
        const value = parseStyle(block.style)[property]

        return value === undefined ? '' : String(lengthOf(value, 'pt'))
    }))

/** The font-size step (execCommand's 2 to 6) for a computed pixel size, or '' if it is not one of ours. */
export const sizeStep = (px) => ({ 13: '2', 16: '3', 18: '4', 24: '5', 32: '6' })[Math.round(px)] ?? ''

/** 'rgb(17, 17, 20)' as '#111114'; anything else as ''. */
export const hexOf = (colour) => {
    const match = String(colour ?? '').match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/)

    if (!match || (match[4] !== undefined && Number.parseFloat(match[4]) === 0)) return ''

    return `#${[match[1], match[2], match[3]].map((n) => Number(n).toString(16).padStart(2, '0')).join('')}`
}
