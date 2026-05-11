const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch({ headless: 'new' });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
  await page.goto('https://pressgodigital.com/get-started/?nocache=' + Date.now(), { waitUntil: 'networkidle2', timeout: 30000 });
  await new Promise(r => setTimeout(r, 3000));

  // Check if our script was mangled by WP Rocket
  const scriptInfo = await page.evaluate(() => {
    const scripts = document.querySelectorAll('script');
    for (const s of scripts) {
      if (s.textContent.includes('pg-cal')) {
        return {
          type: s.type || 'normal',
          hasNoOptimize: s.hasAttribute('data-no-optimize'),
          snippet: s.textContent.substring(0, 100)
        };
      }
    }
    return 'script not found';
  });
  console.log('Script status:', JSON.stringify(scriptInfo, null, 2));

  // Check if pgCalOpen is defined
  const fnDefined = await page.evaluate(() => typeof pgCalOpen === 'function');
  console.log('pgCalOpen defined:', fnDefined);

  // Check overlay has no rocket attrs
  const overlayInfo = await page.evaluate(() => {
    const ov = document.getElementById('pg-cal-overlay');
    if (!ov) return 'overlay not found';
    return {
      hasRocketOnclick: ov.hasAttribute('data-rocket-onclick'),
      hasInlineOnclick: ov.hasAttribute('onclick')
    };
  });
  console.log('Overlay:', JSON.stringify(overlayInfo));

  // Click CTA
  await page.click('a[href*="#schedule"]');
  await new Promise(r => setTimeout(r, 4000));

  const modalState = await page.evaluate(() => {
    const ov = document.getElementById('pg-cal-overlay');
    const fr = document.getElementById('pg-cal-frame');
    return {
      display: ov ? ov.style.display : null,
      opacity: ov ? ov.style.opacity : null,
      iframeSrc: fr ? fr.src : null
    };
  });
  console.log('Modal after click:', JSON.stringify(modalState));
  await page.screenshot({ path: '/tmp/logo-check/modal-v3-desktop.png' });
  console.log('Desktop screenshot saved');

  // Close via ESC
  await page.keyboard.press('Escape');
  await new Promise(r => setTimeout(r, 500));
  const closed = await page.evaluate(() => document.getElementById('pg-cal-overlay').style.display);
  console.log('After ESC:', closed);

  // Mobile
  await page.setViewport({ width: 375, height: 812, deviceScaleFactor: 2 });
  await page.goto('https://pressgodigital.com/get-started/?nocache=' + Date.now(), { waitUntil: 'networkidle2', timeout: 30000 });
  await new Promise(r => setTimeout(r, 3000));
  await page.click('a[href*="#schedule"]');
  await new Promise(r => setTimeout(r, 4000));
  await page.screenshot({ path: '/tmp/logo-check/modal-v3-mobile.png' });
  console.log('Mobile screenshot saved');

  await browser.close();
})();
