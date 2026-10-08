<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Url;
use Weline\Theme\Observer\NormalizeVisitorUriLivePreviewPath;
use Weline\Theme\Service\ThemeLivePreviewPathMount;

final class NormalizeVisitorUriLivePreviewPathContractTest extends TestCase
{
    private const TOKEN = 'pv_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

    public function testObserverRegisteredOnNormalizeVisitorUriEvent(): void
    {
        $xml = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringContainsString('Weline_Framework_Url::normalize_visitor_uri', $xml);
        self::assertStringContainsString('NormalizeVisitorUriLivePreviewPath', $xml);
    }

    public function testObserverPeelsPreviewMountForSiteProbeRemainder(): void
    {
        $uri = '/~preview/' . self::TOKEN . '/~site/daocharms/about?keep=1';
        $data = new DataObject([
            'uri' => $uri,
            'routing_uri' => $uri,
            'origin_uri' => $uri,
        ]);
        $event = new Event(['data' => $data]);
        (new NormalizeVisitorUriLivePreviewPath())->execute($event);

        self::assertSame($uri, (string)$data->getData('origin_uri'));
        self::assertSame('/~site/daocharms/about?keep=1', (string)$data->getData('routing_uri'));
        self::assertSame(self::TOKEN, (string)$data->getData('live_preview_token'));
    }

    public function testSharedUrlHelperReturnsRoutingRemainderViaEvent(): void
    {
        $uri = '/~preview/' . self::TOKEN . '/~site/daocharms/';
        $normalized = Url::normalizeVisitorUri($uri);
        self::assertSame($uri, $normalized['origin_uri']);
        self::assertSame('/~site/daocharms', $normalized['routing_uri']);

        $absolute = Url::applyVisitorUriNormalizeToUrl(
            'https://p05113ef3.test.weline.com' . $uri
        );
        self::assertSame(
            'https://p05113ef3.test.weline.com/~site/daocharms',
            $absolute
        );
        self::assertNotNull(ThemeLivePreviewPathMount::parseFromUri($uri));
    }

    public function testRehydrateHelperPrefixesSiteWhenTokenHasCode(): void
    {
        self::assertSame(
            '/~site/grocery/CNY/terms',
            ThemeLivePreviewPathMount::rehydrateSiteMountIntoRouting('/CNY/terms', 'grocery')
        );
    }

    public function testExtractWebsiteCodeFromTokenPayload(): void
    {
        $code = \Weline\Theme\Service\PreviewTokenService::extractWebsiteCodeFromPayload([
            'context' => [
                'editor_context' => [
                    'scope' => [
                        'identity' => [
                            'website_id' => 544,
                            'website_code' => 'grocery',
                        ],
                    ],
                ],
            ],
        ]);
        self::assertSame('grocery', $code);

        $fromCanonical = \Weline\Theme\Service\PreviewTokenService::extractWebsiteCodeFromPayload([
            'canonical_scope' => 'daocharms.default.default',
            'context' => [],
        ]);
        self::assertSame('daocharms', $fromCanonical);
    }
}
