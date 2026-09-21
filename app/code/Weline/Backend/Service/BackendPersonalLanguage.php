<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Model\BackendUserConfig;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;

/**
 * 后台管理员个人语言（与全局 backend_default_language、网站前台默认语言无关）。
 */
final class BackendPersonalLanguage
{
    public const CONFIG_KEY = 'personal_language';
    public const CONFIG_MODULE = 'Weline_Backend';
    public const CONFIG_NAME = 'locale';

    public static function resolveForCurrentUser(): string
    {
        try {
            /** @var BackendUserConfig $config */
            $config = ObjectManager::getInstance(BackendUserConfig::class);
            $userId = $config->getCurrentUserId();
            if ($userId <= 0) {
                return '';
            }

            return self::normalizeStored((string)$config->getConfig(
                self::CONFIG_KEY,
                self::CONFIG_MODULE,
                self::CONFIG_NAME
            ));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function resolveForUserId(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        try {
            /** @var BackendUserConfig $config */
            $config = ObjectManager::getInstance(BackendUserConfig::class);
            $row = $config->clear()
                ->where(BackendUserConfig::schema_fields_user_id, $userId)
                ->where(BackendUserConfig::schema_fields_key, self::CONFIG_KEY)
                ->find()
                ->fetchArray();

            return self::normalizeStored((string)($row[BackendUserConfig::schema_fields_value] ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function primeRuntime(string $language): void
    {
        $language = self::normalizeStored($language);
        if ($language === '') {
            return;
        }
        try {
            WelineEnv::set('backend_personal_language', $language, 'backend personal language');
            WelineEnv::set('user.lang', $language, 'backend personal language');
        } catch (\Throwable) {
        }
        State::resetLangLocalCache();
    }

    public static function runtimeOverride(): string
    {
        try {
            return self::normalizeStored((string)WelineEnv::get('backend_personal_language', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function normalizeStored(string $code): string
    {
        $code = \str_replace('-', '_', \trim($code));
        if ($code === '' || !State::isLanguageCodeShape($code)) {
            return '';
        }

        return $code;
    }
}
