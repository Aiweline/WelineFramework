<?php

declare(strict_types=1);

namespace Weline\Index\Api\View;

use Weline\Framework\App\State;
use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

/**
 * Non-default locale homepage roots. Default-locale `/` is owned by Framework
 * deferred storefront warmup; prefixed locales need their own FPC keys.
 * Default-language prefixes are omitted — App 301-strips them to `/`.
 */
final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            fpcPaths: $this->nonDefaultLocaleHomepages(),
        );
    }

    /**
     * @return list<string>
     */
    private function nonDefaultLocaleHomepages(): array
    {
        $defaults = $this->resolveWebsiteDefaults();
        $defaultLanguage = $defaults['language'];
        $paths = [];

        foreach ($defaults['languages'] as $code) {
            $code = \trim((string)$code);
            if ($code === '' || !State::isLanguageCodeShape($code)) {
                continue;
            }
            if ($defaultLanguage !== '' && \strcasecmp($code, $defaultLanguage) === 0) {
                continue;
            }
            $path = '/' . $code . '/';
            $paths[$path] = $path;
        }

        if ($paths === []) {
            // Bounded fallback when Website snapshot is cold; Framework deferred
            // filter still drops default-locale prefixes at warmup time.
            foreach (['ar_SA', 'zh_Hans_CN', 'en_US'] as $code) {
                if ($defaultLanguage !== '' && \strcasecmp($code, $defaultLanguage) === 0) {
                    continue;
                }
                $path = '/' . $code . '/';
                $paths[$path] = $path;
            }
        }

        return \array_slice(\array_values($paths), 0, 4);
    }

    /**
     * @return array{language:string,currency:string,languages:list<string>}
     */
    private function resolveWebsiteDefaults(): array
    {
        $language = '';
        $currency = '';
        $languages = [];

        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class)
                && \class_exists(\Weline\Websites\Model\Website::class)
            ) {
                $snapshot = \Weline\Websites\Data\WebsiteData::readSharedSnapshotById(
                    \Weline\Websites\Model\Website::ID_DEFAULT
                );
                $website = \is_array($snapshot['website'] ?? null) ? $snapshot['website'] : [];
                $language = \trim((string)($website['default_language'] ?? ''));
                $currency = \trim((string)($website['default_currency'] ?? ''));
                $languages = \is_array($snapshot['language_codes'] ?? null)
                    ? \array_values(\array_map('strval', $snapshot['language_codes']))
                    : [];
            }
        } catch (\Throwable) {
        }

        // Align with App 301 / Framework deferred filter — never Env `lang` alone.
        if ($language === '') {
            try {
                $language = \trim(State::resolveWebsiteDefaultLanguage());
            } catch (\Throwable) {
            }
        }
        if ($currency === '') {
            try {
                $currency = \trim(State::resolveWebsiteDefaultCurrency());
            } catch (\Throwable) {
            }
        }

        return [
            'language' => $language,
            'currency' => $currency,
            'languages' => $languages,
        ];
    }
}
