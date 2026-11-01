import { place, splitShortcut, upgradePlan } from './tooltip-logic'

/*
 * One tooltip for the whole app. Anything with data-tooltip (or a rich <x-tooltip>) gets it, on hover and on keyboard
 * focus; plain title attributes are upgraded on the fly, so the browser's own tooltip never shows. It is drawn once, on
 * a layer on the body, so nothing clips it: not overflow, not a sticky header, not the sidebar.
 */
const SHOW_DELAY = 60
const TRIGGER = '[data-tooltip], [data-tooltip-rich]'

let layer = null
let current = null
let timer = null
let frame = 0

const tipLayer = () => {
    if (layer && layer.isConnected) return layer

    layer = document.createElement('div')
    layer.id = 'cs-tooltip'
    layer.className = 'cs-tooltip'
    layer.setAttribute('role', 'tooltip')
    layer.innerHTML = '<div class="cs-tooltip-body"></div><span class="cs-tooltip-arrow" aria-hidden="true"></span>'
    document.body.appendChild(layer)

    return layer
}

const fill = (tip, trigger) => {
    const body = tip.querySelector('.cs-tooltip-body')
    const template = trigger.querySelector(':scope > template[data-tooltip-content]')

    body.replaceChildren()

    if (template) {
        body.appendChild(template.content.cloneNode(true))

        return
    }

    const { text, keys } = splitShortcut(trigger.getAttribute('data-tooltip'))

    body.append(text)

    if (keys.length) {
        const chips = document.createElement('span')

        chips.className = 'cs-tooltip-keys'
        keys.forEach((key) => {
            const chip = document.createElement('kbd')

            chip.textContent = key
            chips.appendChild(chip)
        })
        body.appendChild(chips)
    }
}

const show = (trigger) => {
    if (!trigger.isConnected) return

    const tip = tipLayer()

    fill(tip, trigger)

    // Measure it where it will be seen, hidden, then put it in place.
    tip.classList.remove('is-visible')
    tip.style.visibility = 'hidden'
    tip.style.left = '0px'
    tip.style.top = '0px'

    const size = tip.getBoundingClientRect()
    const rect = trigger.getBoundingClientRect()
    const spot = place(
        { left: rect.left, top: rect.top, width: rect.width, height: rect.height },
        { width: size.width, height: size.height },
        { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight },
        8,
        8,
        trigger.getAttribute('data-tooltip-side') === 'right' ? 'right' : 'top',
    )

    tip.style.left = `${spot.left}px`
    tip.style.top = `${spot.top}px`
    tip.style.setProperty('--arrow', `${spot.arrow}px`)
    tip.dataset.side = spot.side
    tip.style.visibility = ''

    if (current && current !== trigger) release(current)

    current = trigger
    trigger.setAttribute('aria-describedby', tip.id)
    trigger.setAttribute('data-tip-active', '')

    requestAnimationFrame(() => tip.classList.add('is-visible'))
}

const release = (trigger) => {
    trigger.removeAttribute('aria-describedby')
    trigger.removeAttribute('data-tip-active')
}

const hide = () => {
    clearTimeout(timer)
    timer = null

    if (layer) layer.classList.remove('is-visible')

    if (current) {
        release(current)
        current = null
    }
}

const schedule = (trigger, delay = SHOW_DELAY) => {
    if (trigger === current) return

    clearTimeout(timer)
    timer = setTimeout(() => show(trigger), delay)
}

/** A disabled control gets no pointer events of its own, so it is found by where the pointer is. */
let disabledAt = 0

const disabledUnder = (x, y) => {
    const now = performance.now()

    if (now - disabledAt < 80) return null

    disabledAt = now

    for (const el of document.querySelectorAll('[data-tooltip]:disabled, [data-tooltip][aria-disabled="true"]')) {
        const r = el.getBoundingClientRect()

        if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return el
    }

    return null
}

/* ----- Turning title attributes into tooltips ----- */

const upgrade = (el) => {
    const plan = upgradePlan(el)

    if (!plan) return

    el.removeAttribute('title')

    if (plan.text === '') return

    el.setAttribute('data-tooltip', plan.text)

    if (plan.aria) el.setAttribute('aria-label', plan.aria)
}

const upgradeTree = (root) => {
    if (!root || root.nodeType !== 1) return

    upgrade(root)
    root.querySelectorAll('[title]').forEach(upgrade)

    // An element that already carries data-tooltip (the server renames title) still needs a name if it is icon-only.
    const nameless = (el) => !el.hasAttribute('aria-label') && !el.hasAttribute('aria-labelledby') && (el.textContent ?? '').trim() === ''
        && !el.querySelector('img[alt]:not([alt=""])') && !el.hasAttribute('data-no-tooltip')

    const scan = [...(root.matches?.('[data-tooltip]') ? [root] : []), ...root.querySelectorAll('[data-tooltip]')]

    scan.forEach((el) => {
        if (nameless(el) && !el.closest('.doc-body, [data-no-tooltip]')) el.setAttribute('aria-label', splitShortcut(el.getAttribute('data-tooltip')).text)
    })
}

const pending = new Set()

const flush = () => {
    frame = 0

    pending.forEach((node) => { if (node.isConnected) upgradeTree(node) })
    pending.clear()
}

const queue = (node) => {
    pending.add(node)

    if (!frame) frame = requestAnimationFrame(flush)
}

export const startTooltips = () => {
    if (window.__csTooltips) return

    window.__csTooltips = true

    upgradeTree(document.documentElement)

    // Anything added later (Livewire, Alpine, a page swapped in by wire:navigate), and any title set after the fact.
    new MutationObserver((records) => {
        for (const record of records) {
            if (record.type === 'attributes') {
                queue(record.target)
            } else {
                record.addedNodes.forEach((node) => { if (node.nodeType === 1) queue(node) })
            }
        }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['title'] })

    document.addEventListener('livewire:navigated', () => { hide(); upgradeTree(document.documentElement) })

    document.addEventListener('pointerover', (event) => {
        if (event.pointerType === 'touch') return

        const trigger = event.target.closest?.(TRIGGER)

        if (trigger) { schedule(trigger) } else if (current || timer) { hide() }
    })

    document.addEventListener('pointermove', (event) => {
        if (event.pointerType === 'touch' || event.target.closest?.(TRIGGER)) return

        const under = disabledUnder(event.clientX, event.clientY)

        if (under) { schedule(under) } else if (current && current.matches(':disabled, [aria-disabled="true"]')) { hide() }
    })

    document.addEventListener('pointerout', (event) => {
        if (!event.relatedTarget) hide()
    })

    document.addEventListener('pointerdown', hide, true)

    document.addEventListener('focusin', (event) => {
        const trigger = event.target.closest?.(TRIGGER)

        if (trigger && trigger.matches(':focus-visible')) schedule(trigger, 0)
    })

    document.addEventListener('focusout', hide)
    document.addEventListener('scroll', hide, true)
    window.addEventListener('resize', hide)
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') hide() })
    document.addEventListener('cs:hide-tooltip', hide)
}
