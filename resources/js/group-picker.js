/*
 * The group picker's keyboard: the arrow keys move between its cards (Enter, or Space, picks the focused one, as a
 * link or a button always does). Nothing is stored, and the listener is on the picker itself, so it goes with the page.
 */
export default () => ({
    move(event) {
        if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return

        const cards = [...this.$el.querySelectorAll('[data-group-card]')]
        const at = cards.indexOf(document.activeElement)

        if (at === -1) return

        event.preventDefault()

        const last = cards.length - 1
        const to = event.key === 'Home' ? 0
            : event.key === 'End' ? last
                : (event.key === 'ArrowLeft' || event.key === 'ArrowUp') ? Math.max(0, at - 1) : Math.min(last, at + 1)

        cards[to].focus()
    },
})