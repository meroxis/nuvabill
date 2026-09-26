import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/admin.css',
                'resources/js/app.js',
                'themes/nova/assets/theme.css',
            ],
            refresh: ['resources/views/**', 'themes/**/views/**', 'extensions/**/*.php'],
            fonts: [
                bunny('Figtree', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('Bricolage Grotesque', {
                    weights: [600, 700, 800],
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
