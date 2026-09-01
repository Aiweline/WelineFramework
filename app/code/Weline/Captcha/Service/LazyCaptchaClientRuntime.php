<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

use Weline\Framework\DataObject\DataInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;

/**
 * Once-per-request browser runtime for FPC-safe captcha lazy hosts / refresh.
 */
final class LazyCaptchaClientRuntime
{
    public const REQUEST_INJECTED_KEY = 'captcha.client_runtime.injected';

    public const SCRIPT_SOURCE = 'Weline_Captcha::js/captcha-lazy.js?v=20260831-layout-fix1';

    public const STYLESHEET_SOURCE = 'Weline_Captcha::css/captcha-local.css?v=20260831-layout-fix1';

    public static function onceScriptHtml(): string
    {
        if (RequestContext::get(self::REQUEST_INJECTED_KEY)) {
            return '';
        }
        RequestContext::set(self::REQUEST_INJECTED_KEY, true);

        $url = self::resolveScriptUrl();
        if ($url === '') {
            return '';
        }

        return '<script src="'
            . \htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            . '" defer data-weline-captcha-runtime="1"></script>';
    }

    public static function resolveScriptUrl(): string
    {
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $url = \trim((string)$template->fetchTagSource(DataInterface::dir_type_STATICS, self::SCRIPT_SOURCE));
            if ($url !== '') {
                return $url;
            }
        } catch (\Throwable) {
        }

        // Match storefront module static URL shape used by Theme/Currency assets.
        return '/Weline/Captcha/view/statics/js/captcha-lazy.js?v=20260831-layout-fix1';
    }

    public static function resolveStylesheetUrl(): string
    {
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $url = \trim((string)$template->fetchTagSource(DataInterface::dir_type_STATICS, self::STYLESHEET_SOURCE));
            if ($url !== '') {
                return $url;
            }
        } catch (\Throwable) {
        }

        return '/Weline/Captcha/view/statics/css/captcha-local.css?v=20260831-layout-fix1';
    }
}
