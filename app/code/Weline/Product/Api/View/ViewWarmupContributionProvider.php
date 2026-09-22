<?php

declare(strict_types=1);

namespace Weline\Product\Api\View;

use Weline\Framework\App\State;
use Weline\Framework\Runtime\Preload\ViewWarmupContribution;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionProviderInterface;

/**
 * Publishes bounded anonymous catalog surfaces for the WLS startup warmup.
 */
final class ViewWarmupContributionProvider implements ViewWarmupContributionProviderInterface
{
    public function contribution(): ViewWarmupContribution
    {
        return new ViewWarmupContribution(
            fpcPaths: $this->catalogWarmupPaths(),
        );
    }

    /**
     * @return list<string>
     */
    private function catalogWarmupPaths(): array
    {
        $defaults = $this->resolveWebsiteDefaults();
        $defaultLanguage = $defaults['language'];
        $paths = ['/products' => '/products'];

        foreach ($defaults['languages'] as $code) {
            $code = \trim((string)$code);
            if ($code === '' || !State::isLanguageCodeShape($code)) {
                continue;
            }
            if ($defaultLanguage !== '' && \strcasecmp($code, $defaultLanguage) === 0) {
                continue;
            }
            $path = '/' . $code . '/products';
            $paths[$path] = $path;
        }

        if (\count($paths) === 1) {
            foreach (['ar_SA', 'zh_Hans_CN', 'en_US'] as $code) {
                if ($defaultLanguage !== '' && \strcasecmp($code, $defaultLanguage) === 0) {
                    continue;
                }
                $path = '/' . $code . '/products';
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
