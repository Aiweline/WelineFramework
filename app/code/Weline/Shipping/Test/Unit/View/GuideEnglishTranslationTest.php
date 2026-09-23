<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

require_once dirname(__DIR__, 6) . '/bootstrap.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Service\TranslationResolver;

final class GuideEnglishTranslationTest extends TestCase
{
    #[DataProvider('guideCopy')]
    public function testGuideCopyUsesEnglishWithoutChangingChinese(string $source, string $english): void
    {
        $dictionary = $this->createStub(DictionaryRepositoryInterface::class);
        $resolver = new TranslationResolver(dictionaryRepository: $dictionary);

        self::assertSame($english, $resolver->translate($source, 'en_US', ['Weline_Shipping']));
        self::assertSame($source, $resolver->translate($source, 'zh_Hans_CN', ['Weline_Shipping']));
    }

    public static function guideCopy(): array
    {
        return [
            'shipping contents' => ['一、发货时效', '1. Dispatch times'],
            'returns contents' => ['一、适用条件', '1. Eligibility'],
            'dispatch commitment' => [
                '1.1 现货订单：付款成功后，我们将在 1–3 个工作日内完成拣货、质检并发出（法定节假日与物流高峰期可能顺延）。',
                '1.1 In-stock orders: After successful payment, we pick, inspect and dispatch your order within 1–3 business days. Public holidays and peak shipping periods may extend this timeframe.',
            ],
            'return eligibility' => [
                '1.1 跨境售后说明：本店从中国国内发往海外。汉服跨境回程运费与税费较高，试穿后也较难二次销售，因此更建议您下单前确认尺码与款式。发出后，个人原因的退换通常难以安排；若遇到质量、错发或运输损坏，我们会认真处理。与部分平台的无理由退货不同，我们的售后重心是质量与发货准确性。',
                '1.1 Cross-border after-sales note: We ship from mainland China overseas. Cross-border return freight and taxes for hanfu are high, and tried-on garments are hard to resell, so we recommend confirming size and style before ordering. After dispatch, returns for personal reasons are usually difficult to arrange; if you encounter quality issues, wrong items, or shipping damage, we will handle them carefully. Unlike no-reason returns on some platforms, our after-sales focus is product quality and shipping accuracy.',
            ],
            'refund timeframe' => [
                '5.3 到账时效：质检通过后 3–15 个工作日内原路退回，具体到账时间以支付渠道（银行卡、第三方支付等）为准。节假日可能顺延。',
                '5.3 Refund timing: Refunds are returned to the original payment method within 3–15 business days after the quality inspection is passed. The exact time depends on the payment provider, such as your bank or third-party payment service. Public holidays may cause delays.',
            ],
            'help navigation' => ['返回帮助中心', 'Back to the Help Center'],
        ];
    }
}
