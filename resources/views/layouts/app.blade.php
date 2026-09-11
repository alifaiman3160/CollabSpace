{{--
    Breeze's <x-app-layout> shell. It delegates to the CollabSpace design-system
    layout so Breeze-scaffolded pages (profile) sit in the same shell as the rest
    of the app rather than carrying a second, off-design chrome.

    Breeze passes its page title as a $header slot of arbitrary markup, so it is
    rendered above the content rather than fed to the layout's $heading prop,
    which expects a plain string.

    See DESIGN-NOTES.md; the real shell is components/layouts/app.blade.php.
--}}
<x-layouts.app :title="$title ?? 'CollabSpace'">
    @isset($header)
        <div class="mb-6">{{ $header }}</div>
    @endisset

    {{ $slot }}
</x-layouts.app>
