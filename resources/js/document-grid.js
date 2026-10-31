import { arrange, filtered } from './document-grid-logic'

/*
 * The Documents list's search and sort. Everything is already on the page (the list is one group's documents), so this
 * only hides cards that do not match the title and sets their order; the New document tile always has the highest
 * order, so it stays last. Hiding and reordering never replay the entrance of cards that are already there: the class
 * comes off each card as soon as it has played, and only a card that comes back into view fades in again.
 *
 * It also tells the previews how big the cards are: each preview is a full A4 page scaled to its card's width.
 */
const PAGE_WIDTH = 793.7   // 21cm in CSS pixels

export default () => ({
    query: '',
    sort: 'edited',
    shown: 0,
    cards: [],
    observer: null,

    init() {
        this.cards = [...this.$el.querySelectorAll('[data-doc]')]
        this.shown = this.cards.length

        const settle = (card) => card.classList.remove('cs-card-in')
        const entering = [...this.cards, ...this.$el.querySelectorAll('[data-new-tile]')]

        entering.forEach((card) => card.addEventListener('animationend', () => settle(card), { once: true }))
        // With reduced motion nothing animates, so nothing ends: clear the class anyway.
        setTimeout(() => entering.forEach(settle), 1500)

        this.apply()
        this.fit()
    },

    destroy() {
        this.observer?.disconnect()
    },

    /** Scale every preview to the width the grid gave its cards. */
    fit() {
        const grids = [...this.$el.querySelectorAll('[data-document-grid]')]

        if (!grids.length || !window.ResizeObserver) return

        this.observer = new ResizeObserver((entries) => entries.forEach((entry) => {
            const width = entry.contentRect.width

            if (width > 0) entry.target.closest('[data-document-grid]').style.setProperty('--thumb-scale', String(width / PAGE_WIDTH))
        }))

        grids.forEach((grid) => { const thumb = grid.querySelector('.doc-thumb'); if (thumb) this.observer.observe(thumb) })
    },

    clear() {
        this.query = ''
        this.apply()
        this.$nextTick(() => document.getElementById('document-search')?.focus())
    },

    apply() {
        const items = this.cards.map((card) => ({ title: card.dataset.title, edited: card.dataset.edited, card }))
        const result = filtered(items, this.query)

        arrange(items, this.sort).forEach((item, position) => { item.card.style.order = position })

        this.shown = result.shown

        items.forEach((item, index) => {
            const show = result.visible[index]
            const wasHidden = item.card.hidden

            item.card.hidden = !show

            // Only a card that was out of the way fades back in; the ones that stayed do not move.
            if (show && wasHidden && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                item.card.classList.remove('cs-card-in')
                void item.card.offsetWidth
                item.card.style.setProperty('--i', '0')
                item.card.classList.add('cs-card-in')
                item.card.addEventListener('animationend', () => item.card.classList.remove('cs-card-in'), { once: true })
            }
        })
    },
})