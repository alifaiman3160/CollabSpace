import { splitPages, peekPlan } from './document-pages'

/*
 * A document read on the lecturer's side: the same A4 sheet, page by page, with the previous and next controls, the
 * page indicator, the slide between pages and the neighbours peeking out, but nothing can be edited or focused. The
 * body arrives already sanitised from the server (App\Support\DocumentSanitizer::render); here it is only cut at the
 * page breaks and shown a page at a time. Without JavaScript the sheet simply shows the whole body.
 */
const SLIDE_MS = 320
const EASE = 'cubic-bezier(0.22, 1, 0.36, 1)'

export default (config) => ({
    pages: splitPages(config.html),
    current: 0,
    slide: null,

    get plan() {
        return peekPlan(this.current, this.pages.length)
    },

    init() {
        if (config.html.trim() !== '') this.$refs.sheet.innerHTML = this.pages[0]
    },

    destroy() {
        this.cancelSlide()
    },

    cancelSlide() {
        if (!this.slide) return

        this.slide.animations.forEach((animation) => animation.cancel())
        this.slide.ghost.remove()
        this.slide = null
    },

    go(index) {
        if (index < 0 || index >= this.pages.length || index === this.current) return

        const sheet = this.$refs.sheet
        const dir = index > this.current ? 1 : -1
        const animate = !window.matchMedia('(prefers-reduced-motion: reduce)').matches && typeof sheet.animate === 'function'

        this.cancelSlide()

        // A still copy of the sheet that is leaving, so it slides away while the new page slides in.
        let ghost = null

        if (animate) {
            ghost = sheet.cloneNode(true)
            ghost.removeAttribute('x-ref')
            ghost.setAttribute('aria-hidden', 'true')
            ghost.style.cssText = `position:absolute;margin:0;pointer-events:none;left:${sheet.offsetLeft}px;top:${sheet.offsetTop}px;width:${sheet.offsetWidth}px;height:${sheet.offsetHeight}px`
            sheet.parentNode.appendChild(ghost)
        }

        this.current = index
        sheet.innerHTML = this.pages[index]
        sheet.scrollTop = 0

        if (ghost) {
            const distance = (sheet.offsetWidth + 40) * dir
            const timing = { duration: SLIDE_MS, easing: EASE, fill: 'both' }
            const entering = sheet.animate([{ transform: `translateX(${distance}px)`, opacity: 0.25 }, { transform: 'translateX(0)', opacity: 1 }], timing)
            const leaving = ghost.animate([{ transform: 'translateX(0)', opacity: 1 }, { transform: `translateX(${-distance}px)`, opacity: 0.25 }], timing)

            this.slide = { ghost, animations: [entering, leaving] }
            leaving.onfinish = () => { if (this.slide && this.slide.ghost === ghost) this.cancelSlide() }
        }
    },

    next() { this.go(this.current + 1) },

    prev() { this.go(this.current - 1) },
})