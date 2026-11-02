import { decideCollapsed, parseOpen, serializeOpen, withSection, matchesPath, activeChild, nextIndex, placeFlyout } from './sidebar-logic'

/*
 * The sidebar: collapsible to a slim rail, with sections that open into the person's groups.
 *
 * The aside is kept across wire:navigate (x-persist), so this component lives as long as the visit: nothing is
 * re-rendered or re-animated when the page changes. The server draws the first paint from the same two cookies this
 * writes (see App\Support\SidebarState), so a reload shows the right state at once; localStorage is only a fallback
 * for a browser that blocks cookies.
 */
const COLLAPSE_KEY = 'cs_sidebar'
const OPEN_KEY = 'cs_nav_open'
const CLOSE_DELAY = 150

// Methods run from handlers on inner elements (the item that opens), where Alpine's $el is that element, so the
// sidebar itself is found by name, never through $el.
const sidebar_root = () => document.querySelector('aside.cs-sidebar')

const readCookie = (name) => {
    const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`))

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

const readStored = (name) => {
    try { return window.localStorage.getItem(name) } catch { return null }
}

const save = (name, value) => {
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=31536000; SameSite=Lax`

    try { window.localStorage.setItem(name, value) } catch { /* private window or blocked storage: the cookie is enough */ }
}

let shared = null

/*
 * The state is ONE object for the whole visit, and every component the page starts reads and writes it. Livewire keeps
 * the sidebar across wire:navigate by moving the very same element into the next page, and Alpine then starts x-data
 * on it a second time. With a fresh state each time the toggle flipped one copy while the sidebar's attributes
 * followed the other, so nothing seemed to happen after the first page change.
 */
const state = () => (shared ??= window.Alpine.reactive({ ready: false, collapsed: false, open: [], flyout: null, closeTimer: null, hovered: false }))

