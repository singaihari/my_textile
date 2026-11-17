// Keyboard-only POS test (Scenario J) in a real Chromium browser.
// Prereq: a FRESH local server, e.g.
//   $env:DB_PATH='storage/tmp/e2e.sqlite'; php -S 127.0.0.1:8098 -t public public/router.php
// Run:  cd tests/E2E; npm install; $env:BASE='http://127.0.0.1:8098'; node pos_keyboard.test.mjs
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import { execSync } from 'node:child_process';

const BASE = process.env.BASE || 'http://127.0.0.1:8098';
const exe = [process.env.CHROME, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p));
let failed = 0;
const ok = (label, cond, extra = '') => { console.log((cond ? '  ok   ' : '  FAIL ') + label + (cond ? '' : '  ' + extra)); if (!cond) failed++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: exe, headless: true, args: ['--window-size=1366,768', '--no-first-run'] });
const page = await browser.newPage();
await page.setViewport({ width: 1366, height: 768 });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
page.on('dialog', (d) => d.accept());
page.on('response', (r) => { if (r.status() >= 400 && r.status() !== 422) errors.push(r.status() + ' ' + r.url()); });
const press = async (key, n = 1) => { for (let i = 0; i < n; i++) { await page.keyboard.press(key); await sleep(60); } };
const visible = (id) => page.$eval('#' + id, (el) => el.classList.contains('open')).catch(() => false);
const focusedId = () => page.evaluate(() => document.activeElement && document.activeElement.id);

