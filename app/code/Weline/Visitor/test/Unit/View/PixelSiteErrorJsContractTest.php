<?php
declare(strict_types=1);

namespace Weline\Visitor\test\Unit\View;

use Weline\Framework\Test\TestCore;

class PixelSiteErrorJsContractTest extends TestCore
{
    public function testPixelJsExposesReportIncidentAndSiteErrorMonitors(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/pixel.js';
        self::assertFileExists($path);
        $js = (string)file_get_contents($path);
        self::assertStringContainsString('reportIncident:', $js);
        self::assertStringContainsString('__reportSiteIncident', $js);
        self::assertStringContainsString('__installSiteErrorMonitors', $js);
        self::assertStringContainsString("track('site_error'", $js);
        self::assertStringContainsString('themePublishedVersionId', $js);
        self::assertStringContainsString('deployVersion', $js);
        self::assertStringContainsString("window.addEventListener('weline:api:error'", $js);
        self::assertStringContainsString('__onWelineApiError', $js);
        self::assertStringContainsString("capture_source: 'api'", $js);
    }

    public function testTaglibPixelSynced(): void
    {
        $path = dirname(__DIR__, 3) . '/view/taglib/js/pixel.phtml';
        self::assertFileExists($path);
        $js = (string)file_get_contents($path);
        self::assertStringContainsString('reportIncident:', $js);
        self::assertStringContainsString('__installSiteErrorMonitors', $js);
        self::assertStringContainsString("window.addEventListener('weline:api:error'", $js);
        self::assertStringContainsString('__onWelineApiError', $js);
    }

    public function testFrontendWelineApiAlwaysNotifiesApiFailure(): void
    {
        $path = BP . '/app/code/Weline/Frontend/view/statics/js/weline-api.js';
        self::assertFileExists($path);
        $js = (string)file_get_contents($path);
        self::assertStringContainsString('notifyApiFailure(error, meta', $js);
        self::assertStringContainsString("window.dispatchEvent(new CustomEvent('weline:api:error'", $js);
        self::assertStringContainsString("provider === 'visitor' && /^trackPixel$/i.test(operation)", $js);
        self::assertStringContainsString('this.notifyApiFailure(error, {', $js);
        self::assertStringContainsString('handleHttpError(status, error, silent, requestOptions, requestPayload)', $js);
    }

    public function testEventDictionaryHasSiteErrorSkipGtm(): void
    {
        $path = dirname(__DIR__, 3) . '/etc/event_dictionary.json';
        $json = json_decode((string)file_get_contents($path), true);
        self::assertIsArray($json);
        $found = null;
        foreach (($json['events'] ?? []) as $event) {
            if (($event['weline_event'] ?? '') === 'site_error') {
                $found = $event;
                break;
            }
        }
        self::assertIsArray($found);
        self::assertTrue((bool)($found['skip_gtm_push'] ?? false));
        self::assertSame('error', (string)($found['event_family'] ?? ''));
    }
}
