# CollabSpace — Design Reference

Extracted from the five Claude Design screens. Use this when translating to Blade + Tailwind.

## Typeface

**Plus Jakarta Sans** — weights 400, 500, 600, 700, 800. Loaded in `resources/views/components/layouts/app.blade.php`.

## Layout shell

| Element | Value |
|---|---|
| Sidebar width | 222px, fixed, sticky, full height (`w-sidebar`) |
| Sidebar background | `#c3cff9` (periwinkle) |
| Sidebar corner | `border-radius: 0 26px 26px 0` |
| Sidebar padding | `22px 14px 18px` |
| Content background | `#f7f7fa` (`bg-surface-soft`) — see Surfaces below |
| Header padding | `24px 28px 18px` |
| Content padding | `0 28px` |

The sidebar is **light periwinkle**, not dark navy. The dark colour lives on the active nav item and primary buttons.

## Sidebar nav item

```
padding: 11px 14px · radius 999px · 13.5px · gap 12px · icon 18x18 stroke 1.7

inactive → rgba(17,17,20,.66), weight 600
hover    → bg rgba(255,255,255,.5), color #111114
active    → bg #111114, #fff, weight 700, shadow 0 6px 16px rgba(17,17,20,.22)
```

Notification badge: `min-width 20px, height 20px, radius 999px, bg #e0453a, 10.5px/700 white`.

## Buttons

| Type | Style |
|---|---|
| Primary | bg `#111114`, white text, radius 999px, hover `#2a2a33` |
| Secondary | bg `#f2f2f5`, ink text, radius 999px, hover `#e8e8ee` |
| Icon-only | 44×44, radius 999px, bg `#f2f2f5` |
| FAB (add task) | 52×52 circle, bg `#111114` |

## Status pills

```
Overdue      → bg #fdeae8, text #b7291f   (tone="danger")
Done/success → bg #e6f4ec, text #155c3b   (tone="success")
Neutral      → bg #f2f2f5, text #4d4d57   (tone="neutral")
Subject code → bg #e7ebfc, text #4a5bd0   (tone="info")
```

Font: 11.5px, weight 700, radius 999px.

## Progress bars

Height 9px, radius 999px. Track `rgba(255,255,255,.6)` on periwinkle panels (`on="periwinkle"`) or `#f2f2f5` on white. Fill: done `#111114`, in progress `rgba(17,17,20,.32)`.

## Typography scale

| Role | Size / weight | Utility |
|---|---|---|
| Page title (h1) | 30px / 800, tracking -0.035em | `text-h1 font-extrabold tracking-title` |
| Hero stat | 42px / 800, tracking -0.04em | `text-stat tracking-stat` |
| Section heading | 21px / 700 | `text-h3 font-bold` |
| Card title / nav | 13.5px / 600–700 | `text-nav` |
| Body | 13px / 400–500 | `text-body` |
| Meta | 12.5px / 500, `#75757f` | `text-meta text-ink-400` |
| Eyebrow label | 11.5px / 700, uppercase, tracking 0.09em | `text-label uppercase tracking-eyebrow` |

## Radii in use

`rounded-pill` (999px) for anything interactive. `rounded-panel` (24px) for main panels. `rounded-card` (18px) for inner cards. Nothing square.

---

## Components (built)

```
components/
  layouts/app.blade.php       — sidebar + header, role-aware nav
  nav-item.blade.php          — icon, label, route, badge
  stat-card.blade.php         — label, value, meta, tone (plain|periwinkle|dark), icon
  badge.blade.php             — tone (danger|success|neutral|info)
  button.blade.php            — variant (primary|secondary|icon), href, icon
  progress-bar.blade.php      — done, doing, total, on (white|periwinkle)
  avatar.blade.php            — name, size (sm|md|lg); derives initials
  empty-state.blade.php       — title, body, cta, href, icon
  data-table.blade.php        — headings[], empty; rows via slot, optional footer slot
  icon.blade.php              — name, size; inline Lucide-style SVG, stroke 1.7
```

The nav differs by role (admin / lecturer / student), the shell does not — `App\View\Composers\NavigationComposer` supplies `$nav` and `$role` to the layout, so the layout is never duplicated. `role` comes from `auth()->user()->role`, defaulting to `student`.

Nav entries point at named routes. `x-nav-item` still falls back to `#` for a missing route, but no entry relies on that any more — every screen exists, and `SidebarRoleTest` fails if a `href="#"` reappears in a rendered sidebar.

---

## Surfaces

The content area is the **soft** token and cards are **white**. That contrast is what
makes a card read as a card; the border and shadow are secondary cues. Before this,
white cards sat on a white page behind a border too light to see, and every screen
read as empty.

| Role | Token | Value |
|---|---|---|
| Page / content area | `surface.soft` | `#f7f7fa` |
| Card, table, form panel | `surface.DEFAULT` | `#ffffff` |
| Card border, row divider | `surface.line` | `#dfdfe9` (was `#ececf2`) |
| Table header underline | `surface.strong` | `#cbcbda` |
| Card elevation | `shadow-subtle` | two-layer, ~6% |

Rules that follow from it:

- A form field is **white with a border**, not a soft fill — a soft field is invisible
  on a soft page whenever the form is not inside a card.
- A grouping panel that stays soft (chat thread, activity lists) carries a border,
  since its tint no longer separates it from the page.
- Table cell padding lives in `resources/css/app.css` under `.data-table`, not on
  individual `<td>` elements. Row density is one decision; pages write bare cells.

Density: stat cards `p-4` (compact) / `p-5` (hero), table cells `11px 20px`,
table headers `10px 20px`.

The **type scale above is unchanged** — this was surface, spacing and borders only.

## Screens still to build from these patterns

Admin dashboard, manage user account, my subjects, groups, students, student dashboard, documents, document editor, group chat, notifications, user account, monitor progress. All reuse the components above — no new visual decisions needed.
