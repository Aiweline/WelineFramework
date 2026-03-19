<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page as PageModel;

/**
 * 法律类页面默认 HTML 占位（批量建站时使用）。
 * 不依赖 view 下的 phtml 文件，避免 include 缺失导致 Warning。
 */
final class DefaultLegalPlaceholder
{
    public static function toHtml(string $legalType, string $legalTitle): string
    {
        $title = $legalTitle !== '' ? $legalTitle : (string) __('法律信息');
        $body = self::bodyForType($legalType);
        $typeAttr = htmlspecialchars($legalType, ENT_QUOTES, 'UTF-8');
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $notice = htmlspecialchars((string) __('以下为占位说明，请在后台编辑为本站正式法律文本。'), ENT_QUOTES, 'UTF-8');

        return '<div class="page-legal-default glr-legal-placeholder" data-legal-type="' . $typeAttr . '">'
            . '<header class="glr-legal-placeholder__header">'
            . '<h1 class="glr-legal-placeholder__title">' . $titleEsc . '</h1>'
            . '<p class="glr-legal-placeholder__notice">' . $notice . '</p>'
            . '</header>'
            . '<div class="glr-legal-placeholder__body">' . $body . '</div>'
            . '</div>';
    }

    private static function bodyForType(string $legalType): string
    {
        return match ($legalType) {
            PageModel::TYPE_PRIVACY_POLICY => self::privacyBody(),
            PageModel::TYPE_TERMS_OF_SERVICE => self::termsBody(),
            PageModel::TYPE_REFUND_POLICY => self::refundBody(),
            PageModel::TYPE_SHIPPING_POLICY => self::shippingBody(),
            PageModel::TYPE_COOKIE_POLICY => self::cookieBody(),
            default => '<section><p>' . self::e((string) __('请根据实际业务在后台补充完整法律文本。')) . '</p></section>',
        };
    }

    private static function privacyBody(): string
    {
        return self::section(__('信息收集'), __('说明本站可能收集哪些类型的个人信息或非个人信息，以及收集方式。'))
            . self::section(__('使用目的'), __('说明数据用于提供服务、改进产品、合规与安全等目的。'))
            . self::section(__('存储与安全'), __('说明数据保留期限、安全措施及跨境传输（如适用）的概要。'))
            . self::sectionWithList(
                __('您的权利'),
                [
                    __('访问、更正或删除个人信息的途径。'),
                    __('撤回同意或限制处理的方式（如适用）。'),
                ]
            )
            . self::section(__('第三方与 Cookie'), __('说明是否与第三方共享数据，以及 Cookie 与类似技术的使用概况。'));
    }

    private static function termsBody(): string
    {
        return self::section(__('服务说明'), __('描述所提供的服务范围、使用条件及账户相关规则。'))
            . self::section(__('用户义务'), __('列出禁止行为、内容规范及违规可能导致的后果。'))
            . self::section(__('知识产权'), __('说明站点内容、商标与许可使用的边界。'))
            . self::section(__('免责声明与责任限制'), __('在适用法律允许范围内对责任范围作出说明（需由法务审阅）。'))
            . self::section(__('争议解决与条款变更'), __('约定适用法律、争议解决方式及条款更新通知机制。'));
    }

    private static function refundBody(): string
    {
        return self::section(__('适用范围'), __('哪些订单或产品适用本退款政策。'))
            . self::section(__('申请流程与时限'), __('用户如何发起退款申请及处理时限说明。'))
            . self::section(__('退款方式'), __('原路退回或其他退款路径及预计到账时间。'))
            . self::section(__('不可退款情形'), __('列明不适用退款的典型情况。'));
    }

    private static function shippingBody(): string
    {
        return self::section(__('配送范围'), __('可配送国家/地区及例外说明。'))
            . self::section(__('时效与费用'), __('标准配送时效、加急选项及运费计算方式。'))
            . self::section(__('物流与签收'), __('跟踪信息、签收要求及异常件处理。'));
    }

    private static function cookieBody(): string
    {
        return self::section(__('Cookie 是什么'), __('简要说明 Cookie 及类似技术的作用。'))
            . self::section(__('我们使用的类型'), __('区分必要 Cookie 与可选/分析类 Cookie，并说明用途。'))
            . self::section(__('如何管理'), __('用户如何通过浏览器或本站设置管理偏好。'));
    }

    /**
     * @param string|\Stringable $heading
     * @param string|\Stringable $paragraph
     */
    private static function section(string|\Stringable $heading, string|\Stringable $paragraph): string
    {
        return '<section><h2>' . self::e((string) $heading) . '</h2><p>' . self::e((string) $paragraph) . '</p></section>';
    }

    /**
     * @param string|\Stringable $heading
     * @param list<string|\Stringable> $items
     */
    private static function sectionWithList(string|\Stringable $heading, array $items): string
    {
        $lis = '';
        foreach ($items as $item) {
            $lis .= '<li>' . self::e((string) $item) . '</li>';
        }

        return '<section><h2>' . self::e((string) $heading) . '</h2><ul>' . $lis . '</ul></section>';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
