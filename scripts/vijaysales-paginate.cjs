/**
 * Clicks through every Vijay Sales pagination li[jsname="page-li"][data-value="N"]
 * and collects all product URLs from data-product-url attributes.
 *
 * Usage: node vijaysales-paginate.cjs <url> [maxPages]
 * Output: JSON array of relative product URLs written to stdout.
 *
 * Cleanup errors (EBUSY on Windows) are swallowed so Node always exits cleanly.
 */

const puppeteer = require('puppeteer');

const url      = process.argv[2];
const maxPages = parseInt(process.argv[3] || '50', 10);

if (!url) {
    process.stderr.write('Usage: node vijaysales-paginate.cjs <url> [maxPages]\n');
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
                '--disable-background-timer-throttling',
                '--disable-backgrounding-occluded-windows',
                '--disable-renderer-backgrounding',
                '--blink-settings=imagesEnabled=false',
            ],
        });

        const page = await browser.newPage();
        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        // Use domcontentloaded — faster than networkidle; SSR products are in the DOM
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await delay(3000); // Allow JS to initialise product cards

        const allUrls = new Set();

        /** Collect data-product-url from every visible .product-card on the page */
        const collectUrls = () => page.evaluate(() => {
            const cards = document.querySelectorAll('.product-card[data-product-url]');
            return Array.from(cards).map(c => c.getAttribute('data-product-url')).filter(Boolean);
        });

        /** Total pages available in the pagination widget */
        const getTotalPages = () => page.evaluate(() =>
            document.querySelectorAll('li[jsname="page-li"]').length
        );

        const totalAvailable = await getTotalPages();
        const pagesToScrape  = Math.min(totalAvailable || 1, maxPages);

        process.stderr.write(`Found ${totalAvailable} pages, will scrape ${pagesToScrape}\n`);

        // Collect page 1 (already loaded)
        let prevFirst = null;
        const page1Urls = await collectUrls();
        page1Urls.forEach(u => allUrls.add(u));
        prevFirst = page1Urls[0] || null;

        process.stderr.write(`Page 1: ${page1Urls.length} products\n`);

        // Click pages 2..N (data-value is 0-indexed, so page 2 = data-value="1")
        for (let i = 1; i < pagesToScrape; i++) {
            const clicked = await page.evaluate((dataValue) => {
                const li = document.querySelector(`li[jsname="page-li"][data-value="${dataValue}"]`);
                if (!li) return false;
                li.scrollIntoView({ behavior: 'auto', block: 'center' });
                li.click();
                return true;
            }, i);

            if (!clicked) {
                process.stderr.write(`Could not click page ${i + 1} (data-value="${i}"), stopping\n`);
                break;
            }

            // Wait for the product list to update (up to 8 s)
            let waited = 0;
            let pageUrls = [];
            while (waited < 8) {
                await delay(1000);
                pageUrls = await collectUrls();
                if (pageUrls.length > 0 && pageUrls[0] !== prevFirst) break;
                waited++;
            }

            if (pageUrls.length === 0 || pageUrls[0] === prevFirst) {
                process.stderr.write(`Page ${i + 1} unchanged after ${waited}s, stopping\n`);
                break;
            }

            pageUrls.forEach(u => allUrls.add(u));
            prevFirst = pageUrls[0];
            process.stderr.write(`Page ${i + 1}: ${pageUrls.length} products (total so far: ${allUrls.size})\n`);
        }

        process.stdout.write(JSON.stringify([...allUrls]));

    } catch (err) {
        process.stderr.write('ERROR: ' + err.message + '\n');
        process.exit(1);
    } finally {
        if (browser) {
            try { await browser.close(); } catch (_) { /* swallow EBUSY on Windows */ }
        }
    }
})();
