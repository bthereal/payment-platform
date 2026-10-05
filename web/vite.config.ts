import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [tailwindcss(), react()],
  server: {
    // Required to be reachable from the host/browser when run inside the
    // `web` container — Vite otherwise binds to 127.0.0.1 inside the container.
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
  },
})
