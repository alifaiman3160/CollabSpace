/*
 * What the Documents list's search and sort do, as pure functions (no DOM), so they can be tested with Node.
 */

/** Case-insensitive title match; an empty search matches everything. */
export const matches = (title, query) => {
    const needle = String(query ?? '').trim().toLowerCase()

    return needle === '' || String(title).toLowerCase().includes(needle)
}

/** The cards in display order: newest edit first, or title A to Z. Equal titles keep their order. */
export const arrange = (items, sort) => [...items].sort((a, b) => sort === 'title'
    ? String(a.title).localeCompare(String(b.title), undefined, { sensitivity: 'base' })
    : Number(b.edited) - Number(a.edited))

/** What to show for a search: each item's visibility, how many show, and whether the New document tile is there. */
export const filtered = (items, query) => {
    const visible = items.map((item) => matches(item.title, query))

    return {
        visible,
        shown: visible.filter(Boolean).length,
        // The tile is for starting a document, so it steps aside while someone is looking for one.
        tile: String(query ?? '').trim() === '',
    }
}