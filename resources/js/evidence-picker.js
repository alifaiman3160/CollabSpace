/*
 * The evidence-type control: live advice as the selection or the criterion's
 * wording changes.
 *
 * The rules are PHP's (App\Support\Marking\EvidenceType) handed over as `rules`,
 * so there is one definition. This only evaluates them, mirroring
 * EvidenceType::hint() and ::guess(). The advice is never a block: it is text
 * beside the control, and every choice stays available.
 */

const mentions = (text, words) => words.some((word) => text.includes(word))

const normalise = (type, rules) => (type && type in rules.labels ? type : 'none')

export const guess = (name, rules) => {
    const text = String(name ?? '').toLowerCase()

    if (mentions(text, rules.attitude)) return null

    for (const [type, words] of Object.entries(rules.keywords)) {
        if (mentions(text, words)) return type
    }

    return null
}

/** A mismatch hint for a criterion, or '' when there is none. */
export const hint = (name, mode, evidence, rules) => {
    if (mode !== 'suggested') return ''

    const type = normalise(evidence, rules)
    const text = String(name ?? '').toLowerCase()

    if (mentions(text, rules.attitude)) return type === 'none' ? '' : rules.messages.attitude

    if (mentions(text, rules.reliability)) return type === 'reliability' ? '' : rules.messages.reliability

    const guessed = guess(name, rules)

    if (guessed !== null && type !== 'none' && type !== guessed) {
        return rules.messages.mismatch.replace(':label', rules.labels[guessed])
    }

    return ''
}

export default (config) => ({
    ev: config.ev ?? '',
    rules: config.rules,

    // On the saved rubric there is no surrounding form state, so the control
    // carries its own. On the review screen these come from the criterion card,
    // so what is typed in its name and mode fields drives the advice.
    ...(config.standalone ? { name: config.name, cmode: config.cmode } : {}),

    hintFor(name, mode, ev) {
        return hint(name, mode, ev, this.rules)
    },

    /** The one line shown under the select: what the chosen option measures. */
    describe(ev) {
        return this.rules.descriptions[ev ?? ''] ?? this.rules.descriptions['']
    },

    isDefault(name, ev) {
        return ev !== '' && ev === guess(name, this.rules)
    },
})
