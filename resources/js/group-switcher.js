import { matches, placeBelow } from './group-switcher-logic'
import { nextIndex } from './sidebar-logic'

/*
 * The lecturer's group switcher: a button that opens a card listing every group, grouped by subject. The card is
 * fixed-positioned under the button (see placeBelow), the options are real links (so choosing one is a normal
 * wire:navigate visit), and arrow keys move focus between the options that are showing.
 */
export default () => ({
    open: false,
    query: '',
    empty: false,

    toggle() {
        this.open ? this.close() : this.show()
    },

    show() {
        this.open = true
        this.query = ''
        this.filter()

        this.$nextTick(() => {
            this.place()
            ;(this.$refs.search ?? this.$el.querySelector('[data-switch-option][aria-selected="true"]'))?.focus()
            this.$el.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' })
        })
    },

    close(returnFocus = false) {
        if (!this.open) return

        this.open = false

        if (returnFocus) this.$refs.button.focus()
    },

    place() {
        const card = this.$refs.card
        const rect = (el) => { const r = el.getBoundingClientRect(); return { left: r.left, top: r.top, width: r.width, height: r.height } }
        const spot = placeBelow(rect(this.$refs.button), { width: card.offsetWidth, height: card.offsetHeight }, { width: window.innerWidth, height: window.innerHeight })

        card.style.left = `${spot.left}px`
        card.style.top = `${spot.top}px`
    },

    options() {
        return [...this.$el.querySelectorAll('[data-switch-option]')].filter((option) => !option.closest('[hidden]'))
    },

    /** Shows the groups that match the box (name or subject), hides a subject with none left. */
    filter() {
        let shown = 0

        this.$el.querySelectorAll('[data-switch-section]').forEach((section) => {
            let any = false

            section.querySelectorAll('[data-switch-item]').forEach((item) => {
                const hit = matches(item.dataset.search, this.query)

                item.hidden = !hit
                any = any || hit
                shown += hit ? 1 : 0
            })

            section.hidden = !any
        })

        this.empty = shown === 0
    },

    key(event) {
        if (!this.open) return

        if (event.key === 'Escape') {
            event.preventDefault()
            event.stopPropagation()
            this.close(true)

            return
        }

        if (event.key === 'Tab') {
            this.close()

            return
        }

        const options = this.options()
        const inSearch = document.activeElement === this.$refs.search
        const at = options.indexOf(document.activeElement)

        // From the box, Down enters the list; Up from the first option goes back to the box.
        if (inSearch && event.key === 'ArrowDown' && options.length) {
            event.preventDefault()
            options[0].focus()

            return
        }

        if (!inSearch && at === 0 && event.key === 'ArrowUp' && this.$refs.search) {
            event.preventDefault()
            this.$refs.search.focus()

            return
        }

        if (inSearch && !['ArrowUp', 'ArrowDown'].includes(event.key)) return

        const to = nextIndex(at, options.length, event.key)

        if (to === null || (inSearch && event.key !== 'ArrowDown')) return

        event.preventDefault()
        options[to]?.focus()
    },
})
