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

## Moving between pages (wire:navigate)

Sidebar links use Livewire's `wire:navigate`, so going from page to page does not reload: the sidebar element is kept (`x-persist="sidebar"` on the `<aside>`), only the content column (`[data-page-content]`) is swapped, with one short fade-in (`.cs-page-in` in `app.css`; reduced motion gets none). The highlighted item follows the address through `wire:current`.

The window is **not** reloaded, so this is the rule for any new page:

- Page scripts must start from Alpine (`Alpine.data(...)` registered on `alpine:init`, started by `x-data`/`x-init`) or from `livewire:navigated`. Never from `DOMContentLoaded`, which fires once.
- Anything an Alpine component attaches to `window`, `document` or a timer must be removed in its `destroy()` (see `chat-switcher.js`, `login-intro.js`). Otherwise every visit stacks another listener.
- Do not `replaceState` or `pushState` over the page's own history entry: Livewire keeps its state there. Push your own entries with your own keys and ignore entries that have `state.alpine` (see `chat-switcher.js`).
- Inline `<script>` tags run again on every visit. Avoid them; if unavoidable add `data-navigate-once`.
- Links that download (CSV, PDF), log out, leave the site or open a new tab must **not** carry `wire:navigate`.
- Pages with their own active-item logic should read `data-current`, not compare URLs.
- **Page transitions animate opacity and a small `translateY` (6px at most) only: never horizontal movement, never blur, never width, height, margin or shadow. The scrollbar gutter must stay stable** (`html { scrollbar-gutter: stable }`, and the page is the only thing that scrolls), so the content's width cannot change mid-fade. Only `[data-page-content]` animates; the persisted sidebar is outside it and must stay untouched. There is one animation (enter); do not add a leave animation beside it.

## Group pickers

Every student page that lets you pick a group (My workspace, Task board, Documents) uses `<x-group-picker>`, never custom pills. It takes the groups, the current one, either a `route` (each card links to the page for that group) or a `select` expression (in place, with `{id}`), and an optional `secondary` array (group id => "4 members") for the quiet second line; with none, the line is left out. The colour comes from `SubjectPalette` (subject id mod 6), so a subject is the same colour on every page. Compute the second line with `StudentController::groupSecondary()` (one grouped query), never one query per card. The Group chat's left pane is a messenger list, not a picker, and stays as it is.

## Cards for short lists, tables for long ones

A short list of things a person acts on (My subjects, Groups, Marking, Monitor progress) is a grid of cards: `<x-subject-card>` for a subject and `<x-group-card>` for a group, both in the subject's own colour (`SubjectPalette`, subject id mod 6), with a footer slot for the actions and `cs-card-in` for the staggered entrance. Tables (`<x-data-table>`) are only for long data lists such as Students. A group card's order on Monitor progress and the dashboard comes from `App\Support\GroupNeed::sort()` (most overdue first, then the lowest progress). Compute the numbers in the controller (counts, `withCount`), never in the view.

## Long feeds

A feed that can grow (messages, activity, anything "recent") never stretches a page. Put it in an internal-scroll panel (`<x-panel>`, whose body scrolls inside the card), or, when two feeds belong together, in one card with two tabs (see Monitor progress, the single group page). Cap the query as well (the latest 30), so the page stays light however busy the group is.
