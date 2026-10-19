import { chromium } from '@playwright/test'
import { mkdir } from 'node:fs/promises'
import { spawn } from 'node:child_process'

await mkdir('test-results/previews', { recursive: true })
const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })
await page.route('**/api/spa/user', route => route.fulfill({ status: 401, contentType: 'application/json', body: '{"message":"Unauthenticated."}' }))
const origin = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:3100'
const server = process.env.PLAYWRIGHT_BASE_URL ? null : spawn('node', ['.output/server/index.mjs'], { env: { ...process.env, PORT: '3100', HOST: '127.0.0.1' }, stdio: 'ignore' })
for (let attempt = 0; attempt < 50; attempt++) {
  try { await fetch(origin); break } catch { await new Promise(resolve => setTimeout(resolve, 100)) }
}
await page.goto(origin)
await page.getByRole('heading', { name: /Make room/ }).waitFor()
await page.getByLabel('Colour theme').selectOption('light')
await page.screenshot({ path: 'test-results/previews/homepage-desktop-light.png', fullPage: true })
await page.getByLabel('Colour theme').selectOption('dark')
await page.screenshot({ path: 'test-results/previews/homepage-desktop-dark.png', fullPage: true })
await page.setViewportSize({ width: 393, height: 852 })
await page.getByLabel('Colour theme').selectOption('light')
await page.screenshot({ path: 'test-results/previews/homepage-mobile-light.png', fullPage: true })
await page.getByLabel('Colour theme').selectOption('dark')
await page.screenshot({ path: 'test-results/previews/homepage-mobile-dark.png', fullPage: true })
await page.setViewportSize({ width: 1440, height: 1000 })
await page.getByLabel('Colour theme').selectOption('light')
await page.goto(`${origin}/brand`)
await page.getByRole('heading', { name: /Three personalities/ }).waitFor()
await page.screenshot({ path: 'test-results/previews/brand-gallery.png', fullPage: true })
await page.goto(`${origin}/login`)
await page.getByRole('heading', { name: 'Good to see you.' }).waitFor()
await page.screenshot({ path: 'test-results/previews/login-desktop.png', fullPage: true })
await browser.close()
server?.kill()
