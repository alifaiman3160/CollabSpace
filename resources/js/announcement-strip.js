/*
 * The announcements strip inside a subject card on the student dashboard.
 *
 * Opening it, and "Show earlier", are plain toggles. The first time a student
 * opens a strip, one small request records that they have read the announcements
 * now showing (and another when they ask for the earlier ones); the red dot goes
 * the moment the strip opens. Nothing is sent on page load.
 *
 * If the page is opened at #announcement-<id> (the notification's link) the card
 * that owns that announcement opens by itself, which counts as opening it.
 */
export default ({ ids, unreadIds, url, token }) => ({
    ids,
    unread: unreadIds.length,
    pending: [...unreadIds],
    open: false,
    earlier: false,
    // True only while the page is arriving already open (from a notification), so nothing animates on load.
    instant: false,

    init() {
        this.instant = true
        this.reveal()
        requestAnimationFrame(() => requestAnimationFrame(() => (this.instant = false)))
    },

    toggle() {
        this.open = !this.open

        if (this.open) this.opened()
    },

    toggleEarlier() {
        this.earlier = !this.earlier

        if (this.earlier) this.record(this.ids.slice(3))
    },

    reveal() {
        const match = window.location.hash.match(/^#announcement-(\d+)$/)

        if (!match) return

        const id = Number(match[1])
        const position = this.ids.indexOf(id)

        if (position === -1) return

        this.open = true
        this.opened()

        if (position >= 3) {
            this.earlier = true
            this.record(this.ids.slice(3))
        }

        this.$nextTick(() => document.getElementById(`announcement-${id}`)?.scrollIntoView({ block: 'center' }))
    },

    /** The strip is open: the dot clears now, and what is on show is recorded as read. */
    opened() {
        this.unread = 0
        this.record(this.ids.slice(0, 3))
    },

    /** Tell the server about the unread ones among these, once each. */
    record(shown) {
        const fresh = shown.filter((id) => this.pending.includes(id))

        if (fresh.length === 0) return

        this.pending = this.pending.filter((id) => !fresh.includes(id))

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ ids: fresh }),
        }).catch(() => {
            // Not worth interrupting anyone: it will be recorded the next time they open it.
            this.pending = [...this.pending, ...fresh]
        })
    },
})
