<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\View\Template;

/**
 * 默认 Partials 页脚组合：复用 footer-* 部件模板渲染完整插槽内容。
 */
final class FooterPartialComposer
{
    private const NEWSLETTER = 'Weline_Theme::theme/frontend/widgets/newsletter/footer-newsletter/default.phtml';
    private const SOCIAL = 'Weline_Theme::theme/frontend/widgets/social/footer-social/default.phtml';
    private const PAYMENT = 'Weline_Theme::theme/frontend/widgets/footer/footer-payment/default.phtml';

    public static function renderNewsletter(Template $template): string
    {
        return self::renderWidget($template, self::NEWSLETTER);
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
