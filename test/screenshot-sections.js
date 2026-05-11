const puppeteer = require('puppeteer');

const url = process.argv[2] || 'https://pressgodigital.com/get-started-final/';
const outDir = process.argv[3] || '/tmp';

(async () => {
  const browser = await puppeteer.launch({ headless: 'new' });
  const page = await browser.newPage();
  await page.setViewport({ width: 375, height: 812, deviceScaleFactor: 2 });
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
  await new Promise(r => setTimeout(r, 1500));

  // Force-add e-lazyloaded class to all sections (bypasses IntersectionObserver)
  await page.evaluate(() => {
    document.querySelectorAll('.e-con.e-parent').forEach(el => el.classList.add('e-lazyloaded'));
  });
  await new Promise(r => setTimeout(r, 500));

  // Get section boundaries
  const sections = await page.evaluate(() => {
    const parents = document.querySelectorAll('.elementor > .elementor-element.e-parent');
    return Array.from(parents).map((el, i) => {
      const rect = el.getBoundingClientRect();
      return { index: i, top: rect.top + window.scrollY, height: rect.height, id: el.getAttribute('data-id') };
    });
  });

  // Full page
  await page.screenshot({ path: `${outDir}/mobile-full.png`, fullPage: true });
  console.log(`Saved mobile-full.png`);

  // Screenshot each section
  const names = ['hero', 'stats', 'features', 'testimonials', 'pricing', 'faq', 'cta', 'footer'];
  for (let i = 0; i < Math.min(sections.length, names.length); i++) {
    const s = sections[i];
    const pad = 10;
    await page.screenshot({
      path: `${outDir}/section-${i}-${names[i]}.png`,
      clip: { x: 0, y: Math.max(0, s.top - pad), width: 375, height: s.height + pad * 2 }
    });
    console.log(`Saved section-${i}-${names[i]}.png (${Math.round(s.height)}px)`);
  }

  // Desktop
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
  await new Promise(r => setTimeout(r, 1500));
  await page.evaluate(() => {
    document.querySelectorAll('.e-con.e-parent').forEach(el => el.classList.add('e-lazyloaded'));
  });
  await new Promise(r => setTimeout(r, 500));
  await page.screenshot({ path: `${outDir}/desktop-full.png`, fullPage: true });
  console.log(`Saved desktop-full.png`);

  await browser.close();
})();
