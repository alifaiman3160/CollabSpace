/*
 * The Students search box's pure parts (no DOM), so they can be tested with Node.
 */

/** The text split into { text, bold } pieces, the part that matches the typed text bold (case-insensitive, first match). */
export const highlight = (text, query) => {
    const value = String(text ?? '')
    const needle = String(query ?? '').trim().toLowerCase()

    if (needle === '') return [{ text: value, bold: false }]

    const at = value.toLowerCase().indexOf(needle)

    if (at < 0) return [{ text: value, bold: false }]

    return [
        { text: value.slice(0, at), bold: false },
        { text: value.slice(at, at + needle.length), bold: true },
        { text: value.slice(at + needle.length), bold: false },
    ].filter((piece) => piece.text !== '')
}

/**
 * What choosing a suggestion does to the table's filters: a student becomes the search text (their matric number when
 * they have one, else their name), a group or a subject becomes that filter and clears the text.
 */
export const choice = (item) => {
    switch (item.type) {
        case 'group': return { search: '', group: String(item.group_id), subject: '' }
        case 'subject': return { search: '', group: '', subject: String(item.subject_id) }
        default: return { search: item.matric && !String(item.matric).includes('@') ? item.matric : item.name, group: '', subject: '' }
    }
}

/** The highlighted row after an arrow key, wrapping; -1 means none (the plain search). */
export const move = (active, count, key) => {
    if (count <= 0) return -1
    if (key === 'ArrowDown') return active + 1 >= count ? 0 : active + 1
    if (key === 'ArrowUp') return active <= 0 ? count - 1 : active - 1

    return active
}