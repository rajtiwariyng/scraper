/**
 * Loads a Croma listing page, clicks "View More" up to maxClicks times,
 * then writes the full page HTML to stdout.
 *
 * Usage: node croma-view-more.js <url> [maxClicks]
 *
 * Cleanup errors (EBUSY on Windows) are swallowed so the process always
 * exits cleanly after the HTML has been written.
 */

const puppeteer = require('puppeteer');

const url      = process.argv[2];
const maxClicks = parseInt(process.argv[3] || '20', 10);

if (!url) {
    process.stderr.write('Usage: node croma-view-more.js <url> [maxClicks]\n');
    process.exit(1);
}

const delay = ms => new Promise(r => setTimeout(r, ms));

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-web-security',
                '--disable-background-timer-throttling',
                '--disable-backgrounding-occluded-windows',
                '--disable-renderer-backgrounding',
            ],
        });

        const page = await browser.newPage();

        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );
        await page.setExtraHTTPHeaders({
            'Accept-Language': 'en-US,en;q=0.9',
        });

        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });

        // Wait for initial product list to render
        await delay(4000);

        for (let i = 0; i < maxClicks; i++) {
            const countBefore = await page.evaluate(
                () => document.querySelectorAll('li.product-item').length
            );

            const clicked = await page.evaluate(() => {
                // Try data-testid / class selectors first
                const candidates = [
                    '[data-testid="view-more-button"]',
                    '.view-more-btn',
                    'button[class*="view-more"]',
                    'a[class*="view-more"]',
                    '.plp-view-more button',
                    '.load-more-btn',
                ];
                for (const sel of candidates) {
                    try {
                        const el = document.querySelector(sel);
                        if (el && el.offsetParent !== null) {
                            el.scrollIntoView({ behavior: 'auto', block: 'center' });
                            el.click();
                            return true;
                        }
                    } catch (_) {}
                }

                // Fallback: find by visible button/link text
                const all = Array.from(document.querySelectorAll('button, a'));
                const btn = all.find(el => {
                    if (el.offsetParent === null) return false;
                    const t = el.textContent.trim().toLowerCase();
                    return t === 'view more' || t === 'load more' || t === 'show more';
                });
                if (btn) {
                    btn.scrollIntoView({ behavior: 'auto', block: 'center' });
                    btn.click();
                    return true;
                }
                return false;
            });

            if (!clicked) break;

            // Wait up to 8 s for new products to appear
            for (let w = 0; w < 8; w++) {
                await delay(1000);
                const count = await page.evaluate(
                    () => document.querySelectorAll('li.product-item').length
                );
                if (count > countBefore) break;
            }
        }

        const html = await page.content();
        process.stdout.write(html);

    } catch (err) {
        process.stderr.write('ERROR: ' + err.message + '\n');
        process.exit(1);
    } finally {
        if (browser) {
            try { await browser.close(); } catch (_) { /* swallow EBUSY on Windows */ }
        }
    }
})();
