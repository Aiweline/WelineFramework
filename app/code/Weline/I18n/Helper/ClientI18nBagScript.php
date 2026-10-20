<?php

declare(strict_types=1);

namespace Weline\I18n\Helper;


use Weline\Framework\Env\WelineEnv;
use Weline\I18n\Service\ClientI18nBagPublisher;
use Weline\I18n\Service\DictionaryAreaPolicy;

/**
 * Emit footer &lt;script defer&gt; for the current request locale client bag.
 */
final class ClientI18nBagScript
{
    public static function currentLocale(): string
    {
        try {
            $locale = trim((string)(WelineEnv::getLang() ?: ''));
        } catch (\Throwable) {
            $locale = '';
        }
        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    public static function renderTag(string $area): string
    {
        $area = DictionaryAreaPolicy::normalize($area);
        if ($area === DictionaryAreaPolicy::AREA_SHARED) {
            $area = DictionaryAreaPolicy::AREA_FRONTEND;
        }
        $meta = ClientI18nBagPublisher::resolveFooterScript($area, self::currentLocale());
        if ($meta === null) {
            return '';
        }
        $url = htmlspecialchars((string)$meta['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<script defer src="' . $url . '" data-weline-i18n-client-bag="1"></script>';
    }
}
