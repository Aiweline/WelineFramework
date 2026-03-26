<?php

declare(strict_types=1);

namespace Weline\DataTable\Helper;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class FrontendAccess
{
    public static function isAllowed(array $attributes = [], array $fallbackAttributes = []): bool
    {
        if (self::isUnitTest()) {
            return true;
        }

        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        if ($request->isBackend() || $request->isApiBackend()) {
            return true;
        }

        $allowFrontend = $attributes['allow-frontend'] ?? $fallbackAttributes['allow-frontend'] ?? false;
        return filter_var($allowFrontend, FILTER_VALIDATE_BOOLEAN);
    }

    public static function deniedComment(string $tagName): string
    {
        if (\defined('DEV') && DEV) {
            return sprintf(
                '<!-- %s requires backend/api-backend or allow-frontend="true" -->',
                htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8')
            );
        }

        return '';
    }

    private static function isUnitTest(): bool
    {
        return (\defined('ENV_TEST') && ENV_TEST === true)
            || \defined('PHPUNIT_COMPOSER_INSTALL')
            || \defined('__PHPUNIT_PHAR__');
    }
}
