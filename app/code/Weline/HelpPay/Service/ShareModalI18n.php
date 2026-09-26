<?php

declare(strict_types=1);

namespace Weline\HelpPay\Service;

/**
 * Storefront share / quick-pay / help-pay modal copy for helppay-share.js (data-helppay-i18n).
 */
final class ShareModalI18n
{
    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'shareTitle' => (string) __('分享规格给朋友'),
            'shareWithPayer' => (string) __('分享给付款人'),
            'hint' => (string) __('复制链接或扫码分享当前规格'),
            'specSection' => (string) __('当前规格'),
            'specQty' => (string) __('数量（规格）'),
            'specSku' => 'SKU',
            'linkSection' => (string) __('链接分享'),
            'linkLabel' => (string) __('链接地址'),
            'copyLink' => (string) __('复制链接'),
            'tipUrl' => (string) __('复制后，粘贴发给朋友即可打开这份规格'),
            'tipUrlCopied' => (string) __('已复制，可以分发给朋友'),
            'tipUrlFail' => (string) __('复制没成功，请再点一次试试'),
            'qrSection' => (string) __('扫码分享'),
            'shareQrAria' => (string) __('分享二维码'),
            'copyQr' => (string) __('复制二维码图片'),
            'downloadQr' => (string) __('下载二维码'),
            'tipQr' => (string) __('复制后，可直接把图片粘贴到聊天里发送'),
            'tipQrCopied' => (string) __('已复制，可直接粘贴图片到聊天里发送'),
            'tipQrFail' => (string) __('复制没成功，请再点一次或改用下载'),
            'copied' => (string) __('已复制'),
            'copyFail' => (string) __('复制失败'),
            'qrCopyFallback' => (string) __('当前环境不支持复制图片，请改用下方下载'),
            'generatingShare' => (string) __('正在生成分享链接…'),
            'generatingQuick' => (string) __('正在进入付款…'),
            'quickTitle' => (string) __('本人快捷购买'),
            'quickCheckoutHint' => (string) __('地址已确认。正在进入付款页，可在本页完成支付。'),
            'quickPayNow' => (string) __('打开支付窗口'),
            'quickPayCreateFailed' => (string) __('未返回付款入口，请稍后重试。'),
            'quickPaidHint' => (string) __('可在支付窗口完成付款。若已支付，可关闭本弹窗。'),
            'payPopupOpened' => (string) __('已打开支付窗口，请在窗口内完成付款。'),
            'quickHint' => (string) __('复制链接或扫码，在本机或其它设备打开即可完成付款'),
            'quickLinkSection' => (string) __('付款链接'),
            'quickTipUrl' => (string) __('复制后在浏览器打开，用你自己的账号完成支付'),
            'quickTipUrlCopied' => (string) __('已复制，请自行打开链接付款'),
            'quickQrSection' => (string) __('扫码付款'),
            'quickQrAria' => (string) __('快捷购买付款二维码'),
            'quickTipQr' => (string) __('扫码后在本机打开付款页，完成你自己的支付'),
            'quickTipQrCopied' => (string) __('已复制，可粘贴到本机浏览器打开付款'),
            'confirmAddressTitle' => (string) __('确认收货地址'),
            'confirmAddressHint' => (string) __('请填写、选择或更换收货地址；确认后选择物流。'),
            'confirmQuickPay' => (string) __('确认并付款'),
            'confirmQuickNextShipping' => (string) __('下一步：选择物流'),
            'confirmHelpPayHint' => (string) __('请填写、选择或更换收货地址后再生成代付链接。'),
            'confirmHelpPay' => (string) __('确认并生成代付链接'),
            'generatingHelpPay' => (string) __('正在生成代付链接…'),
            'addressIncompleteHelpPay' => (string) __('请先完善收货地址后再生成代付链接。'),
            'loadingAddress' => (string) __('正在加载收货地址…'),
            'addressLoadFailed' => (string) __('收货地址组件加载失败，请稍后重试。'),
            'addressUnavailable' => (string) __('收货地址组件暂不可用，请改用结账流程。'),
            'addressIncomplete' => (string) __('请先完善收货地址后再付款。'),
            'cannotComplete' => (string) __('无法完成操作'),
            'noPaymentMethod' => (string) __('暂无可用支付方式，请稍后重试。'),
            'close' => (string) __('关闭'),
            // Quick-pay: address → shipping → payment
            'dialogStepShipping' => (string) __('物流'),
            'dialogShippingTitle' => (string) __('选择配送方式'),
            'dialogShippingHint' => (string) __('运费按收货地址报价，仅用于本单快捷购买，不影响结账页。'),
            'loadingShipping' => (string) __('正在计算运费…'),
            'backToAddress' => (string) __('返回地址'),
            'confirmShipping' => (string) __('下一步：付款'),
            'shippingQuoteFailed' => (string) __('运费报价失败，请稍后重试。'),
            'shippingUnavailable' => (string) __('该地址暂无可用配送方式，请更换地址后重试。'),
            'missingWeight' => (string) __('购物车商品缺少重量，无法计算运费。请联系客服协助处理后再试。'),
            'shippingRequired' => (string) __('请选择配送方式。'),
            'dialogPaymentTitle' => (string) __('确认支付'),
            'dialogPaymentHint' => (string) __('在弹窗内完成支付。支付商可能打开小窗，完成后回到此处。'),
            'backToShipping' => (string) __('返回物流'),
            'confirmPay' => (string) __('确认支付'),
            'crossDevicePay' => (string) __('换设备支付（链接）'),
            'paySummaryGoods' => (string) __('商品'),
            'paySummaryShipping' => (string) __('运费'),
            'paySummaryTotal' => (string) __('应付'),
            'paySummaryShipTo' => (string) __('收货'),
            // Help-pay dialog (rules → address → share)
            'dialogTitle' => (string) __('找朋友代付'),
            'dialogLead' => (string) __('生成付款链接发给朋友。订单仍归你，朋友只负责付款。'),
            'dialogBulletOwner' => (string) __('订单与收货信息归你，不会转给付款人'),
            'dialogBulletPay' => (string) __('朋友打开链接后完成支付即可'),
            'dialogBulletNoDiscount' => (string) __('代付不可用优惠券与积分'),
            'dialogRulesLink' => (string) __('查看完整规则'),
            'dialogRulesAccepted' => (string) __('我已阅读并同意帮我付规则'),
            'dialogNextAddress' => (string) __('下一步：确认收货地址'),
            'dialogAddressTitle' => (string) __('确认收货地址'),
            'dialogAddressLead' => (string) __('出链前请完整核对。仅此弹窗可见，之后店面不再展示收货详情。'),
            'dialogAddressConfirmed' => (string) __('地址正确，可以生成代付链接'),
            'dialogCreateShare' => (string) __('生成代付链接'),
            'dialogStepRules' => (string) __('规则'),
            'dialogStepAddress' => (string) __('地址'),
            'dialogStepShare' => (string) __('分享'),
            'dialogStepPay' => (string) __('付款'),
        ];
    }

    public static function json(): string
    {
        $json = json_encode(self::labels(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) && $json !== '' ? $json : '{}';
    }
}
