<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>That file is too large — CollabSpace</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-surface-soft px-6 text-ink">
    <main class="flex w-full max-w-md flex-col gap-4 rounded-panel border border-surface-line bg-surface p-8 shadow-subtle">
        <h1 class="text-h2 font-extrabold tracking-title">That file is too large</h1>
        <p class="text-body leading-[1.55] text-ink-400">
            The upload was bigger than this server accepts, so nothing was saved. Choose a smaller file (up to 10 MB), or ask
            the administrator to raise <span class="font-semibold text-ink-600">upload_max_filesize</span> and
            <span class="font-semibold text-ink-600">post_max_size</span> in php.ini.
        </p>
        <div>
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="interactive inline-flex rounded-pill bg-ink px-5 py-2.5 text-nav font-bold text-white">Go back</a>
        </div>
    </main>
</body>
</html>