try {
  // ---- first admin is created on the shop PC via CLI (there is no setup page)
  await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
  ok('fresh install shows login page', page.url().includes('/login'), page.url());
  execSync('php ../../scripts/create-admin.php --shop="E2E Textiles" --name="E2E Owner" --username=owner --code=C1 --demo',
    { env: { ...process.env, TPOS_ADMIN_PASSWORD: 'E2epass123' }, stdio: 'pipe', timeout: 180000 });
  await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
  await page.keyboard.type('owner'); await press('Tab'); await page.keyboard.type('E2epass123');   // keyboard only
  await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
  ok('admin signs in with keyboard and lands on dashboard', new URL(page.url()).pathname === '/', page.url());

  // ---- POS
  await page.goto(BASE + '/pos', { waitUntil: 'networkidle0' });
  await sleep(500);
  if (await visible('mCash')) { await page.keyboard.type('500'); await press('Enter'); await sleep(400); }
  ok('scan box focused on load', (await focusedId()) === 'scan', await focusedId());
  const items = await page.evaluate(async () => {
    const get = async (q) => (await TPOS.api('GET', '/api/pos/search?q=' + q)).items;
    const shirts = (await get('shirt')).filter((x) => +x.stock_milli >= 3000);
    const fabric = (await get('fabric')).filter((x) => x.unit === 'm' && +x.stock_milli >= 5000);
    return { a: shirts[0], b: shirts[1], m: fabric[0] };
  });
  // scan A twice (USB scanner = fast typing + Enter), scan B, scan metre fabric
  await page.keyboard.type(items.a.barcode, { delay: 5 }); await press('Enter'); await sleep(350);
  await page.keyboard.type(items.a.barcode, { delay: 5 }); await press('Enter'); await sleep(350);
  await page.keyboard.type(items.b.barcode, { delay: 5 }); await press('Enter'); await sleep(350);
  await page.keyboard.type(items.m.barcode, { delay: 5 }); await press('Enter'); await sleep(400);
  ok('metre item asks for length', await visible('mLine'));
  await page.keyboard.type('2.75'); await press('Enter'); await sleep(500);
  let st = await page.evaluate(() => window.__pos.state());
  ok('cart has 3 lines; duplicate scan merged to qty 2', st.lines.length === 3 && st.lines[0].qty_milli === 2000, JSON.stringify(st.lines.map((l) => l.qty_milli)));
  ok('metre qty 2.750 m stored as 2750 milli', st.lines[2].qty_milli === 2750);
  ok('focus returns to scan box', (await focusedId()) === 'scan', await focusedId());

  // arrow to line 2 and use + / Del with confirm, then Ctrl+Z undo
  await press('ArrowUp'); await press('ArrowUp');                // from scan → last line → line 2
  await press('+'); await sleep(300);
  st = await page.evaluate(() => window.__pos.state());
  ok('+ on selected line increases qty', st.lines[1].qty_milli === 2000, JSON.stringify(st.lines.map((l) => l.qty_milli)));
  await press('-'); await sleep(300);
  await press('Delete'); await sleep(200); await press('Enter'); await sleep(300);   // confirm dialog: Enter = Remove
  st = await page.evaluate(() => window.__pos.state());
  ok('Del + confirm removes line', st.lines.length === 2);
  await page.keyboard.down('Control'); await press('z'); await page.keyboard.up('Control'); await sleep(300);
  st = await page.evaluate(() => window.__pos.state());
  ok('Ctrl+Z restores removed line', st.lines.length === 3);

  // Esc never clears the bill
  await press('F3'); await press('Escape'); await press('Escape'); await sleep(200);
  st = await page.evaluate(() => window.__pos.state());
  ok('Esc does not discard the bill', st.lines.length === 3);

  // F4 customer modal open/close, F5 discount 5%
  await press('F4'); await sleep(200);
  ok('F4 opens customer dialog', await visible('mCustomer'));
  await press('Escape'); await sleep(200);
  await press('F5'); await sleep(200);
  ok('F5 opens discount dialog (browser refresh suppressed)', await visible('mDiscount'));
  await page.keyboard.type('5'); await press('Enter'); await sleep(700);
  const q = await page.evaluate(() => window.__pos.quote());
  ok('5% bill discount applied by server quote', q && q.bill_discount_paise > 0, JSON.stringify(q && q.discount_bp));

  // F7 hold, F8 resume
  await press('F7'); await sleep(200); await page.keyboard.type('trial room'); await press('Enter'); await sleep(600);
  st = await page.evaluate(() => window.__pos.state());
  ok('F7 holds and clears the screen', st.lines.length === 0);
  await press('F8'); await sleep(500); await press('Enter'); await sleep(900);
  st = await page.evaluate(() => window.__pos.state());
  ok('F8 + Enter resumes held bill', st.lines.length === 3, String(st.lines.length));

  // F6 payment (cash exact prefilled) → F10 save
  await sleep(400);
  await press('F6'); await sleep(700);
  ok('F6 opens payment with amount focused', (await visible('mPay')) && (await page.evaluate(() => document.activeElement.dataset.amt === '0')));
  const total = (await page.evaluate(() => window.__pos.quote())).total_paise;
  await page.keyboard.type(String(Math.ceil(total / 100000) * 1000)); await sleep(150);   // customer gives round notes
  await press('F10'); await sleep(1500);
  ok('F10 saves: success dialog shows invoice no', await visible('mSaved'), errors.join(' | '));
  const inv = await page.$eval('#savedNo', (e) => e.textContent);
  ok('invoice number format', /^TX-C1-\d{4}-\d{5}$/.test(inv), inv);
  await press('Enter'); await sleep(300);
  ok('Enter starts next bill, focus on scanner', (await focusedId()) === 'scan' && !(await visible('mSaved')));
  const status = await page.evaluate(() => TPOS.api('GET', '/api/pos/status'));
  ok('bill is pending sync (not uploaded automatically)', status.pending_sync > 0);

  // Refresh mid-bill keeps a draft (no silent discard)
  await page.keyboard.type(items.b.barcode, { delay: 5 }); await press('Enter'); await sleep(500);
  await page.reload({ waitUntil: 'networkidle0' }); await sleep(500);
  st = await page.evaluate(() => window.__pos.state());
  ok('unsaved bill restored after page reload', st.lines.length === 1);

  // Unknown barcode
  await page.keyboard.type('ZZZ-NOT-REAL', { delay: 5 }); await press('Enter'); await sleep(900);
  st = await page.evaluate(() => window.__pos.state());
  ok('unknown barcode adds nothing', st.lines.length === 1);

  // Image-card grid: search → ↓ into cards → Enter (quick-pick for size/colour) → Enter adds
  await page.click('#scan', { clickCount: 3 }); await press('Backspace'); await sleep(900);   // grid reloads after the search debounce
  const before = (await page.evaluate(() => window.__pos.state())).lines.length;
  ok('product cards rendered', (await page.evaluate(() => window.__pos.grid().length)) > 0);
  await page.keyboard.type('shirt'); await sleep(900);
  await press('ArrowDown'); await sleep(200);
  ok('↓ from search focuses a product card', await page.evaluate(() => document.activeElement.classList.contains('pcard')));
  await press('ArrowRight'); await press('Enter'); await sleep(800);
  if (await visible('mPick')) { await press('Enter'); await sleep(800); }
  st = await page.evaluate(() => window.__pos.state());
  ok('Enter on a card adds the chosen variant', st.lines.length === before + 1 || st.lines.some((l) => l.qty_milli > 1000), String(st.lines.length));
  await page.click('#scan', { clickCount: 3 }); await press('Escape'); await sleep(500);
  await press('PageDown'); await sleep(700);
  ok('PgDn switches category chip', await page.evaluate(() => !document.querySelector('.chip.active').matches(':first-child')));
  await page.click('#cancelBill'); await sleep(300); await press('Enter'); await sleep(400);
  ok('Cancel bill (with confirmation) empties the cart', (await page.evaluate(() => window.__pos.state())).lines.length === 0);
  await page.keyboard.down('Control'); await press('h'); await page.keyboard.up('Control'); await sleep(600);
  ok('Ctrl+H opens bill history', await visible('mHistory')); await press('Escape');

  // Invoice page: reprint does not create a sale
  await page.screenshot({ path: 'pos-1366x768.png' });
  await page.setViewport({ width: 1920, height: 1080 }); await sleep(200);
  await page.screenshot({ path: 'pos-1920x1080.png' });
  const layout = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth > window.innerWidth, pay: !!document.getElementById('payBtn').getBoundingClientRect().height }));
  ok('POS fits 1920×1080 without horizontal scroll', !layout.overflow && layout.pay);
  ok('no JavaScript errors', errors.length === 0, errors.join(' | '));
} catch (e) {
  console.error(e); failed++;
} finally {
  await browser.close();
}
console.log(failed ? `\n${failed} browser checks FAILED` : '\nAll browser keyboard checks passed');
process.exit(failed ? 1 : 0);
