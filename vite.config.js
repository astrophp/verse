import { resolve } from 'node:path'
import { defineConfig } from 'vite'

const theme = resolve(import.meta.dirname, 'themes/default')

// Builds the bundled default theme's prebuilt assets.
// `npm run build` (or `npm run watch`) writes themes/default/dist/.
export default defineConfig({
  root: theme,
  // A relative base keeps CSS url() references working at any site base path.
  // Tags are emitted by @verseAssets, never by Vite.
  base: './',
  build: {
    outDir: resolve(theme, 'dist'),
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    assetsDir: '',
    rolldownOptions: {
      input: [resolve(theme, 'src/app.css'), resolve(theme, 'src/app.js')],
    },
  },
})
