import { highlight, choice, move } from './student-search-logic'

/*
 * Suggestions under the Students search box, from the first typed character. Typing is never blocked: a request is
 * sent 120ms after the last key, and an older request is cancelled when a newer one starts. Choosing a row fills the
 * form's filters and submits it (the table is drawn by the server, so the page is the thing that updates).
 */
export default () => ({
    query: '',
    items: [],
    open: false,
    active: -1,
    done: false,
    timer: null,
    request: null,
    url: '',

    init() {
        this.url = this.$el.dataset.url
        this.query = this.$refs.input.value
    },

    onInput() {
        clearTimeout(this.timer)
        this.active = -1

        if (this.query.trim() === '') {
            this.request?.abort()
            this.close()

            return
        }

        this.timer = setTimeout(() => this.fetchSuggestions(), 120)
    },

    async fetchSuggestions() {
        const asked = this.query.trim()

        this.request?.abort()
        this.request = new AbortController()

        try {
            const response = await fetch(`${this.url}?q=${encodeURIComponent(asked)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.request.signal,
                credentials: 'same-origin',
            })

            if (!response.ok) return

            const data = await response.json()

            // Only the answer to what is in the box now is shown.
            if (asked !== this.query.trim()) return

            this.items = data.results ?? []
            this.done = true
            this.open = true
        } catch (error) {
            // Aborted by a newer keystroke, or offline: the plain search still works.
        }
    },

    close() {
        this.open = false
        this.active = -1
        this.done = false
        this.items = []
    },

    clear() {
        this.query = ''
        this.request?.abort()
        this.close()
        this.$refs.input.focus()
    },

    pieces(text) {
        return highlight(text, this.query)
    },

    key(event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (!this.open || this.items.length === 0) return

            event.preventDefault()
            this.active = move(this.active, this.items.length, event.key)
            this.$nextTick(() => document.getElementById(`student-option-${this.active}`)?.scrollIntoView({ block: 'nearest' }))
        } else if (event.key === 'Enter') {
            if (this.open && this.active >= 0 && this.items[this.active]) {
                event.preventDefault()
                this.choose(this.items[this.active])
            }
        } else if (event.key === 'Escape') {
            if (this.open) {
                event.preventDefault()
                this.close()
            }
        } else if (event.key === 'Tab') {
            this.close()
        }
    },

    choose(item) {
        const next = choice(item)
        const form = this.$refs.input.form

        this.query = next.search
        this.$refs.input.value = next.search
        form.elements.group.value = next.group
        form.elements.subject.value = next.subject
        this.close()
        form.requestSubmit()
    },

    optionId(index) {
        return `student-option-${index}`
    },
})