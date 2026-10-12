import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.ts'],
            refresh: true,
            // Fonts are downloaded at build time and self-hosted from /build (no runtime third-party requests).
            fonts: [
                bunny('Public Sans', { weights: [400, 500, 600, 700] }),
                bunny('Barlow Semi Condensed', { weights: [500, 600, 700] }),
            ],
        }),
        vue({
            template: { transformAssetUrls: { base: null, includeAbsolute: false } },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    build: {
        chunkSizeWarningLimit: 700,
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
