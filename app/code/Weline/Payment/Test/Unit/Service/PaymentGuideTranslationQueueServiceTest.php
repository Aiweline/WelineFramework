<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentGuideTranslationQueueService;

final class PaymentGuideTranslationQueueServiceTest extends TestCase
{
    public function testBuildBizKeyIncludesLocaleAndOptionalMethod(): void
    {
        $service = new PaymentGuideTranslationQueueService(
            $this->createMock(\Weline\Payment\Service\PaymentGuideI18nCatalog::class),
            $this->createMock(\Weline\I18n\Service\AiTranslationConfig::class),
        );

        self::assertSame(
            'payment_guide.ai_translation:en_US',
            $service->buildBizKey('en_US'),
        );
        self::assertSame(
            'payment_guide.ai_translation:en_US:paypal',
            $service->buildBizKey('en_US', 'paypal'),
        );
    }

    public function testServiceDefinesPaymentGuideDomain(): void
    {
        self::assertSame('payment_guide', PaymentGuideTranslationQueueService::DOMAIN);
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PaymentGuideTranslationQueueService.php');
        self::assertStringContainsString("'domain' => self::DOMAIN", $source);
        self::assertStringContainsString("'words' => \$words", $source);
    }
}
