<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Model\FaqItem;
use Weline\Framework\Manager\ObjectManager;

/**
 * Idempotent ecommerce FAQ template pack seeds (website_id=0 global defaults).
 * At least zh_Hans_CN + en_US rows are required so storefront locale does not fall back to Chinese.
 */
final class FaqTemplateSeedService
{
    public const LOCALE_ZH = 'zh_Hans_CN';
    public const LOCALE_EN = 'en_US';

    /** @return list<string> */
    public static function requiredLocales(): array
    {
        return [self::LOCALE_ZH, self::LOCALE_EN];
    }

    public function __construct(
        private readonly FaqService $faqs,
    ) {
    }

    public function seedAll(): int
    {
        $created = 0;
        foreach (FaqTemplatePacks::codes() as $pack) {
            $created += $this->seedPack($pack);
        }

        return $created;
    }

    public function seedPack(string $pack): int
    {
        $pack = FaqTemplatePacks::normalize($pack);
        $created = 0;
        foreach (self::requiredLocales() as $locale) {
            $created += $this->seedPackLocale($pack, $locale);
        }

        return $created;
    }

    /**
     * Relabel legacy empty-locale Chinese seeds as zh_Hans_CN so en_US no longer inherits them.
     */
    public function migrateEmptyLocaleToZhHans(): int
    {
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $updated = 0;
        try {
            $rows = $model->clear()
                ->where(FaqItem::schema_fields_TYPE_CODE, TemplateFaqTypeProvider::TYPE_CODE)
                ->where(FaqItem::schema_fields_WEBSITE_ID, 0)
                ->where(FaqItem::schema_fields_STORE_CODE, '')
                ->where(FaqItem::schema_fields_CHANNEL_CODE, '')
                ->where(FaqItem::schema_fields_LOCALE_CODE, '')
                ->select()
                ->fetchArray();
            if (!is_array($rows)) {
                return 0;
            }
            $now = date('Y-m-d H:i:s');
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row[FaqItem::schema_fields_ID] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $pack = (string)($row[FaqItem::schema_fields_ENTITY_UUID] ?? '');
                $faqKey = (string)($row[FaqItem::schema_fields_FAQ_KEY] ?? '');
                if ($pack === '' || $faqKey === '') {
                    continue;
                }
                if ($this->exists($pack, $faqKey, self::LOCALE_ZH)) {
                    // Prefer the explicit zh row; drop the legacy empty duplicate.
                    $model->clear()->load($id);
                    if ((int)$model->getData(FaqItem::schema_fields_ID) === $id) {
                        $model->delete();
                        $updated++;
                    }
                    continue;
                }
                $model->clear()->load($id);
                if ((int)$model->getData(FaqItem::schema_fields_ID) !== $id) {
                    continue;
                }
                $model->setData(FaqItem::schema_fields_LOCALE_CODE, self::LOCALE_ZH);
                $model->setData(FaqItem::schema_fields_UPDATED_AT, $now);
                $model->save();
                $updated++;
            }
        } catch (\Throwable) {
            return $updated;
        }

