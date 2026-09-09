<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

/**
 * Migrated hub content formerly hardcoded in Theme layouts/faq/default.phtml.
 */
final class FaqHubContent
{
    /**
     * @return list<array{icon:string,title:string,desc:string,url:string,route?:bool,open_cs?:bool}>
     */
    public function topics(): array
    {
        return [
            ['icon' => 'truck', 'title' => (string)__('物流配送'), 'desc' => (string)__('发货时效、运费与配送范围'), 'url' => 'guide/shipping', 'route' => true],
            ['icon' => 'undo', 'title' => (string)__('退换货'), 'desc' => (string)__('退货条件、流程与退款进度'), 'url' => 'guide/returns', 'route' => true],
            ['icon' => 'credit-card', 'title' => (string)__('支付与发票'), 'desc' => (string)__('支付方式、账单与发票开具'), 'url' => 'guide/payment', 'route' => true],
            ['icon' => 'box', 'title' => (string)__('订单问题'), 'desc' => (string)__('下单、改单、取消与订单状态'), 'url' => 'customer/account/index#orders', 'route' => true],
            ['icon' => 'user', 'title' => (string)__('账户与安全'), 'desc' => (string)__('登录、密码与个人信息'), 'url' => 'customer/account/index', 'route' => true],
            ['icon' => 'headset', 'title' => (string)__('联系客服'), 'desc' => (string)__('电话、邮箱与在线支持通道'), 'url' => '#contact-service', 'open_cs' => true],
        ];
    }

    /**
     * @return list<array{q:string,a:string}>
     */
    public function faqs(): array
    {
        return [
            ['q' => (string)__('下单后多久发货？'), 'a' => (string)__('现货订单通常在付款成功后 1–3 个工作日内发出（节假日顺延）；预售、定制或以商品页标注时效为准。详见「配送说明」。')],
            ['q' => (string)__('如何查询物流？'), 'a' => (string)__('登录后打开「我的订单」可查看承运商与运单节点。长时间无更新时，可先排除节假日，再通过「联系客服」并提供订单号协助查询。')],
            ['q' => (string)__('运费如何计算？是否包邮？'), 'a' => (string)__('运费按收货地区、重量/体积与配送服务在结账页实时计算。满足满额包邮或活动门槛时会自动减免，以结算页显示为准。')],
            ['q' => (string)__('支持哪些支付方式？'), 'a' => (string)__('支持站点已开通的在线支付渠道（如 PayPal 等）。具体可用方式以结算页与「支付方式」指南为准，并可查阅各支付商的用户协议。')],
            ['q' => (string)__('如何申请退换货？'), 'a' => (string)__('请先阅读「退换政策」确认期限与品类要求，再在「我的订单」提交售后并上传凭证。质量问题与个人原因的运费承担规则不同，详见政策页。')],
            ['q' => (string)__('退款多久到账？'), 'a' => (string)__('退回商品质检通过后，退款一般在 3–15 个工作日内原路退回，具体到账时间以支付渠道为准。完整规则见「退款政策」。')],
            ['q' => (string)__('可以修改或取消订单吗？'), 'a' => (string)__('未发货订单可在「我的订单」尝试取消或联系客服协助修改地址/备注。已发货订单无法直接取消，可按签收后的退换政策办理。')],
            ['q' => (string)__('个人信息如何保护？'), 'a' => (string)__('我们仅在提供交易与服务所必需的范围内处理个人信息，详见「隐私政策」与「Cookie 政策」。您可在账户设置中管理部分偏好。')],
        ];
    }

    /**
     * @return list<array{label:string,url:string,route?:bool,open_cs?:bool}>
     */
    public function quickLinks(): array
    {
        return [
            ['label' => (string)__('我的订单'), 'url' => 'customer/account/index#orders', 'route' => true],
            ['label' => (string)__('我的账户'), 'url' => 'customer/account/index', 'route' => true],
            ['label' => (string)__('配送说明'), 'url' => 'guide/shipping', 'route' => true],
            ['label' => (string)__('退换政策'), 'url' => 'guide/returns', 'route' => true],
            ['label' => (string)__('退款政策'), 'url' => 'refund', 'route' => true],
            ['label' => (string)__('支付方式'), 'url' => 'guide/payment', 'route' => true],
            ['label' => (string)__('隐私政策'), 'url' => 'privacy', 'route' => true],
            ['label' => (string)__('服务条款'), 'url' => 'terms', 'route' => true],
            ['label' => (string)__('Cookie 政策'), 'url' => 'cookies', 'route' => true],
            ['label' => (string)__('联系客服'), 'url' => '#contact-service', 'route' => false, 'open_cs' => true],
        ];
    }

    /**
     * FAQ facts for SEO FAQPage (question/answer keys).
     *
     * @return list<array{question:string,answer:string}>
     */
    public function seoFaqs(): array
    {
        $out = [];
        foreach ($this->faqs() as $row) {
            $out[] = [
                'question' => (string)($row['q'] ?? ''),
                'answer' => (string)($row['a'] ?? ''),
            ];
        }

        return $out;
    }
}
