import { defineConfig } from 'vite'

export default defineConfig({
  publicDir: false,
  build: {
    emptyOutDir: true,
    manifest: false,
    rollupOptions: {
      input: ['resources/js/chunk-upload.js'],
      output: {
        entryFileNames: 'chunk-upload.js',
      },
    },
    outDir: 'public',
  },
})
