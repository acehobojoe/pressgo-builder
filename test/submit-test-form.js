const puppeteer = require('puppeteer');

(async () => {
  const url = process.argv[2] || 'https://bodystylefitness.com/get-started/';
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });

  // Capture console + network
  page.on('console', m => console.log('[console]', m.type(), m.text()));
  page.on('pageerror', e => console.log('[pageerror]', e.message));
  page.on('response', async r => {
    const u = r.url();
    if (u.includes('admin-ajax') || u.includes('/elementor') || u.includes('googleads') || u.includes('googletagmanager')) {
      console.log('[net]', r.status(), u.substring(0, 200));
    }
  });

  // Tag the URL with utms so the conversion captures attribution + traffic_type=PAID
  const fullUrl = url + (url.includes('?') ? '&' : '?') +
    'utm_source=google&utm_medium=cpc&utm_campaign=TEST-CONVERSION&gclid=test_gclid_' + Date.now();

  console.log('[goto]', fullUrl);
  await page.goto(fullUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  // Give late-loading scripts a moment (gtag, snippets)
  await new Promise(r => setTimeout(r, 4000));

  // Wait for form
  await page.waitForSelector('form[name="Lead Form"], form.elementor-form', { timeout: 15000 });
  console.log('[form-found]');

  // Fill the first form on the page (hero form)
  await page.evaluate(() => {
    const form = document.querySelector('form.elementor-form');
    if (!form) throw new Error('no form');
    const setVal = (sel, val) => {
      const el = form.querySelector(sel);
      if (el) {
        el.focus();
        el.value = val;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    };
    setVal('input[name="form_fields[name]"]', 'Joe Test Conversion');
    setVal('input[name="form_fields[phone]"]', '7273090000');
    setVal('input[name="form_fields[email]"]', 'joe@pressgodigital.com');
    // Acceptance checkbox
    const consent = form.querySelector('input[name="form_fields[sms_consent]"]');
    if (consent && !consent.checked) consent.click();
  });
  console.log('[form-filled]');

  // Hook submit_success listener
  await page.evaluate(() => {
    window.__submitOk = false;
    window.__submitErr = null;
    document.addEventListener('submit_success', () => { window.__submitOk = true; }, true);
    if (window.jQuery) {
      jQuery(document).on('submit_success', '.elementor-form', () => { window.__submitOk = true; });
      jQuery(document).on('submit_error',   '.elementor-form', (e, r) => { window.__submitErr = r || 'err'; });
    }
  });

  // Submit
  await page.evaluate(() => {
    const btn = document.querySelector('form.elementor-form button[type="submit"], form.elementor-form .elementor-button');
    if (btn) btn.click();
    else document.querySelector('form.elementor-form').submit();
  });
  console.log('[submitted]');

  // Wait up to 25s for submit_success or error
  const result = await page.waitForFunction(
    () => window.__submitOk === true || window.__submitErr !== null,
    { timeout: 25000 }
  ).then(() => page.evaluate(() => ({ ok: window.__submitOk, err: window.__submitErr })))
   .catch(e => ({ ok: false, err: 'timeout: ' + e.message }));
  console.log('[result]', JSON.stringify(result));

  // Look for success message in DOM
  const successText = await page.evaluate(() => {
    const el = document.querySelector('.elementor-message-success');
    return el ? el.innerText : null;
  });
  console.log('[success-message]', successText);

  // Capture a screenshot for verification
  await page.screenshot({ path: '/tmp/test-submit.png', fullPage: false });

  await browser.close();
  console.log('[done]');
})();
