// @weline-e2e-runtime wls
// @weline-e2e-transport direct
const { test, expect, gotoFrontend } = require('../../../../../../../tests/e2e/framework');

test('multiple real product cards retain loaded images and purchase layout with external styles', async ({ page }, testInfo) => {
    test.setTimeout(120000);
    await page.setViewportSize({ width: 1440, height: 1000 });
    const response = await gotoFrontend(page, '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
    expect(response.status()).toBe(200);
    const cards = page.locator('.weline-product-card').filter({ has: page.locator('.wpc-cta a, .wpc-cta button') });
    await expect(cards.nth(2)).toBeAttached();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        for (let index = 0; index < 3; index++) {
            const card = cards.nth(index);
            await card.scrollIntoViewIfNeeded();
            const image = card.locator('img.wpc-image').first();
            await expect(image).toBeVisible();
            await expect.poll(() => image.evaluate(img => img.complete && img.naturalWidth > 0), { timeout: 30000 }).toBe(true);
            const mediaElement = card.locator('.wpc-media');
            const media = await mediaElement.boundingBox();
            expect(media.width).toBeGreaterThan(0);
            expect(media.height).toBeGreaterThan(0);
            // Featured shelves intentionally use 68% padding; the shared card's square
            // default is not a universal design contract. Check the applied box model.
            const mediaStyle = await mediaElement.evaluate(element => {
                const style = getComputedStyle(element);
                const pixels = property => parseFloat(style[property]) || 0;
                const verticalExtras = pixels('paddingTop') + pixels('paddingBottom')
                    + pixels('borderTopWidth') + pixels('borderBottomWidth');
                return { expectedHeight: pixels('height') + (style.boxSizing === 'border-box' ? 0 : verticalExtras),
                    paddingTop: pixels('paddingTop'),
                    featuredShelf: element.matches('.wc-theme_widget_featured_products .weline-product-card.density-shelf .wpc-media, .theme-layout-homepage .homepage-section--featured-lead .wc-theme_widget_featured_products .weline-product-card .wpc-media') };
            });
            expect(Math.abs(media.height - mediaStyle.expectedHeight)).toBeLessThanOrEqual(1);
            if (mediaStyle.featuredShelf) {
                expect(Math.abs(mediaStyle.paddingTop / media.width - 0.68)).toBeLessThan(0.02);
            }
            const purchase = card.locator('.wpc-cta');
            await expect(purchase).toBeVisible();
            const controls = purchase.locator('a, button');
            expect(await controls.count()).toBeGreaterThan(0);
            const cardBox = await card.boundingBox();
            const purchaseBox = await purchase.boundingBox();
            expect(purchaseBox.y).toBeGreaterThanOrEqual(media.y + media.height - 1);
            for (const control of await controls.all()) {
                if (!(await control.isVisible())) continue;
                const box = await control.boundingBox();
                expect(box.width).toBeGreaterThan(0);
                expect(box.height).toBeGreaterThan(0);
                expect(box.x).toBeGreaterThanOrEqual(cardBox.x - 1);
                expect(box.x + box.width).toBeLessThanOrEqual(cardBox.x + cardBox.width + 1);
                expect(box.y + box.height).toBeLessThanOrEqual(cardBox.y + cardBox.height + 1);
            }
        }
        await cards.first().scrollIntoViewIfNeeded();
        await page.screenshot({ path: testInfo.outputPath(`product-cards-${width}.png`), fullPage: false });
    }
    await expect(page.locator('body')).not.toContainText(/Fatal error|ParseError|WLS Runtime Error/i);
});
