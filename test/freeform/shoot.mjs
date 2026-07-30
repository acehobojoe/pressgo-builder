// Viewport-TRUE screenshots + overflow measurement for freeform loop verification.
// Headless Chrome CLI enforces a ~500px minimum window width, which silently
// renders "mobile" shots at ~500px and crops them — every element then appears
// to clip at the right edge. This harness uses Puppeteer viewport emulation
// (real 375px) and reports document scrollWidth so horizontal overflow is
// measured, not guessed.
// Usage: node test/freeform/shoot.mjs <outdir> <slug> [slug...]
import puppeteer from 'puppeteer';
import fs from 'fs';

const [outdir, ...slugs] = process.argv.slice(2);
if (!outdir || !slugs.length) { console.error('usage: shoot.mjs <outdir> <slug>...'); process.exit(1); }
fs.mkdirSync(outdir, { recursive: true });
const SITE = 'https://wp.pressgo.app';
const VIEWPORTS = [{ name: '1440', width: 1440, height: 900 }, { name: '375', width: 375, height: 812 }];

const b = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
const p = await b.newPage();
for (const slug of slugs) {
  for (const v of VIEWPORTS) {
    await p.setViewport({ width: v.width, height: v.height, deviceScaleFactor: 1 });
    await p.goto(`${SITE}/${slug}/?shoot=${Date.now() % 100000}`, { waitUntil: 'networkidle0', timeout: 60000 });
    const m = await p.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      bodyHeight: Math.min(document.body.scrollHeight, 6000),
    }));
    await p.screenshot({ path: `${outdir}/${slug}-${v.name}.png`, fullPage: true, captureBeyondViewport: true });
    const overflow = m.scrollWidth > m.innerWidth ? ` OVERFLOW +${m.scrollWidth - m.innerWidth}px` : ' fits';
    console.log(`${slug} @${v.name}: scrollWidth=${m.scrollWidth}/${m.innerWidth}${overflow} height=${m.bodyHeight}`);
  }
}
await b.close();
