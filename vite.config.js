import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],

    build: {
        rollupOptions: {
            output: {
                entryFileNames: 'assets/RDev-[name]-[hash].js',
                chunkFileNames: 'assets/RDev-[name]-[hash].js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name.endsWith('.css')) {
                        return 'assets/RDev-[name]-[hash][extname]';
                    }
                    return 'assets/RDev-[name]-[hash][extname]';
                },
            },
        },
    },

    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});