// End-to-end sign-in flows in a real browser (Chromium + virtual WebAuthn authenticator):
//   password sign-in -> authenticator app setup -> passkey registration
//   -> sign-in with passkey as second factor -> with a recovery code
//   -> with an authenticator code -> passwordless sign-in with the passkey
//   -> upload a file (tus) -> download it back, in full and by range.
// Also checks the security headers and fails on any Content-Security-Policy violation.
//
// Usage: BASE_URL=http://localhost:8080 EMAIL=... PASSWORD=... node tests/e2e/auth-flows.mjs
// The account must exist, have no second factor, and passkey sign-in must be
// enabled (passkey_login_enabled=true) for the last step. Exit code 1 on failure.
import { chromium } from 'playwright'
import crypto from 'node:crypto'

const BASE = process.env.BASE_URL || 'http://localhost:8080'
const EMAIL = process.env.EMAIL
const PASSWORD = process.env.PASSWORD
const SHOTS = process.env.SCREENSHOTS_DIR || ''

const base32Decode = (s) => {
  const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const c of s.toUpperCase()) bits += a.indexOf(c).toString(2).padStart(5, '0')
  const bytes = []
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2))
  return Buffer.from(bytes)
}
const totpAt = (secret, step) => {
  const buf = Buffer.alloc(8)
  buf.writeBigUInt64BE(BigInt(step))
  const h = crypto.createHmac('sha1', base32Decode(secret)).update(buf).digest()
  const o = h[h.length - 1] & 0xf
  return String((((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]) % 1e6).padStart(6, '0')
}
const currentStep = () => Math.floor(Date.now() / 30000)

const results = []
const check = (name, ok) => {
  results.push({ name, ok })
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}`)
  if (!ok && process.env.GITHUB_ACTIONS) console.log(`::error title=e2e failed::${name}`)
}

const browser = await chromium.launch()
const context = await browser.newContext({ viewport: { width: 1280, height: 950 } })
const page = await context.newPage()
const errors = []
page.on('pageerror', (e) => errors.push(e.message))
page.on('console', (m) => {
  if (m.type() === 'error' && /Content Security Policy|Refused to/i.test(m.text())) errors.push('CSP: ' + m.text())
})
page.on('dialog', (d) => d.accept(PASSWORD))

const cdp = await context.newCDPSession(page)
await cdp.send('WebAuthn.enable')
await cdp.send('WebAuthn.addVirtualAuthenticator', {
  options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true }
})

const shot = async (name) => SHOTS && page.screenshot({ path: `${SHOTS}/${name}.png` })
const signedIn = async () => (await page.locator('button.settings-button').count()) > 0
const signOut = async () => {
  await context.clearCookies()
  await page.goto('about:blank')
}
const passwordSignIn = async () => {
  await page.goto(BASE + '/')
  await page.waitForSelector('input[placeholder="Email"]')
  await page.fill('input[placeholder="Email"]', EMAIL)
  await page.fill('input[placeholder="Password"]', PASSWORD)
  await page.locator('button.block', { hasText: /login/i }).click()
  await page.waitForTimeout(2000)
}

try {
  // 0. security headers
  const home = await context.request.get(BASE + '/')
  const h = home.headers()
  check('security headers', home.ok()
    && /default-src 'self'/.test(h['content-security-policy'] || '')
    && /frame-ancestors 'self'/.test(h['content-security-policy'] || '')
    && h['referrer-policy'] === 'no-referrer'
    && h['x-content-type-options'] === 'nosniff'
    && !h['server'] && !h['x-powered-by'])
  check('tusd hooks are not reachable from outside',
    (await context.request.post(BASE + '/api/tusd-hooks', { data: { Type: 'post-receive' } })).status() === 404)

  // 1. password only
  await passwordSignIn()
  check('password sign-in without second factor', await signedIn())

  // 2. authenticator app
  await page.locator('button.settings-button').click({ force: true })
  await page.waitForTimeout(1000)
  await page.getByText('My Account', { exact: true }).first().click({ force: true })
  await page.waitForTimeout(1500)
  await page.getByText('Set up an authenticator app').click()
  await page.waitForTimeout(800)
  const secret = (await page.locator('#two_factor code.secret').innerText()).trim()
  const setupStep = currentStep()
  await page.fill('#totp_confirm_code', totpAt(secret, setupStep))
  await page.getByRole('button', { name: /activate/i }).click()
  await page.waitForTimeout(1500)
  const recovery = await page.locator('.recovery-codes code').allInnerTexts()
  check('authenticator app enabled, 10 recovery codes shown', recovery.length === 10)
  await page.getByRole('button', { name: /I have saved them/i }).click()

  // 3. passkey
  await page.fill('#passkey_name', 'CI key')
  await page.getByRole('button', { name: /add a passkey/i }).click()
  await page.waitForTimeout(2500)
  check('passkey registered', (await page.locator('#two_factor .passkeys li').count()) === 1)
  await shot('e2e-account-2fa')

  // 4. second factor: passkey
  await signOut()
  await passwordSignIn()
  check('second factor requested after password', (await page.getByText('Two-factor authentication').count()) > 0)
  await page.getByRole('button', { name: /use a passkey/i }).click()
  await page.waitForTimeout(2500)
  check('second factor: passkey', await signedIn())

  // 5. second factor: recovery code
  await signOut()
  await passwordSignIn()
  await page.getByText('Use a recovery code').click()
  await page.fill('#mfa_recovery', recovery[0])
  await page.getByRole('button', { name: /verify/i }).click()
  await page.waitForTimeout(2500)
  check('second factor: recovery code', await signedIn())

  // 6. second factor: authenticator code (a step not used yet)
  await signOut()
  await passwordSignIn()
  await page.fill('#mfa_code', totpAt(secret, Math.max(currentStep(), setupStep + 1)))
  await page.getByRole('button', { name: /verify/i }).click()
  await page.waitForTimeout(2500)
  check('second factor: authenticator code', await signedIn())

  // 7. passwordless
  await signOut()
  await page.goto(BASE + '/')
  await page.waitForTimeout(1500)
  await page.getByRole('button', { name: /sign in with a passkey/i }).click()
  await page.waitForTimeout(2500)
  check('passwordless sign-in with the passkey', await signedIn())

  // 8. upload a file through tus, then download it from the share link
  const content = crypto.randomBytes(3 * 1024 * 1024 + 123)
  const chooser = page.waitForEvent('filechooser')
  await page.locator('button.upload-files').click()
  await (await chooser).setFiles({ name: 'e2e-file.bin', mimeType: 'application/octet-stream', buffer: content })
  await page.locator('button.upload-button').click()
  await page.waitForSelector('.sharePanel.visible', { timeout: 60000 })
  const shareUrl = (await page.locator('.sharePanel-url').innerText()).trim()
  const longId = shareUrl.split('/shares/')[1]
  check('upload creates a share', !!longId)
  let full
  for (let i = 0; i < 30; i++) {
    full = await context.request.get(`${BASE}/api/shares/${longId}/download`)
    if (full.ok()) break
    await page.waitForTimeout(1000)
  }
  check('download returns the uploaded bytes', full.ok() && Buffer.compare(await full.body(), content) === 0)
  const part = await context.request.get(`${BASE}/api/shares/${longId}/download`, { headers: { Range: 'bytes=100-199' } })
  check('ranged download', part.status() === 206 && Buffer.compare(await part.body(), content.subarray(100, 200)) === 0)
} catch (e) {
  check('unexpected error: ' + e.message, false)
  await shot('e2e-failure')
}

check('no JavaScript errors', errors.length === 0)
if (errors.length) console.log(process.env.GITHUB_ACTIONS ? `::error title=Browser errors::${errors.join('%0A')}` : errors.join('\n'))
await browser.close()
process.exit(results.every((r) => r.ok) ? 0 : 1)
