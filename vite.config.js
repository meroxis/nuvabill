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
                'themes/nova/assets/theme.css',
            ],
            refresh: ['resources/views/**', 'themes/**/views/**', 'extensions/**/*.php'],
            fonts: [
                local('Figtree', {
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
