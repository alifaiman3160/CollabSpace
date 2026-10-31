/*
 * Switching between group chats without reloading the page.
 *
 * The left list and the page shell stay put. A click asks the one chat component on the page to open
 * another group (a Livewire event); the component checks the student belongs to it and re-renders its
 * header, messages and composer, and tells this script when it has finished. Meanwhile the old
 * conversation fades out, and a light placeholder shows only if the swap takes more than about 150ms.
 *
 * Clicks that arrive while a switch is in flight are coalesced: only the last group asked for is
 * opened, so a slow earlier answer can never land on top of a newer one. The address bar and the page
 * title follow the open group, and Back and Forward replay the same switch. Unsent text is kept per
 * group, in memory only. Without JavaScript every row is an ordinary link.
 */
export default ({ current, groups }) => ({
    current,
    loaded: current,
    want: current,
    busy: false,
    leaving: false,
    skeleton: false,
    announce: '',
    drafts: {},
    timer: null,
    listeners: [],

    init() {
        /* The page's own history entry belongs to Livewire's navigate, so it is left alone; only the entries
           this switcher pushes carry a { chat } state. Going back to Livewire's entry is Livewire's to handle. */
        this.listen('popstate', (event) => {
            if (event.state?.alpine) return

            const id = event.state?.chat ?? this.idFromPath()

            if (id && groups[id]) {
                document.title = this.titleFor(id)
                this.request(id)
            }
        })

        this.listen('chat-opened', (event) => this.opened(event.detail?.id ?? this.want))
        this.listen('chat-missing', () => this.settle())
    },

    /* Window listeners are removed when the page is left (wire:navigate keeps the window), so repeated
       visits never stack them up. */
    listen(name, handler) {
        window.addEventListener(name, handler)
        this.listeners.push([name, handler])
    },

    destroy() {
        this.listeners.forEach(([name, handler]) => window.removeEventListener(name, handler))
        this.listeners = []
        clearTimeout(this.timer)
    },

    reduced() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches
    },

    idFromPath() {
        const match = window.location.pathname.match(/\/chat\/g\/(\d+)/)

        return match ? Number(match[1]) : null
    },

    titleFor(id) {
        return `${groups[id].name} — Group chat — CollabSpace`
    },

    /** A click on a group's row or chip. Anything but a plain left click is left to the browser. */
    go(event, id) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
        if (!groups[id] || !window.Livewire) return

        event.preventDefault()

        if (id === this.want && !this.busy) return

        window.history.pushState({ chat: id }, '', groups[id].url)
        document.title = this.titleFor(id)

        this.request(id)
    },

    request(id) {
        if (!this.busy) this.saveDraft()

        this.want = id
        this.current = id

        if (!this.busy) this.run()
    },

    run() {
        this.busy = true
        this.leaving = true

        clearTimeout(this.timer)

        if (!this.reduced()) {
            this.timer = setTimeout(() => (this.skeleton = true), 150)
        }

        window.Livewire.dispatch('open-group', { id: this.want })
    },

    /** The component has finished showing a group. */
    opened(id) {
        // Somebody clicked again while that was loading: skip straight to the last one asked for.
        if (id !== this.want) {
            this.loaded = id
            this.run()

            return
        }

        this.loaded = id
        this.settle()

        this.restoreDraft(id)
        this.announce = `Opened ${groups[id].name}`

        if (!this.reduced()) {
            const pane = this.$refs.pane

            pane.classList.remove('cs-swap-in')
            void pane.offsetWidth
            pane.classList.add('cs-swap-in')
        }
    },

    settle() {
        clearTimeout(this.timer)
        this.busy = false
        this.leaving = false
        this.skeleton = false
    },

    textarea() {
        return document.querySelector('[data-chat-body]')
    },

    saveDraft() {
        const field = this.textarea()

        if (field) this.drafts[this.loaded] = field.value
    },

    restoreDraft(id) {
        const field = this.textarea()

        if (!field) return

        field.value = this.drafts[id] ?? ''
        field.dispatchEvent(new Event('input', { bubbles: true }))
    },
})
