/*
 * One section of the rubric review form.
 *
 * Keeps the section's maximum marks up to date as the lecturer edits weights,
 * marks and rows, so the declared total, the maximum under each formula and the
 * warning are always about what is on screen. The same sums are done in PHP
 * (App\Support\Rubric\RubricScoring), which is what the server renders first and
 * what a save is checked against; this only keeps the screen honest while typing.
 *
 * Registered on Alpine's init event rather than imported, because Livewire
 * bundles and starts the only Alpine on the page (see app.js).
 */

const TOLERANCE = 0.005

const number = (value) => {
    if (value === null || value === undefined) return null

    const text = String(value).trim().replace(',', '.')

    return text !== '' && !Number.isNaN(Number(text)) ? Number(text) : null
}

const round = (n) => Math.round(n * 100) / 100

export const format = (n) => (n === null ? '—' : String(round(n)))

export default (initial) => ({
    mode: initial.mode,
    scoring: initial.scoring ?? '',
    declared: initial.declared ?? '',
    weighted: initial.weighted,
    only: initial.only,

    /** Recompute both maxima from the inputs now in this section. */
    recalc() {
        let weighted = 0
        let only = 0
        let weightsKnown = true
        let any = false

        this.$root.querySelectorAll('[data-criterion]').forEach((criterion) => {
            any = true

            const marks = [...criterion.querySelectorAll('[data-level] input[name$="[marks]"]')]
                .map((input) => number(input.value))
                .filter((n) => n !== null)

            const top = marks.length ? Math.max(...marks) : 0
            only += top

            const weight = number(criterion.querySelector('input[name$="[weight]"]')?.value)

            if (weight === null) {
                weightsKnown = false
            } else {
                weighted += top * weight
            }

            // In a "level only" section the weight is the criterion's maximum, so a
            // top level worth something else is probably a slip. Mirrors RubricChecks.
            const flag = criterion.querySelector('[data-weight-flag]')

            if (flag) {
                const differs = this.scoring === 'level_only' && weight !== null && marks.length > 0 && Math.abs(top - weight) >= TOLERANCE

                flag.hidden = !differs
                flag.querySelector('[data-weight-flag-text]').textContent = differs
                    ? `The top level is worth ${format(top)} but the weight, which is this criterion's maximum, is ${format(weight)}. Check which is right.`
                    : ''
            }
        })

        this.weighted = any && weightsKnown ? round(weighted) : null
        this.only = round(only)
    },

    get maximum() {
        if (this.scoring === 'level_x_weight') return this.weighted
        if (this.scoring === 'level_only') return this.only

        return null
    },

    get declaredNumber() {
        return number(this.declared)
    },

    /** What to tell the lecturer, and in which tone. Mirrors RubricScoring::problem(). */
    get status() {
        const declared = this.declaredNumber

        if (this.scoring === '') {
            return {
                tone: 'warn',
                text: declared === null
                    ? 'This section does not state a total. Choose how its marks are calculated.'
                    : `Neither or both formulas give the declared total of ${format(declared)}. Choose how this section is calculated.`,
            }
        }

        if (this.maximum === null) {
            return { tone: 'warn', text: 'A criterion has no weight, so marks cannot be multiplied by it. Add the weights, or use level only.' }
        }

        if (declared !== null && Math.abs(this.maximum - declared) >= TOLERANCE) {
            return { tone: 'warn', text: `The maximum is ${format(this.maximum)} but the declared total is ${format(declared)}.` }
        }

        return {
            tone: 'ok',
            text: declared === null
                ? `The maximum is ${format(this.maximum)}.`
                : `The maximum of ${format(this.maximum)} matches the declared total.`,
        }
    },

    format,
})
