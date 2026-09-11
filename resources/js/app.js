import './bootstrap';

/*
 * Alpine is not imported here on purpose.
 *
 * Livewire bundles its own Alpine and starts it, and the layouts load
 * @livewireScripts on every page. Importing Alpine here as well would run two
 * instances side by side on any page with a Livewire component, which
 * double-binds every directive.
 */
