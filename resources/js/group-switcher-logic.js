/*
 * The group switcher's pure parts (no DOM), so they can be tested with Node.
 */

/** True when the text holds every word typed (case and accents aside); nothing typed matches everything. */
export const matches = (text, query) => {
    const fold = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    const words = fold(query).split(/\s+/).filter(Boolean)
    const haystack = fold(text)

    return words.every((word) => haystack.includes(word))
}

/**
 * Where the card goes: under the button, its left edge level with the button's, then pulled back inside the window
 * (`margin` from every edge). When there is no room below it opens above instead. Rects are { left, top, width, height }.
 */
export const placeBelow = (trigger, card, viewport, gap = 8, margin = 12) => {
    const left = Math.min(Math.max(trigger.left, margin), Math.max(margin, viewport.width - card.width - margin))
    const below = trigger.top + trigger.height + gap
    const fits = below + card.height <= viewport.height - margin
    const above = trigger.top - gap - card.height
    const top = fits || above < margin ? Math.min(below, Math.max(margin, viewport.height - card.height - margin)) : above

    return { left: Math.round(left), top: Math.round(top) }
}