const component = () => ({
    get ready() { return state().ready },
    set ready(value) { state().ready = value },
    get collapsed() { return state().collapsed },
    set collapsed(value) { state().collapsed = value },
    get open() { return state().open },
    set open(value) { state().open = value },
    get flyout() { return state().flyout },
    set flyout(value) { state().flyout = value },
    get closeTimer() { return state().closeTimer },
    set closeTimer(value) { state().closeTimer = value },
    get hovered() { return state().hovered },
    set hovered(value) { state().hovered = value },

    /**
     * Started by Alpine on first load, and again whenever wire:navigate has moved the kept sidebar into a new page. The
     * state is read and the listeners are added only the first time; every start paints, and the `x-effect` on the
     * aside re-draws the attributes whenever the state changes (it belongs to the start, so it is never lost).
     */
    init() {
        const root = sidebar_root()

        if (!this.ready) {
            this.ready = true

            // The server drew the state it read from the cookie; with no cookie the width (or the stored copy) decides.
            this.collapsed = root.hasAttribute('data-collapsed')

            if (root.hasAttribute('data-auto')) {
                this.collapsed = decideCollapsed({ cookie: null, stored: readStored(COLLAPSE_KEY), width: window.innerWidth })
            }

            this.open = parseOpen(root.getAttribute('data-open') ?? readCookie(OPEN_KEY) ?? readStored(OPEN_KEY))

            document.addEventListener('livewire:navigated', () => {
                this.closeFlyout()
                this.refreshActive(false)
                this.followPage()
                this.paint()
            })

            // On the window, in the capture phase: the first to see the press, before wire:navigate on the link can follow it.
            // Livewire navigates on mousedown and on Enter (not on click), so those are stopped here for an item that opens
            // while the rail is slim; its click then opens the card (the item's own page is the card's title link).
            window.addEventListener('click', (event) => this.railClick(event), true)
            ;['mousedown', 'mouseup'].forEach((type) => window.addEventListener(type, (event) => { if (this.opensCard(event)) event.stopPropagation() }, true))
            window.addEventListener('keydown', (event) => { if (event.key === 'Enter' && this.opensCard(event)) event.stopPropagation() }, true)
            window.addEventListener('resize', () => this.placeFlyout())
            root.querySelector('nav')?.addEventListener('scroll', () => this.placeFlyout(), { passive: true })

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && this.flyout) this.closeFlyout(true)
            })

            document.addEventListener('pointerdown', (event) => {
                if (this.flyout && !event.target.closest?.('[data-nav-group]')) this.closeFlyout()
            })
        }

        this.paint()
        this.refreshActive(true)
        this.followPage()
    },

    /** Everything that depends on the state and is drawn by attributes rather than by Alpine. */
    paint() {
        const root = sidebar_root()

        root.toggleAttribute('data-collapsed', this.collapsed)
        root.removeAttribute('data-auto')

        root.querySelectorAll('[data-rail-tip]').forEach((el) => {
            const text = el.getAttribute('data-rail-tip')

            // An item that opens shows its card (whose bold title names it), never a second box.
            const opens = el.matches('[data-nav-link]') && el.closest('[data-nav-group]') !== null

            if (this.collapsed) {
                if (!opens) {
                    el.setAttribute('data-tooltip', text)
                    el.setAttribute('data-tooltip-side', 'right')
                }

                if (el.matches('[data-nav-link]')) el.setAttribute('aria-label', text)
            } else {
                el.removeAttribute('data-tooltip')
                el.removeAttribute('data-tooltip-side')

                if (el.matches('[data-nav-link]')) el.removeAttribute('aria-label')
            }
        })

        root.querySelectorAll('[data-rail-side]').forEach((el) => {
            if (this.collapsed) { el.setAttribute('data-tooltip-side', 'right') } else { el.removeAttribute('data-tooltip-side') }
        })
    },

    toggle() {
        this.closeFlyout()
        this.collapsed = !this.collapsed
        save(COLLAPSE_KEY, this.collapsed ? 'collapsed' : 'expanded')
    },

    /* ----- Sections ----- */

    isOpen(key) {
        return this.open.includes(key)
    },

    setSection(key, open) {
        this.open = withSection(this.open, key, open)
        save(OPEN_KEY, serializeOpen(this.open))
    },

    toggleSection(key) {
        this.setSection(key, !this.isOpen(key))
    },

    /** Open: the inline list when the sidebar is wide, the floating card when it is the slim rail. */
    shown(key) {
        return this.collapsed ? this.flyout === key : this.isOpen(key)
    },

    /**
     * The page names the section that holds it (the server renders [data-nav-current] in the page, from the request).
     * The sidebar is kept across wire:navigate, so this is read again on every navigation: that section opens, every
     * other closes, and a page in none of them closes all.
     */
    followPage() {
        const holder = document.querySelector('[data-nav-current]')

        if (!holder) return

        this.open = parseOpen(holder.getAttribute('data-nav-current'))
        save(OPEN_KEY, serializeOpen(this.open))
    },

    /* ----- The highlight follows the address, for the items that open ----- */

    refreshActive(first) {
        const path = window.location.pathname

        sidebar_root().querySelectorAll('[data-nav-group]').forEach((group) => {
            const link = group.querySelector('[data-nav-link]')
            const children = [...group.querySelectorAll('[data-child]')]
            const index = activeChild(path, children.map((child) => ({ match: child.dataset.match || null, exact: child.hasAttribute('data-exact') })))
            const onItem = matchesPath(path, link.dataset.match)

            link.toggleAttribute('data-current', onItem)
            link.toggleAttribute('data-parent-current', index !== null)

            if (onItem) { link.setAttribute('aria-current', index !== null ? 'true' : 'page') } else { link.removeAttribute('aria-current') }

            children.forEach((child, i) => {
                child.toggleAttribute('data-current', i === index)

                if (i === index) { child.setAttribute('aria-current', 'page') } else { child.removeAttribute('aria-current') }
            })

        })
    },

    /* ----- The floating card in the slim rail ----- */

    openFlyout(key, focusFirst = false) {
        if (!this.collapsed) return

        clearTimeout(this.closeTimer)
        this.flyout = key

        // One box at a time: a tooltip that is showing makes way for the card.
        document.dispatchEvent(new CustomEvent('cs:hide-tooltip'))

        this.$nextTick(() => {
            this.placeFlyout()

            if (focusFirst) sidebar_root()?.querySelector(`[data-nav-group="${key}"] .cs-subnav a`)?.focus()
        })
    },

    /** Puts the open card beside the icon that opened it (see placeFlyout in sidebar-logic.js). Run on open, resize and scroll. */
    placeFlyout() {
        const key = this.flyout
        const root = sidebar_root()
        const group = key && root ? root.querySelector(`[data-nav-group="${key}"]`) : null
        const card = group?.querySelector('.cs-subnav')
        const link = group?.querySelector('[data-nav-link]')

        if (!card || !link) return

        const rect = (el) => { const r = el.getBoundingClientRect(); return { left: r.left, top: r.top, width: r.width, height: r.height } }
        const spot = placeFlyout(rect(root), rect(link), { width: card.offsetWidth, height: card.offsetHeight }, { width: window.innerWidth, height: window.innerHeight })

        card.style.setProperty('--fly-left', `${spot.left}px`)
        card.style.setProperty('--fly-top', `${spot.top}px`)
        card.style.setProperty('--fly-arrow', `${spot.arrow}px`)
    },

    closeFlyout(returnFocus = false) {
        clearTimeout(this.closeTimer)

        const key = this.flyout

        this.flyout = null
        this.hovered = false

        if (returnFocus && key) sidebar_root()?.querySelector(`[data-nav-group="${key}"] [data-nav-link]`)?.focus()
    },
    hoverIn(key) {
        if (!this.collapsed) return

        if (this.flyout !== key) this.hovered = true

        this.openFlyout(key)
    },

    hoverOut() {
        if (!this.flyout) return

        clearTimeout(this.closeTimer)
        this.closeTimer = setTimeout(() => this.closeFlyout(), CLOSE_DELAY)
    },

    /**
     * In the slim rail, an item that opens does not navigate on a click: it opens its card (its own page is the card's
     * "All groups"). Caught on the aside before wire:navigate on the link can see the click.
     */
    railClick(event) {
        if (!this.collapsed) return

        const link = event.target.closest?.('aside.cs-sidebar [data-nav-link]')
        const group = link?.closest('[data-nav-group]')

        if (!group) return

        const key = group.dataset.navGroup

        event.preventDefault()
        event.stopPropagation()

        // A card the pointer opened by hovering stays open on the click (it is the same gesture); otherwise a click toggles.
        if (this.flyout === key && this.hovered) {
            this.hovered = false
        } else if (this.flyout === key) {
            this.closeFlyout()
        } else {
            this.openFlyout(key, event.detail === 0)
        }
    },

    /** True for an event on a main item that opens a card, while the sidebar is the slim rail. */
    opensCard(event) {
        return this.collapsed && event.target.closest?.('aside.cs-sidebar [data-nav-group] > [data-nav-link]') !== null
    },

    railKey(event, key) {
        if (!this.collapsed) return

        if (event.key === ' ' || event.key === 'ArrowDown' || event.key === 'ArrowRight') {
            event.preventDefault()
            this.openFlyout(key, true)
        }
    },

    flyoutKey(event, key) {
        const links = [...event.currentTarget.querySelectorAll('a')]
        const next = nextIndex(links.indexOf(document.activeElement), links.length, event.key)

        if (next !== null) {
            event.preventDefault()
            links[next].focus()
        } else if (event.key === 'Escape') {
            event.preventDefault()
            event.stopPropagation()
            this.closeFlyout(true)
        }
    },

    focusLeft(event) {
        if (this.flyout && !event.currentTarget.contains(event.relatedTarget)) this.closeFlyout()
    },
})

export default () => {
    try {
        return component()
    } catch (error) {
        // Silent in production; in development a sidebar that cannot start says so.
        if (import.meta.env?.DEV) console.error('[sidebar] could not start:', error)

        throw error
    }
}
