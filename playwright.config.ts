import { defineConfig, devices } from '@playwright/test';

/**
 * Solo para jugar en local: requiere `composer run dev` corriendo (servidor,
 * cola y Vite) y Mailpit levantado en 127.0.0.1:8025. No corre en CI.
 */
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 120_000,
    fullyParallel: false,
    retries: 0,
    workers: 1,
    reporter: 'list',
    use: {
        baseURL: process.env.APP_URL ?? 'http://localhost:8000',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
