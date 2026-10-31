import { tiles, visibleIn } from './progress-filter-logic'

/*
 * Monitor progress: the subject pills show only that subject's groups (and the activity and flagged members that
 * belong to them), and the four tiles follow what is showing. Nothing reloads; a card that comes back into view fades
 * in (opacity and a few pixels up), and the cards that stayed do not move.
 */
export default (initial) => ({
    subject: null,
    groups: initial.groups,
    attention: initial.attention,
    overdue: initial.overdue,
    average: initial.average,
    cards: [],
    rows: [],

    init() {
        this.cards = [...this.$el.querySelectorAll('[data-pg]')]
        this.rows = [...this.$el.querySelectorAll('[data-pg-row]')]

        // The entrance class comes off once it has played, so showing and hiding never replay it.
        this.cards.forEach((card) => card.addEventListener('animationend', () => card.classList.remove('cs-card-in'), { once: true }))
        setTimeout(() => this.cards.forEach((card) => card.classList.remove('cs-card-in')), 1500)
    },

    pick(subject) {
        this.subject = subject
        this.apply()
    },

    apply() {
        const quiet = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        const data = this.cards.map((card) => ({ subject: card.dataset.subject, overdue: card.dataset.overdue, progress: card.dataset.progress, card }))
        const shown = visibleIn(data, this.subject)

        data.forEach((item) => {
            const show = shown.includes(item)
            const wasHidden = item.card.hidden

            item.card.hidden = !show

            if (show && wasHidden && !quiet) {
                item.card.classList.remove('cs-card-in')
                void item.card.offsetWidth
                item.card.style.setProperty('--i', '0')
                item.card.classList.add('cs-card-in')
                item.card.addEventListener('animationend', () => item.card.classList.remove('cs-card-in'), { once: true })
            }
        })

        this.rows.forEach((row) => { row.hidden = this.subject !== null && row.dataset.subject !== String(this.subject) })

        Object.assign(this, tiles(shown))
    },
})