<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\TrackingUrlResolver;

final class TrackingUrlResolverTest extends TestCase
{
    public function testRejectsExamplePlaceholderHosts(): void
    {
        $r = new TrackingUrlResolver();
        self::assertFalse($r->isUsableTrackingUrl('https://track.example.com/?n=ABC'));
        self::assertFalse($r->isUsableTemplate('https://track.example.com/?n={tracking_number}'));
        self::assertFalse($r->isUsableTrackingUrl('https://foo.example.test/t/1'));
    }

    public function testDefaultsTo17trackWhenCarrierMissingOrPlaceholder(): void
    {
        $r = new TrackingUrlResolver();
        $url = $r->resolve('TRACK-BACKFILL-92', 'https://track.example.com/?n=TRACK-BACKFILL-92', '');
        self::assertStringContainsString('17track.net', $url);
        self::assertStringContainsString('TRACK-BACKFILL-92', $url);
        self::assertStringNotContainsString('example.com', $url);

        $fromEmpty = $r->resolve('TN1', '', '');
        self::assertSame(
            'https://www.17track.net/zh-cn/track?nums=TN1',
            $fromEmpty
        );
    }

    public function testPrefersUsableCarrierUrl(): void
    {
        $r = new TrackingUrlResolver();
        $carrier = 'https://www.yw56.com.cn/track?num=YW123';
        self::assertSame($carrier, $r->resolve('YW123', $carrier, ''));
    }

    public function testSanitizeTemplateRewritesPlaceholder(): void
    {
        $r = new TrackingUrlResolver();
        self::assertSame(
            TrackingUrlResolver::DEFAULT_TEMPLATE,
            $r->sanitizeTemplate('https://track.example.com/?n={tracking_number}')
        );
    }
}
