<?php

declare(strict_types=1);

namespace Weline\HelpPay\View;

/**
 * Storefront share / quick-pay / help-pay modal copy for helppay-share.js.
 * Kept outside view/templates so Theme tpl compilation cannot rewrite the include path.
 */
final class ShareModalI18n
{
    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return \Weline\HelpPay\Service\ShareModalI18n::labels();
    }

    public static function json(): string
    {
        return \Weline\HelpPay\Service\ShareModalI18n::json();
    }
}
