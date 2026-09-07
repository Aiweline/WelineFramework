<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

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
                '1.1 一般情形：自签收之日起 7 日内，商品未经穿着使用（或仅试穿）、吊牌完整、不影响二次销售的，可申请退货或换货。',
                '1.1 General eligibility: You may request a return or exchange within 7 days of delivery if the item has not been worn or used (other than being tried on), its tags are intact, and it remains in a condition suitable for resale.',
            ],
            'refund timeframe' => [
                '5.3 到账时效：质检通过后 3–15 个工作日内原路退回，具体到账时间以支付渠道（银行卡、第三方支付等）为准。节假日可能顺延。',
                '5.3 Refund timing: Refunds are returned to the original payment method within 3–15 business days after the quality inspection is passed. The exact time depends on the payment provider, such as your bank or third-party payment service. Public holidays may cause delays.',
            ],
            'help navigation' => ['返回帮助中心', 'Back to the Help Center'],
        ];
    }
}
