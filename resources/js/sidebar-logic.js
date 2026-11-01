/*
 * The sidebar's pure parts (no DOM of its own), so they can be tested with Node.
 */

/** Narrower than this, and nobody has chosen: the sidebar starts as the slim rail. */
export const NARROW = 1200

const choice = (value) => (value === 'collapsed' || value === 'expanded' ? value : null)

/** Collapsed or not. An explicit choice (cookie first, then the browser's own storage) always wins over the width. */
export const decideCollapsed = ({ cookie = null, stored = null, width = Infinity } = {}) => {
    const explicit = choice(cookie) ?? choice(stored)

    return explicit !== null ? explicit === 'collapsed' : width < NARROW
}

/** "marking,reports" as ['marking', 'reports']: only plain keys, each once. */
export const parseOpen = (value) => {
    const keys = String(value ?? '').split(',').map((key) => key.trim()).filter((key) => /^[a-z]{1,20}$/.test(key))

    return [...new Set(keys)]
}

export const serializeOpen = (keys) => [...new Set(keys)].join(',')

/** The open list with one section opened or closed. */
export const withSection = (keys, key, open) => {
    const rest = keys.filter((existing) => existing !== key)

    return open ? [...rest, key] : rest
}

const clean = (path) => `/${String(path ?? '').replace(/^\/+|\/+$/g, '')}`

/** True when the address is the match itself, or (unless exact) a page below it. */
export const matchesPath = (path, match, exact = false) => {
    if (match === null || match === undefined || match === '') return false

    const here = clean(path)
    const there = clean(match)

    return here === there || (!exact && here.startsWith(`${there}/`))
}

/**
 * The sub-item whose page this is: the longest match wins, so the right group stays highlighted on a page below it.
 * children are { match, exact }; returns its index or null. The same rule as SidebarState::activeChild in PHP.
 */
export const activeChild = (path, children) => {
    let best = null
    let length = -1

    children.forEach((child, index) => {
        if (matchesPath(path, child.match, child.exact) && clean(child.match).length > length) {
            best = index
            length = clean(child.match).length
        }
    })

    return best
}

/** The index to focus after a key in a list of `count` items; null for a key that does not move. */
export const nextIndex = (index, count, key) => {
    if (count <= 0) return null

    switch (key) {
        case 'ArrowDown': return index < 0 ? 0 : (index + 1) % count
        case 'ArrowUp': return index < 0 ? count - 1 : (index - 1 + count) % count
        case 'Home': return 0
        case 'End': return count - 1
        default: return null
    }
}

/**
 * Where the floating card of the slim rail goes. It sits to the right of the rail (left = the rail's right edge + gap),
 * its top edge level with the top of the icon that opened it; when that would run past the bottom of the window it is
 * shifted up so it ends `margin` inside it, and when past the top it is shifted down. It never leaves the window.
 * Rects are { left, top, width, height }; the card is { width, height } and the viewport { width, height }.
 * `arrow` is how far down the card the icon's middle is, kept inside the card's rounded corners.
 */
export const placeFlyout = (rail, trigger, card, viewport, gap = 8, margin = 12) => {
    const left = Math.round(rail.left + rail.width + gap)
    const lowest = Math.max(margin, viewport.height - card.height - margin)
    const top = Math.round(Math.min(Math.max(trigger.top, margin), lowest))
    const arrow = Math.round(Math.min(Math.max(trigger.top + trigger.height / 2 - top, 16), Math.max(16, card.height - 16)))

    return { left, top, arrow }
}
