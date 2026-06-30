<?php

declare(strict_types=1);

namespace Weline\Framework\Registry\Service;

use Weline\Framework\App\Env;

final class RegistryModulePresence
{
    public static function isActivePresent(string $moduleName, ?Env $env = null): bool
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '') {
            return false;
        }

        $env ??= Env::getInstance();
        $moduleInfo = $env->getModuleInfo($moduleName);
        if (!is_array($moduleInfo) || empty($moduleInfo['status'])) {
            return false;
        }

        return self::moduleSourceExists($moduleInfo);
    }

    /**
     * @param array<string,mixed> $moduleInfo
     */
    public static function moduleSourceExists(array $moduleInfo): bool
    {
        $basePath = $moduleInfo['base_path'] ?? '';
        if (!is_string($basePath) || trim($basePath) === '') {
            return false;
        }

        $basePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;
        return is_dir($basePath) && is_file($basePath . 'register.php');
    }

    /**
     * @param array<string,array<string,mixed>> $modules
     * @return list<string>
     */
    public static function detectMissingSourceModules(array $modules): array
    {
        $missing = [];
        foreach ($modules as $moduleName => $moduleInfo) {
            if (!is_array($moduleInfo) || !self::moduleSourceExists($moduleInfo)) {
                $name = is_string($moduleName) && $moduleName !== ''
                    ? $moduleName
                    : (string)($moduleInfo['name'] ?? '');
                if ($name !== '') {
                    $missing[] = $name;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    public static function classExists(string $className): bool
    {
        $className = ltrim(trim($className), '\\');
        if ($className === '') {
            return false;
        }

        if (class_exists($className, false)) {
            return true;
        }

        set_error_handler(static fn(): bool => true);
        try {
            return class_exists($className);
        } finally {
            restore_error_handler();
        }
    }
}
