import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

// The production build is static: it is uploaded to public_html and served by
// Apache. Nothing here runs on Hostinger at runtime.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
    chunkSizeWarningLimit: 900,
    rollupOptions: {
      output: {
        // Split the heavy, rarely-changing libraries so a content edit does
        // not invalidate the whole bundle for returning users.
        manualChunks(id: string) {
          if (!id.includes('node_modules')) return undefined;
          if (/[\/]node_modules[\/](react|react-dom|react-router|react-router-dom)[\/]/.test(id)) return 'react';
          if (id.includes('@tiptap') || id.includes('prosemirror')) return 'editor';
          if (id.includes('recharts') || id.includes('d3-')) return 'charts';
          return undefined;
        },
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      // Local development talks to a PHP dev server on :8788.
      '/api': { target: 'http://localhost:8788', changeOrigin: true },
      '/t': { target: 'http://localhost:8788', changeOrigin: true },
      '/u': { target: 'http://localhost:8788', changeOrigin: true },
    },
  },
});
