<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;

/**
 * CLI Phrase locale: env.php `cli_language`, independent of storefront website locales.
 */
final class CliLanguage
{
    public const CONFIG_KEY = 'cli_language';

    public const EVENT_OPTIONS = 'Weline_Framework_Phrase::cli_language_options';

    public static function isCliContext(): bool
    {
        return Runtime::isCli();
    }

    /**
     * Resolved CLI Phrase locale (never website language-list first item).
     */
    public static function resolveConfigured(): string
    {
        $candidates = [];
        try {
            $candidates[] = \trim((string)Env::get(self::CONFIG_KEY, ''));
        } catch (\Throwable) {
        }
        try {
            $candidates[] = \trim((string)Env::system('lang', ''));
        } catch (\Throwable) {
        }
        $candidates[] = Env::default_LANGUAGE_CODE;

        foreach ($candidates as $candidate) {
            $code = self::normalize((string)$candidate);
            if ($code !== '' && State::isLanguageCodeShape($code)) {
                return $code;
            }
        }

        return Env::default_LANGUAGE_CODE;
    }

    public static function get(): string
    {
        return self::resolveConfigured();
    }

    /**
     * Persist CLI locale and clear Phrase lang cache for the current process.
     *
     * @throws \InvalidArgumentException when code is empty, malformed, or not in catalog
     */
    public static function set(string $code): string
    {
        $code = self::normalize($code);
        if ($code === '' || !State::isLanguageCodeShape($code)) {
            throw new \InvalidArgumentException((string)__('无效的 CLI 语言代码：%{1}', [$code]));
        }

        /** @var CliLanguageCatalog $catalog */
        $catalog = ObjectManager::getInstance(CliLanguageCatalog::class);
        $codes = $catalog->codes();
        if ($codes !== [] && !\in_array($code, $codes, true)) {
            throw new \InvalidArgumentException((string)__('语言不在可选列表中：%{1}', [$code]));
        }

        if (!Env::getInstance()->setConfig(self::CONFIG_KEY, $code)) {
            throw new \RuntimeException((string)__('写入 cli_language 失败，请检查 app/etc/env.php 权限'));
        }

        State::resetLangLocalCache();
        State::setRequestLanguageOverride($code);

        return $code;
    }

    public static function normalize(string $code): string
    {
        return \str_replace('-', '_', \trim($code));
    }
}
