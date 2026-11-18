import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/admin.css',
                'resources/js/app.js',
                // The console page only: noVNC stays out of app.js.
                'resources/js/console.js',
                // The text terminal console page only: xterm.js stays out of app.js too.
                'resources/js/terminal.js',
                'themes/nova/assets/theme.css',
            ],
            refresh: ['resources/views/**', 'themes/**/views/**', 'extensions/**/*.php'],
            fonts: [
                local('Figtree', {
                    // Only the body and bold weights are preloaded; 500 and 600 load when a page uses them.
                    preload: [{ weight: 400 }, { weight: 700 }],
                    variants: [400, 500, 600, 700].map((weight) => ({
                        src: `resources/fonts/figtree/figtree-latin-${weight}-normal.woff2`,
                        weight,
                    })),
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
