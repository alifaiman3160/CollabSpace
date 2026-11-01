/*
 * The tooltip's pure parts (no DOM of its own), so they can be tested with Node.
 */

const GAP = 8       // between the trigger and the tooltip
const MARGIN = 8    // the least space kept to the edge of the window
const ARROW = 14    // the least distance of the arrow from either end of the tooltip

/**
 * Where a tooltip goes: above its trigger, or below when there is no room above, centred on the trigger but kept
 * inside the window, and where its arrow must point. Rects are { left, top, width, height } in window coordinates.
 */
export const place = (trigger, tip, viewport, gap = GAP, margin = MARGIN, prefer = 'top') => {
    if (prefer === 'right') return placeBeside(trigger, tip, viewport, gap, margin)

    const above = trigger.top - tip.height - gap
    const below = trigger.top + trigger.height + gap
    const fitsAbove = above >= margin
    const fitsBelow = below + tip.height <= viewport.height - margin

    // Above by default, below when there is no room above; if neither fits, wherever there is more room.
    const roomBelow = viewport.height - (trigger.top + trigger.height)
    const side = fitsAbove ? 'top' : fitsBelow ? 'bottom' : (trigger.top >= roomBelow ? 'top' : 'bottom')

    const top = Math.min(Math.max(margin, side === 'top' ? above : below), Math.max(margin, viewport.height - tip.height - margin))
    const centre = trigger.left + trigger.width / 2
    const left = Math.min(Math.max(margin, centre - tip.width / 2), Math.max(margin, viewport.width - tip.width - margin))
    const arrow = Math.min(Math.max(ARROW, centre - left), Math.max(ARROW, tip.width - ARROW))

    return { side, left: Math.round(left), top: Math.round(top), arrow: Math.round(arrow) }
}

/**
 * Beside the trigger, to its right (the slim sidebar's items), or to its left when there is no room; level with the
 * trigger's middle but kept inside the window. arrow is then the distance down from the tooltip's top.
 */
const placeBeside = (trigger, tip, viewport, gap, margin) => {
    const right = trigger.left + trigger.width + gap
    const fitsRight = right + tip.width <= viewport.width - margin
    const side = fitsRight ? 'right' : 'left'
    const left = side === 'right' ? right : trigger.left - gap - tip.width
    const centre = trigger.top + trigger.height / 2
    const top = Math.min(Math.max(margin, centre - tip.height / 2), Math.max(margin, viewport.height - tip.height - margin))
    const arrow = Math.min(Math.max(ARROW - 4, centre - top), Math.max(ARROW - 4, tip.height - (ARROW - 4)))

    return { side, left: Math.round(Math.max(margin, left)), top: Math.round(top), arrow: Math.round(arrow) }
}

const MODIFIER = /^(ctrl|control|alt|shift|cmd|command|meta|option)$/i

/**
 * "Bold (Ctrl + B)" as { text: 'Bold', keys: ['Ctrl', 'B'] }. Only a bracket that really holds a key combination (at
 * least one modifier) is taken as keys, so "Delete (not undoable)" stays plain text.
 */
export const splitShortcut = (value) => {
    const text = String(value ?? '').trim()
    const match = text.match(/^(.*?)\s*\(([^()]+)\)$/)

    if (!match) return { text, keys: [] }

    const keys = match[2].split('+').map((key) => key.trim()).filter(Boolean)

    if (keys.length < 2 || !keys.some((key) => MODIFIER.test(key))) return { text, keys: [] }

    return { text: match[1].trim(), keys }
}

/** Elements whose title is not ours to turn into a tooltip: user content, frames, and anything that opts out. */
export const isExcluded = (el) => {
    if (!el) return true

    const tag = String(el.tagName ?? '').toUpperCase()

    if (['TITLE', 'IFRAME', 'LINK', 'META', 'STYLE', 'SCRIPT'].includes(tag)) return true

    return typeof el.closest === 'function'
        && el.closest('[data-no-tooltip], .doc-body, .doc-page, [contenteditable="true"], iframe') !== null
}

/** True when an element already has a name a screen reader can use, so no aria-label is added on top of it. */
export const hasAccessibleName = (el) => {
    if (el.hasAttribute('aria-label') || el.hasAttribute('aria-labelledby')) return true
    if (String(el.textContent ?? '').trim() !== '') return true

    const image = typeof el.querySelector === 'function' ? el.querySelector('img[alt]:not([alt=""])') : null

    return image !== null
}

/**
 * What to do with an element that has a title: null when it is left alone, otherwise the text for its tooltip and
 * the aria-label it needs (null when it already has a name, or the title is the only thing a button has).
 */
export const upgradePlan = (el) => {
    if (!el || typeof el.getAttribute !== 'function' || !el.hasAttribute('title')) return null

    const text = String(el.getAttribute('title') ?? '').trim()

    if (isExcluded(el)) return null

    return { text, aria: text !== '' && !hasAccessibleName(el) ? splitShortcut(text).text : null }
}
