<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\View\Template;

/**
 * 默认 Partials 页脚组合：复用 footer-* 部件模板渲染完整插槽内容。
 *
 * Newsletter 业务模板已迁至 Weline_Newsletter（D10）：renderNewsletter 停用，
 * 禁止再硬编码 Theme newsletter 路径，避免与 required default_injections 双渲。
 */
final class FooterPartialComposer
{
    private const SOCIAL = 'Weline_Theme::theme/frontend/widgets/social/footer-social/default.phtml';
    private const PAYMENT = 'Weline_Theme::theme/frontend/widgets/footer/footer-payment/default.phtml';

    /**
     * @deprecated since newsletter-subscribe D10 — footer 订阅改由槽 footer-newsletter + Newsletter Widget 注入
     */
    public static function renderNewsletter(Template $template): string
    {
        unset($template);

        return '';
    }

    public static function renderSocial(Template $template): string
    {
        return self::renderWidget($template, self::SOCIAL);
    }

    public static function renderPayment(Template $template): string
    {
        return self::renderWidget($template, self::PAYMENT);
    }

    private static function renderWidget(Template $template, string $path): string
    {
        try {
            $html = trim((string)$template->fetch($path));

            return $html;
        } catch (\Throwable) {
            return '';
        }
    }
}
