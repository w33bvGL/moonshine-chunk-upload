import { defineConfig } from 'vite'

export default defineConfig({
  // The bundle is committed straight into public/, which is also where vite
  // would look for static assets to copy — there are none, so turn that off.
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
