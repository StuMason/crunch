import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                bunny('Mozilla Headline', {
                    weights: [400, 600, 700, 800],
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        // Wayfinder runs `php artisan wayfinder:generate` via Node's exec(), which caps the
        // child's stderr at 1MB. In a production build container, PHP 8.4 + Symfony 8 boot
        // emit a deprecation flood to stderr (>1MB) — local Herd suppresses it, the FrankenPHP
        // image does not — overflowing the buffer and failing `vite build` with
        // ERR_CHILD_PROCESS_STDIO_MAXBUFFER. The command writes its output to files, so its
        // stderr is just log noise: discard it. (Redirect kept at the end of the command.)
        wayfinder({
            command: 'php artisan wayfinder:generate --with-form 2>/dev/null',
        }),
    ],
});
