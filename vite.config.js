import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/*
 * The dev server watches the project root, which holds far more than the
 * front end: compiled views, logs, vendor code, docs, tests, migrations. None of
 * it can change what the browser shows, and every file under a watched folder is
 * tracked in memory for as long as the server runs. So the watcher is told what
 * to leave alone, and only resources, routes and the Livewire/Blade-rendering
 * code stay watched (laravel-vite-plugin's `refresh` reloads the page for those).
 *
 * Everything that is ignored here is also left out of Tailwind's source list in
 * resources/css/app.css, so nothing is scanned that is not watched, or the
 * other way round.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            // Not `true`: name exactly what a change should reload the page for.
            refresh: [
                'resources/views/**',
                'app/Livewire/**',
                'app/View/Components/**',
                'routes/**',
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: [
                '**/storage/**',            // compiled views, logs, cache, uploads
                '**/vendor/**',
                '**/node_modules/.cache/**',
                '**/node_modules/.vite/**',
                '**/bootstrap/cache/**',
                '**/database/**',           // migrations, seeders, the sqlite file
                '**/docs/**',
                '**/tests/**',
                '**/scripts/**',
                '**/public/build/**',       // what `npm run build` writes
                '**/public/hot',
                '**/*.log',
                '**/.git/**',
                '**/.phpunit.cache/**',
            ],
        },
    },
});
