# Front end: `npm run dev` or `npm run build`

CollabSpace's styles and scripts are built with Vite and Tailwind. There are two ways to have them in the browser.

| | `npm run build` | `npm run dev` |
|---|---|---|
| What it does | Builds the CSS and JS once into `public/build`, then exits | Starts a server that rebuilds on every change and reloads the page |
| Memory | None after it finishes | A node process that stays running and grows with use |
| Use it when | You are not editing styles, scripts or Blade classes: testing, demos, marking, running the app | You are actively changing styles or Blade markup and want to see it at once |

**Default to `npm run build`.** Run it after pulling changes or after editing anything that adds a new Tailwind class, then use the app normally. Nothing keeps running.

Only use `npm run dev` while you are editing the front end, and stop it (Ctrl+C) when you are done. A long-running dev server is the only thing that holds memory here.

If `public/hot` exists, the app is looking for the dev server and will show no styles when it is not running. Delete that file, or just run `npm run build` and stop the dev server cleanly, which removes it.

## What the dev server watches

Set in `vite.config.js` and `resources/css/app.css`:

- **Watched, and reloads the page:** `resources/views`, `app/Livewire`, `app/View/Components`, `routes`.
- **Scanned for Tailwind class names:** `resources/views`, `resources/js`, `app/Livewire`, `app/View`, `app/Support`, and the framework's paginator views. Tailwind's automatic scan of the whole project is switched off (`source(none)`), so it does not read docs, tests, migrations or compiled views.
- **Ignored:** `storage` (compiled views, logs, cache, uploads), `vendor`, `docs`, `tests`, `database`, `scripts`, `bootstrap/cache`, `public/build`, `*.log`, `.git`.

If you add a class name in a new folder (for example a PHP class that returns Tailwind classes), add that folder as an `@source` line in `resources/css/app.css`, and to `content` in `tailwind.config.js`. Otherwise the class is missing from the CSS.

## Checking the dev server's memory

While `npm run dev` is running, in PowerShell:

```powershell
Get-CimInstance Win32_Process -Filter "name='node.exe'" |
  Where-Object CommandLine -like '*vite*' |
  Select-Object ProcessId, @{n='MB'; e={[int]($_.WorkingSetSize/1MB)}}
```

Run it right after starting, then again after ten minutes of normal use (browsing, editing a Blade file or two, chat open). A healthy server starts under 100 MB and stays under about 500 MB, and the number is roughly flat between the two readings. If it passes 1 GB, or keeps climbing each time you look, stop it and start it again, and tell whoever maintains the config.
