import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        emptyOutDir: false,
        lib: {
            entry: 'resources/js/chunk-upload.js',
            name: 'MoonShineChunkUpload',
            formats: ['iife'],
            fileName: () => 'chunk-upload.js',
        },
        outDir: 'dist',
    },
});
