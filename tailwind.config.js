/** @type {import('tailwindcss').Config} */
// CollabSpace design tokens — extracted from the Claude Design screens.
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/Livewire/**/*.php',
        './app/Support/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Plus Jakarta Sans', 'Helvetica', 'system-ui', 'sans-serif'],
            },
            colors: {
                // Core neutrals
                ink: {
                    DEFAULT: '#111114',   // primary text, dark buttons, active nav
                    hover:   '#2a2a33',   // dark button hover
                    700:     '#3d3d47',   // form field labels
                    600:     '#4d4d57',
                    500:     '#5c5c66',
                    400:     '#75757f',   // secondary text
                    300:     '#8b8b95',   // muted text, icon strokes
                    200:     '#a3a3ad',
                },
                surface: {
                    DEFAULT: '#ffffff',   // CARD background — cards are white
                    soft:    '#f7f7fa',   // PAGE background, table header
                    muted:   '#f2f2f5',   // search field, icon buttons
                    // Darkened a second time, from #dfdfe9.
                    //
                    // The note this replaces said cards are defined by the
                    // white-on-soft contrast first and the border is only a
                    // secondary cue. Measured, that was wrong: white on the
                    // soft page is 1.07:1, which is nothing. The border was
                    // never the secondary cue — it was doing the whole job at
                    // 1.24:1, and a card the size of a dashboard tile simply
                    // read as floating text.
                    //
                    // 1.43:1 against the page now, 1.53:1 against a white card
                    // interior for row dividers and input edges. Still quiet,
                    // but the box has an outline you can find.
                    line:    '#d0d0df',   // card border, row divider
                    strong:  '#cbcbda',   // table header underline
                    hover:   '#e8e8ee',
                },
                // Brand accent — periwinkle
                periwinkle: {
                    DEFAULT: '#c3cff9',   // sidebar, hero panel, avatars
                    deep:    '#8fa4f0',
                    soft:    '#e7ebfc',
                    link:    '#4a5bd0',   // link hover
                },
                // Status
                danger: {
                    DEFAULT: '#e0453a',   // notification badge
                    text:    '#b7291f',   // overdue text
                    deep:    '#8f1f17',
                    bg:      '#fdeae8',   // overdue pill background
                    line:    '#f7cdc8',
                },
                // Amber: attention without alarm (a group short of members).
                warning: {
                    DEFAULT: '#d98a1c',
                    text:    '#7a4a00',
                    bg:      '#fdf0d5',
                },
                success: {
                    DEFAULT: '#1b6b45',
                    text:    '#155c3b',
                    bg:      '#e6f4ec',
                    // Matches danger.line in weight, so a success toast and a
                    // failure toast read as the same component in two tones
                    // rather than as two differently built things.
                    line:    '#c6e3d2',
                },

                // Per-sender chat colours. Eight hues so every member of a
                // group (max size 10) is visually distinct, assigned from the
                // user id so a person is the same colour everywhere, forever.
                //
                // 1, 6 and 7 are the existing periwinkle.link / danger.text /
                // success.DEFAULT values reused; the rest are new but kept to
                // the same muted, slightly-desaturated discipline as the rest
                // of the palette.
                //
                // Every `text` value clears WCAG AA (>= 4.5:1) against the
                // thread surface #f7f7fa, the bubble surface #ffffff, and its
                // own tint. Re-check with the script in MANUAL-TEST before
                // changing any of them.
                //
                // Lifted once: hue held fixed so a person stays recognisably
                // the same colour, saturation up about a tenth, lightness a
                // few points. Brightening a dark text colour spends contrast,
                // so each one was taken only as far as a 4.8:1 floor allowed —
                // a margin over the 4.5 promise, so a later tint tweak cannot
                // quietly break it.
                //
                // Clay is the exception and is untouched. It sits at 4.62:1 on
                // its own tint already, and orange-brown has nowhere to go: any
                // visible lift puts it under the line. Better one colour that
                // stays as it was than eight that are merely close to passing.
                member: {
                    1: '#4155de', '1bg': '#e7ebfc',   // indigo
                    2: '#097171', '2bg': '#d9eeee',   // teal
                    3: '#8e38ab', '3bg': '#f0e4f4',   // plum
                    4: '#a1521f', '4bg': '#f7e6db',   // clay
                    5: '#3c5b7a', '5bg': '#e6ebf0',   // slate
                    6: '#c91f13', '6bg': '#fdeae8',   // crimson
                    7: '#137345', '7bg': '#e6f4ec',   // forest
                    8: '#736a0c', '8bg': '#f2f0dc',   // olive
                },
            },
            borderRadius: {
                card:  '18px',   // inner cards, sidebar user block
                panel: '24px',   // hero panel, main content cards
                pill:  '999px',  // nav items, buttons, badges, inputs
            },
            fontSize: {
                micro:  ['10px',   { lineHeight: '1.3' }],
                tiny:   ['10.5px', { lineHeight: '1.3' }],
                label:  ['11.5px', { lineHeight: '1.35' }],
                meta:   ['12.5px', { lineHeight: '1.4' }],
                body:   ['13px',   { lineHeight: '1.5' }],
                nav:    ['13.5px', { lineHeight: '1.4' }],
                lead:   ['15.5px', { lineHeight: '1.35' }],
                h3:     ['21px',   { lineHeight: '1.2' }],
                h2:     ['26px',   { lineHeight: '1.15' }],
                h1:     ['30px',   { lineHeight: '1.1' }],
                stat:   ['42px',   { lineHeight: '1' }],
                hero:   ['52px',   { lineHeight: '1' }],
            },
            letterSpacing: {
                title: '-0.035em',
                stat:  '-0.04em',
                eyebrow: '0.09em',
            },
            boxShadow: {
                // Cards sit on the soft page background; this lifts them just
                // enough to read as raised without looking heavy.
                subtle: '0 1px 2px rgba(17,17,20,.04), 0 1px 3px rgba(17,17,20,.06)',
                card:   '0 8px 22px rgba(17,17,20,.1)',
                raised: '0 6px 16px rgba(17,17,20,.22)',
                button: '0 8px 20px rgba(17,17,20,.22)',
                modal:  '0 24px 60px rgba(17,17,20,.14)',
                // Hover states: one step stronger than the resting shadow.
                lift:   '0 4px 12px rgba(17,17,20,.12)',
                'button-hover': '0 10px 24px rgba(17,17,20,.28)',
            },
            // Motion. Every duration and curve in the app comes from here, so the
            // feel can be tuned in one place. 120-180ms, ease-out: quick enough
            // to read as a response rather than an animation.
            transitionDuration: {
                'motion-fast': '120ms',   // things leaving, press-down
                motion:        '150ms',   // hover, focus, entering
                'motion-slow': '180ms',   // overlays and toasts opening
            },
            transitionTimingFunction: {
                motion: 'cubic-bezier(0.22, 1, 0.36, 1)',   // ease-out
            },
            spacing: {
                sidebar: '222px',
            },
        },
    },
    plugins: [],
};
