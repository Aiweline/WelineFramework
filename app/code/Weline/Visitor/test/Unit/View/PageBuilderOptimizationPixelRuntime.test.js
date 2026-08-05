import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const pixelSourcePath = resolve(process.cwd(), 'app/code/Weline/Visitor/view/statics/js/pixel.js');
const pixelSource = readFileSync(pixelSourcePath, 'utf8');
const fingerprint = 'a'.repeat(64);
const nativeHistory = {
    pushState: window.history.pushState,
    replaceState: window.history.replaceState,
};

class FakeIntersectionObserver {
    static instances = [];

    constructor(callback) {
        this.callback = callback;
        this.observed = [];
        this.disconnected = false;
        FakeIntersectionObserver.instances.push(this);
    }

    observe(element) {
        this.observed.push(element);
    }

    unobserve(element) {
        this.observed = this.observed.filter((item) => item !== element);
    }

    disconnect() {
        this.disconnected = true;
    }

    trigger(element) {
        this.callback([{target: element, isIntersecting: true, intersectionRatio: 1}], this);
    }
}

describe('PageBuilder optimization Pixel runtime', () => {
    let trackPixel;
    let consoleLog;

    function boot({analytics = 'denied', preview = false} = {}) {
        vi.useFakeTimers();
        FakeIntersectionObserver.instances = [];
        trackPixel = vi.fn().mockResolvedValue({ok: true});
        window.history.pushState = nativeHistory.pushState;
        window.history.replaceState = nativeHistory.replaceState;
        document.body.innerHTML = [
            '<section',
            ' data-pb-attribution-version="pagebuilder_ai_v1"',
            ' data-pb-website-id="0"',
            ' data-pb-page-type="home_page"',
            ' data-pb-block-key="featured_offers"',
            ' data-pb-plan-revision="7"',
            ' data-pb-content-fingerprint="' + fingerprint + '"',
            ' data-pb-page-fingerprint="' + 'b'.repeat(64) + '"',
            ' data-pb-experiment-id="seo_experiment_01"',
            ' data-pb-variant="candidate"',
            ' data-pb-page-experiment-id="seo_page_01"',
            ' data-pb-page-variant="candidate"',
            ' data-pb-canonical-path="/chess-club">',
            '<button type="button" class="weline-pixel::view_item">查看套餐</button>',
            '</section>',
        ].join('');
        window.history.replaceState({}, '', '/chess-club');
        window.__WelinePixelLoaded = false;
        window.__PAGEBUILDER_PREVIEW__ = preview;
        window.__WelineVisitorTrackingConfig = {
            pixel: {enabled: true},
            consent: {enabled: true},
            ga4: {enabled: false},
            gtm: {enabled: false},
            forwarders: {},
        };
        window.__WelineConsentState = {analytics};
        window.__WelinePixelEnv = {
            website_id: '0',
            website_url: 'https://default.weline.test',
            language: 'en_US',
            currency: 'CNY',
        };
        window.Weline = {
            Api: {
                resource: vi.fn(() => ({trackPixel})),
            },
        };
        window.IntersectionObserver = FakeIntersectionObserver;
        globalThis.IntersectionObserver = FakeIntersectionObserver;
        window.eval(pixelSource);
        document.dispatchEvent(new window.Event('DOMContentLoaded'));
    }

    async function drain() {
        await vi.advanceTimersByTimeAsync(1500);
        await Promise.resolve();
        await Promise.resolve();
    }

    beforeAll(() => {
        document.title = 'Chess Club';
        consoleLog = vi.spyOn(console, 'log').mockImplementation(() => {});
        boot();
    });

    afterEach(() => {
        trackPixel.mockClear();
    });

    afterAll(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
        window.history.pushState = nativeHistory.pushState;
        window.history.replaceState = nativeHistory.replaceState;
        consoleLog.mockRestore();
        delete window.__WelinePixelLoaded;
        delete window.__WelinePixelBehaviorTelemetryLoaded;
        delete window.__WelinePageBuilderOptimizationImpressionsLoaded;
        delete window.__PAGEBUILDER_PREVIEW__;
        delete window.__WelineVisitorTrackingConfig;
        delete window.__WelineConsentState;
        delete window.__WelinePixelEnv;
        delete window.Weline;
        delete window.WelinePixel;
        delete window.WelinePixelSandbox;
        delete window.WelineVisitorForwarders;
        delete window.IntersectionObserver;
        delete globalThis.IntersectionObserver;
        document.body.innerHTML = '';
    });

    it('keeps denied-consent and preview events out of the worker transport', async () => {
        const button = document.querySelector('button');
        await drain();
        expect(trackPixel).not.toHaveBeenCalled();

        window.WelinePixel.track('view_item', {}, {element: button});
        await drain();
        expect(trackPixel).not.toHaveBeenCalled();

        window.__WelineConsentState = {analytics: 'granted'};
        window.__PAGEBUILDER_PREVIEW__ = true;
        window.WelinePixel.track('view_item', {}, {element: button});
        await drain();
        expect(trackPixel).not.toHaveBeenCalled();
    });

    it('sends a validated rendered PageBuilder attribution envelope for a published interaction', async () => {
        window.__WelineConsentState = {analytics: 'granted'};
        window.__PAGEBUILDER_PREVIEW__ = false;
        const button = document.querySelector('button');
        window.WelinePixel.track('view_item', {}, {element: button});
        await drain();

        expect(trackPixel).toHaveBeenCalledTimes(1);
        const payload = trackPixel.mock.calls[0][0].payload;
        expect(payload.eventName).toBe('view_item');
        expect(payload.additionalInfo.pagebuilder_attribution).toEqual(expect.objectContaining({
            attribution_version: 'pagebuilder_ai_v1',
            source: 'pagebuilder_rendered_dom',
            surface: 'published',
            analytics_consent: 'granted',
            website_id: '0',
            page_type: 'home_page',
            block_key: 'featured_offers',
            plan_revision: 7,
            content_fingerprint: fingerprint,
            canonical_path: '/chess-club',
        }));
    });

    it('records a visible PageBuilder block once as ai_block_impression', async () => {
        window.__WelineConsentState = {analytics: 'granted'};
        window.__PAGEBUILDER_PREVIEW__ = false;
        const block = document.querySelector('section');
        expect(FakeIntersectionObserver.instances).toHaveLength(1);

        FakeIntersectionObserver.instances[0].trigger(block);
        await drain();
        FakeIntersectionObserver.instances[0].trigger(block);
        await drain();

        expect(trackPixel).toHaveBeenCalledTimes(1);
        const payload = trackPixel.mock.calls[0][0].payload;
        expect(payload.eventName).toBe('ai_block_impression');
        expect(payload.additionalInfo.pagebuilder_attribution.block_key).toBe('featured_offers');
    });
});
