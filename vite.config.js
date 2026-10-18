import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { quasar, transformAssetUrls } from '@quasar/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/app.ts'], refresh: true }),
        vue({ template: { transformAssetUrls } }),
        quasar({
            sassVariables: fileURLToPath(
                new URL(
                    './resources/css/quasar.variables.sass',
                    import.meta.url,
                ),
            ),
        }),
    ],
    server: {
        host: '127.0.0.1',
        cors: {
            origin: [
                /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/,
                /^https?:\/\/recruitflow\.test$/,
            ],
        },
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
