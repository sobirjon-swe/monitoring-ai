import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
export default defineConfig({ plugins: [react()], base: '/ui-assets/', build: { outDir: '../../backend/public/ui-assets', emptyOutDir: true, manifest: true }, test: { environment: 'jsdom', setupFiles: './src/test/setup.ts', restoreMocks: true } });