        return $updated;
    }

    private function seedPackLocale(string $pack, string $locale): int
    {
        $defs = $this->definitions()[$pack][$locale] ?? [];
        if ($defs === []) {
            return 0;
        }
        $created = 0;
        $sort = 0;
        foreach ($defs as $row) {
            $faqKey = (string)$row['faq_key'];
            if ($this->exists($pack, $faqKey, $locale)) {
                $sort++;
                continue;
            }
            $this->faqs->save([
                'website_id' => 0,
                'store_code' => '',
                'channel_code' => '',
                'locale_code' => $locale,
                'type_code' => TemplateFaqTypeProvider::TYPE_CODE,
                'entity_uuid' => $pack,
                'faq_key' => $faqKey,
                'question' => (string)$row['question'],
                'answer' => (string)$row['answer'],
                'sort_order' => $sort++,
                'status' => FaqItem::STATUS_ENABLED,
            ]);
            $created++;
        }

        return $created;
    }

    private function exists(string $pack, string $faqKey, string $localeCode): bool
    {
        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        try {
            $row = $model->clear()
                ->where(FaqItem::schema_fields_TYPE_CODE, TemplateFaqTypeProvider::TYPE_CODE)
                ->where(FaqItem::schema_fields_ENTITY_UUID, $pack)
                ->where(FaqItem::schema_fields_WEBSITE_ID, 0)
                ->where(FaqItem::schema_fields_STORE_CODE, '')
                ->where(FaqItem::schema_fields_CHANNEL_CODE, '')
                ->where(FaqItem::schema_fields_LOCALE_CODE, $localeCode)
                ->where(FaqItem::schema_fields_FAQ_KEY, $faqKey)
                ->find()
                ->fetch();
            if (is_object($row) && method_exists($row, 'getData')) {
                return (int)$row->getData(FaqItem::schema_fields_ID) > 0;
            }
            if (is_array($row)) {
                return (int)($row[FaqItem::schema_fields_ID] ?? 0) > 0;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * @return array<string, array<string, list<array{faq_key:string,question:string,answer:string}>>>
     */
    private function definitions(): array
    {
        return [
            FaqTemplatePacks::RETAIL => [
                self::LOCALE_ZH => [
                    ['faq_key' => 'shipping', 'question' => '配送多久能到？', 'answer' => '国内订单一般 2–5 个工作日送达；偏远地区可能稍长。发货后可在订单页查看物流。'],
                    ['faq_key' => 'returns', 'question' => '如何退换货？', 'answer' => '签收后 7 日内可申请退换（不影响二次销售）。在订单详情提交申请，按指引寄回即可。'],
                    ['faq_key' => 'warranty', 'question' => '质保如何计算？', 'answer' => '自签收日起享质保（具体期限以商品页为准）。人为损坏、未按说明使用不在质保范围。'],
                    ['faq_key' => 'payment', 'question' => '支持哪些支付方式？', 'answer' => '支持主流银行卡、第三方支付与站内可用的钱包方式；结账页以当前可用渠道为准。'],
                ],
                self::LOCALE_EN => [
                    ['faq_key' => 'shipping', 'question' => 'How long does delivery take?', 'answer' => 'Domestic orders usually arrive in 2–5 business days; remote areas may take longer. Track shipping on the order page after dispatch.'],
                    ['faq_key' => 'returns', 'question' => 'How do I return or exchange an item?', 'answer' => 'You can request a return or exchange within 7 days of delivery if the item is resalable. Submit the request from the order details and follow the return instructions.'],
                    ['faq_key' => 'warranty', 'question' => 'How is the warranty calculated?', 'answer' => 'Warranty starts on the delivery date (see the product page for the exact term). Damage from misuse or failure to follow instructions is not covered.'],
                    ['faq_key' => 'payment', 'question' => 'Which payment methods are supported?', 'answer' => 'We support major cards, third-party payments, and any wallets enabled on this storefront. Available methods are shown at checkout.'],
                ],
            ],
            FaqTemplatePacks::CROSS_BORDER => [
                self::LOCALE_ZH => [
                    ['faq_key' => 'customs', 'question' => '跨境订单需要交关税吗？', 'answer' => '视目的地海关政策而定。部分线路已含税；如产生税费，以清关通知为准。'],
                    ['faq_key' => 'lead_time', 'question' => '跨境时效大概多久？', 'answer' => '一般 7–20 个工作日，受清关与航线影响。可在物流轨迹查看最新节点。'],
                    ['faq_key' => 'clearance', 'question' => '清关需要我提供资料吗？', 'answer' => '偶发需要身份信息用于清关，我们会通过订单消息联系您，请及时配合以免延误。'],
                    ['faq_key' => 'returns_xb', 'question' => '跨境退货怎么处理？', 'answer' => '跨境退货需先审核。通过后按退货地址寄回；运费与税费政策以售后说明为准。'],
                ],
                self::LOCALE_EN => [
                    ['faq_key' => 'customs', 'question' => 'Will I pay customs duties on cross-border orders?', 'answer' => 'It depends on destination customs rules. Some lanes are duty-included; otherwise follow the clearance notice for any fees.'],
                    ['faq_key' => 'lead_time', 'question' => 'How long does cross-border shipping take?', 'answer' => 'Usually 7–20 business days, depending on clearance and carrier routes. Check the tracking timeline for the latest status.'],
                    ['faq_key' => 'clearance', 'question' => 'Do I need to provide documents for customs clearance?', 'answer' => 'Occasionally identity details are required. We will contact you via order messages—please respond promptly to avoid delays.'],
                    ['faq_key' => 'returns_xb', 'question' => 'How do cross-border returns work?', 'answer' => 'Returns need prior approval. After approval, ship to the return address; shipping and duty policy follow the after-sales instructions.'],
                ],
            ],
            FaqTemplatePacks::VIRTUAL => [
                self::LOCALE_ZH => [
                    ['faq_key' => 'delivery', 'question' => '虚拟商品如何发货？', 'answer' => '支付成功后通常即时或数分钟内通过站内消息/邮件交付账号、激活码或下载链接。'],
                    ['faq_key' => 'account', 'question' => '账号信息在哪里查看？', 'answer' => '请在订单详情或账户消息中查看。建议尽快修改初始密码并妥善保管。'],
                    ['faq_key' => 'non_refund', 'question' => '虚拟商品可以退款吗？', 'answer' => '一经交付通常不支持无理由退款。若无法激活等履约问题，请联系客服核实。'],
                    ['faq_key' => 'reuse', 'question' => '激活码可以重复使用吗？', 'answer' => '默认一码一用。若提示已使用，请核对订单与平台，并联系客服排查。'],
                ],
                self::LOCALE_EN => [
                    ['faq_key' => 'delivery', 'question' => 'How are virtual products delivered?', 'answer' => 'After payment, account details, activation codes, or download links are usually delivered instantly or within minutes via site message or email.'],
                    ['faq_key' => 'account', 'question' => 'Where can I find my account details?', 'answer' => 'Check the order details or account messages. Change the initial password promptly and keep credentials secure.'],
                    ['faq_key' => 'non_refund', 'question' => 'Can I get a refund for virtual products?', 'answer' => 'Once delivered, no-reason refunds are usually unavailable. Contact support if activation or fulfillment fails.'],
                    ['faq_key' => 'reuse', 'question' => 'Can activation codes be reused?', 'answer' => 'Codes are one-time by default. If a code shows as used, verify the order and platform, then contact support.'],
                ],
            ],
            FaqTemplatePacks::B2B => [
                self::LOCALE_ZH => [
                    ['faq_key' => 'moq', 'question' => '起订量是多少？', 'answer' => '不同 SKU 起订量不同，以商品页/报价单为准。批量询价可获更优阶梯价。'],
                    ['faq_key' => 'payment_terms', 'question' => '支持账期吗？', 'answer' => '认证企业客户可申请账期；额度与账期天数以商务审核结果为准。'],
                    ['faq_key' => 'contract', 'question' => '如何签合同与开票？', 'answer' => '下单后可申请合同与增值税发票。请在企业资料中维护开票信息。'],
                    ['faq_key' => 'lead_b2b', 'question' => '大货交期如何约定？', 'answer' => '交期写入报价/合同。加急需求请提前沟通产能与加急费用。'],
                ],
                self::LOCALE_EN => [
                    ['faq_key' => 'moq', 'question' => 'What is the minimum order quantity?', 'answer' => 'MOQ varies by SKU and is shown on the product page or quote. Bulk inquiries may unlock better tier pricing.'],
                    ['faq_key' => 'payment_terms', 'question' => 'Do you offer payment terms?', 'answer' => 'Verified business buyers can apply for terms; credit limit and days depend on commercial review.'],
                    ['faq_key' => 'contract', 'question' => 'How do contracts and invoices work?', 'answer' => 'After ordering you can request a contract and VAT invoice. Keep billing details updated in your business profile.'],
                    ['faq_key' => 'lead_b2b', 'question' => 'How are bulk lead times agreed?', 'answer' => 'Lead times are written into the quote or contract. Contact us early for rush capacity and fees.'],
                ],
            ],
        ];
    }
}
