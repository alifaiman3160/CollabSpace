import './bootstrap';

/*
 * Alpine is not imported here on purpose.
 *
 * Livewire bundles its own Alpine and starts it, and the layouts load
 * @livewireScripts on every page. Importing Alpine here as well would run two
 * instances side by side on any page with a Livewire component, which
 * double-binds every directive.
 */

import rubricSection from './rubric-section';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('rubricSection', rubricSection);
});

import documentViewer from './document-viewer';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('documentViewer', documentViewer);
});

import progressFilter from './progress-filter';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('progressFilter', progressFilter);
});

import groupPicker from './group-picker';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('groupPicker', groupPicker);
});

import documentGrid from './document-grid';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('documentGrid', documentGrid);
});

import pdfDownload from './pdf-download';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pdfDownload', pdfDownload);
});

import documentEditor from './document-editor';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('documentEditor', documentEditor);
});

import evidencePicker from './evidence-picker';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('evidencePicker', evidencePicker);
});

import markingSuggest from './marking-suggest';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('markingSuggest', markingSuggest);
});

import loginIntro, { loginBackdrop } from './login-intro';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('loginIntro', loginIntro);
    window.Alpine.data('loginBackdrop', loginBackdrop);
});

import announcementStrip from './announcement-strip';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('announcementStrip', announcementStrip);
});

/*
 * A card, message or notification that a Livewire update adds gets a short fade-in. Items already on the
 * page when it loads are never touched, so nothing animates on first load.
 */
const markNewItems = () => {
    window.Livewire.hook('morph.added', ({ el }) => {
        if (!(el instanceof Element)) return

        const items = el.matches('[data-cs-item]') ? [el] : [...el.querySelectorAll('[data-cs-item]')]

        items.forEach((item) => item.classList.add('cs-in'))
    })
}

if (window.Livewire) {
    markNewItems()
} else {
    document.addEventListener('livewire:init', markNewItems)
}
import chatSwitcher from './chat-switcher';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('chatSwitcher', chatSwitcher);
});


/*
 * Moving between pages with wire:navigate (the sidebar links).
 *
 * The sidebar is kept as it is; only the content column animates, and only once, after the new page is in
 * the DOM: it fades in with a tiny vertical shift (.cs-page-in in app.css). There is no leave animation, so
 * nothing fights the enter, and nothing moves sideways. The first page load never animates, and reduced
 * motion gets none.
 */
let navigating = false

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches

document.addEventListener('livewire:navigate', () => {
    navigating = true
})

document.addEventListener('livewire:navigated', () => {
    // wire:current marks the current sidebar link with data-current; screen readers get aria-current.
    document.querySelectorAll('nav[aria-label="Main"] a').forEach((link) => {
        if (link.hasAttribute('data-current')) {
            link.setAttribute('aria-current', 'page')
        } else {
            link.removeAttribute('aria-current')
        }
    })

    if (!navigating) return
    navigating = false

    if (reducedMotion()) return

    const content = document.querySelector('[data-page-content]')

    if (!content) return

    content.classList.remove('cs-page-in')
    void content.offsetWidth
    content.classList.add('cs-page-in')
    content.addEventListener('animationend', () => content.classList.remove('cs-page-in'), { once: true })
})
