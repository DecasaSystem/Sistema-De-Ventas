import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// Cada compilación lleva su versión: va dentro del código (__VERSION_APP__) y en
// /version.json. La app abierta compara las dos y, si no son iguales, avisa que
// hay una versión nueva (components/common/AvisoVersionNueva.vue). Sin esto,
// quien tenía la app abierta desde antes de subir algo seguía con lo viejo sin
// saberlo (2026-10-29: el Excel de Listas le bajaba el de octubre).
const VERSION_APP = process.env.VERCEL_GIT_COMMIT_SHA?.slice(0, 12) || String(Date.now())

const versionJson = {
  name: 'version-app',
  apply: 'build',
  generateBundle() {
    this.emitFile({ type: 'asset', fileName: 'version.json', source: JSON.stringify({ version: VERSION_APP }) })
  },
}

export default defineConfig({
  plugins: [vue(), tailwindcss(), versionJson],
  define: {
    __VERSION_APP__: JSON.stringify(VERSION_APP),
  },
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://decasa-api.test',
        changeOrigin: true,
      },
      '/storage': {
        target: 'http://decasa-api.test',
        changeOrigin: true,
      },
    },
  },
})
