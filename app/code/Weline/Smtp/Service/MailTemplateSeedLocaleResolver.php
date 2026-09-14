<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * Resolves mail-template seed locales from the default website language set.
 * Always keeps zh_Hans_CN + en_US so fallback stays bilingual.
 */
final class MailTemplateSeedLocaleResolver
{
    /**
     * @return list<string>
     */
    public static function forDefaultWebsite(): array
    {
        $codes = MailTemplateSeedCopyCatalog::baselineLocales();
        try {
            /** @var WebsiteLanguage $model */
            $model = ObjectManager::getInstance(WebsiteLanguage::class);
            $websiteCodes = $model->getWebsiteLanguageCodes(Website::ID_DEFAULT);
            if (is_array($websiteCodes)) {
                foreach ($websiteCodes as $code) {
                    $code = trim((string)$code);
                    if ($code === '') {
                        continue;
                    }
                    $codes[] = $code;
                }
            }
        } catch (\Throwable) {
            // Keep bilingual baseline when websites runtime is unavailable (unit tests).
        }

        $seen = [];
        $out = [];
        foreach ($codes as $code) {
            $code = trim((string)$code);
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = $code;
        }

        return $out;
    }
}
