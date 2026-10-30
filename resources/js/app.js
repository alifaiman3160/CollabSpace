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
