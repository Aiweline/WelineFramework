<?php
declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelErrorIncidentClassifier;

class PixelErrorIncidentClassifierTest extends TestCore
{
    public function testClassifiesStockAndCheckoutCodes(): void
    {
        $c = new PixelErrorIncidentClassifier();
        self::assertSame(
            PixelErrorIncidentClassifier::TYPE_STOCK,
            $c->classify(['error_code' => 'out_of_stock'])
        );
        self::assertSame(
            PixelErrorIncidentClassifier::TYPE_CHECKOUT,
            $c->classify(['error_code' => 'checkout_submit_v2_failed'])
        );
        self::assertSame(
            PixelErrorIncidentClassifier::TYPE_PAYMENT,
            $c->classify(['error_code' => 'checkout_payment_failed'])
        );
    }

    public function testClassifiesJsPromiseNetworkAndStale(): void
    {
        $c = new PixelErrorIncidentClassifier();
        self::assertSame(
            PixelErrorIncidentClassifier::TYPE_JS,
            $c->classify(['capture_source' => 'js'])
        );
        self::assertSame(
            PixelErrorIncidentClassifier::TYPE_PROMISE,
            $c->classify(['capture_source' => 'unhandledrejection'])
        );
        self::assertSame(
            PixelErrorIncidentClassifier::TYPE_NETWORK,
            $c->classify(['http_status' => 502])
        );
        self::assertSame(
            PixelErrorIncidentClassifier::TYPE_STALE,
            $c->classify([], true)
        );
    }

    public function testExplicitTypeWins(): void
    {
        $c = new PixelErrorIncidentClassifier();
        self::assertSame(
            PixelErrorIncidentClassifier::TYPE_AUTH,
            $c->classify(['error_type' => 'pixel_incident_auth', 'error_code' => 'out_of_stock'])
        );
    }

    public function testSeverityAndBadgeToneMapping(): void
    {
        $c = new PixelErrorIncidentClassifier();
        self::assertSame('info', $c->severityForType(PixelErrorIncidentClassifier::TYPE_STALE));
        self::assertSame('warning', $c->severityForType(PixelErrorIncidentClassifier::TYPE_STOCK));
        self::assertSame('urgent', $c->severityForType(PixelErrorIncidentClassifier::TYPE_PAYMENT));
        self::assertSame('error', $c->severityForType(PixelErrorIncidentClassifier::TYPE_JS));

        self::assertSame('info', $c->severityBadgeTone('info'));
        self::assertSame('warning', $c->severityBadgeTone('warning'));
        self::assertSame('danger', $c->severityBadgeTone('error'));
        self::assertSame('danger', $c->severityBadgeTone('urgent'));
        self::assertSame('紧急', $c->severityLabel('urgent'));
        self::assertSame('错误', $c->severityLabel('error'));
    }
}
