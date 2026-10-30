/*
 * Sign-in opening, the script half. The animation itself is CSS (see app.css); this only
 * remembers that the session has seen it, removes the preloader when it finishes, and puts
 * the cursor in the email field once the form is on screen.
 *
 * Every step is safe to skip: storage may be blocked, and if this never runs the CSS still
 * plays to the end and the page works.
 */
export default () => ({
    init() {
        const root = document.documentElement;
        const preloader = this.$el;

        const finish = () => {
            if (!preloader.isConnected) return;

            preloader.remove();

            // Only take the focus back if the visitor has not already gone elsewhere.
            const email = document.getElementById('email');
            const active = document.activeElement;

            if (email && (!active || active === document.body || active === email)) {
                email.focus({ preventScroll: true });
            }
        };

        // Not animating (reduced motion, or already seen): nothing to show.
        if (!root.classList.contains('js-intro')) {
            preloader.remove();
            return;
        }

        try {
            sessionStorage.setItem('csIntro', '1');
        } catch (e) {
            // Storage blocked: the intro simply plays again on the next visit.
        }

        preloader.addEventListener('animationend', (event) => {
            if (event.animationName === 'cs-preloader-out') finish();
        });

        // Belt and braces if the animation event never arrives.
        setTimeout(finish, 3000);
    },
});
/*
 * The living backdrop. It stays still until the opening has finished (or at once when there was
 * no opening), pauses while the tab is hidden, and drifts a few pixels with the pointer on a
 * desktop. If this never runs the backdrop is just the still blobs.
 */
export const loginBackdrop = () => ({
    init() {
        const bg = this.$el;
        const root = document.documentElement;
        const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (still) return;

        // After the preloader and the staggered entrance (about 4s); straight away if they were skipped.
        setTimeout(() => bg.classList.add('cs-bg--live'), root.classList.contains('js-intro') ? 4000 : 300);

        const sync = () => bg.classList.toggle('cs-bg--paused', document.hidden);
        document.addEventListener('visibilitychange', sync);
        sync();

        // Parallax: desktop with a real pointer only, at most 14px.
        if (!window.matchMedia('(hover: hover) and (min-width: 1024px)').matches) return;

        let frame = 0;

        window.addEventListener('mousemove', (event) => {
            if (frame || document.hidden) return;

            frame = requestAnimationFrame(() => {
                frame = 0;
                bg.style.setProperty('--px', (event.clientX / window.innerWidth - 0.5) * 2);
                bg.style.setProperty('--py', (event.clientY / window.innerHeight - 0.5) * 2);
            });
        }, { passive: true });
    },
});