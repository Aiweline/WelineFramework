<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Config;

use Weline\Backend\Model\BackendUserConfig;
use Weline\Framework\App\Env;

/**
 * Scalar-only boundary for administrator-specific configuration.
 *
 * Queries are always constrained by user_id + key. No ORM object, query builder
 * or mutable model state crosses the module boundary.
 */
final class BackendUserConfigStore
{
    public function __construct(
        private readonly BackendUserConfig $model,
    ) {
    }

    public function getCurrentUserId(): int
    {
        return $this->model->getCurrentUserId();
    }

    public function getConfig(string $key, string $module = '', string $name = '', bool $real = false): string
    {
        if ($this->isCommandLineConfigContext()) {
            return $this->getDefaultConfig($key);
        }

        return $this->getForUser(
            $this->getCurrentUserId(),
            $key,
            $real ? '' : $module,
            $real ? '' : $name,
        );
    }

    public function getForUser(int $userId, string $key, string $module = '', string $name = ''): string
    {
        $query = (clone $this->model)->clearData()->clearQuery()
            ->where(BackendUserConfig::schema_fields_user_id, \max(0, $userId))
            ->where(BackendUserConfig::schema_fields_key, $key);
        if ($module !== '') {
            $query->where(BackendUserConfig::schema_fields_module, $module);
        }
        if ($name !== '') {
            $query->where(BackendUserConfig::schema_fields_name, $name);
        }

        $row = $query->find()->fetchArray();
        return \is_array($row) ? (string)($row[BackendUserConfig::schema_fields_value] ?? '') : '';
    }

    /**
     * Historical compatibility: the legacy method resolves the first user_id=0
     * row and does not constrain by key. Existing Setup callers rely on it.
     */
    public function getDefaultConfig(string $key): string
    {
        try {
            $row = (clone $this->model)->clearData()->clearQuery()
                ->where(BackendUserConfig::schema_fields_user_id, 0)
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return '';
        }
        return \is_array($row) ? (string)($row[BackendUserConfig::schema_fields_value] ?? '') : '';
    }

    /** New code can request an exact default key without legacy fallback semantics. */
    public function getDefaultConfigForKey(string $key, string $module = '', string $name = ''): string
    {
        return $this->getForUser(0, $key, $module, $name);
    }

    public function setConfig(
        string $key,
        string $value,
        string $module,
        string $name,
        bool $check = true,
    ): bool {
        if ($this->isCommandLineConfigContext()) {
            return (bool)$this->setDefaultConfig($key, $value, $module, $name, $check);
        }

        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return false;
        }
        return $this->writeForUser($userId, $key, $value, $module, $name, $check);
    }

    public function setDefaultConfig(
        string $key,
        string $value,
        string $module,
        string $name,
        bool $check = true,
    ): bool|int {
        return $this->writeForUser(0, $key, $value, $module, $name, $check);
    }

    public function deleteConfig(
        string $key,
        string $module = '',
        string $name = '',
        ?int $userId = null,
    ): bool {
        $userId ??= $this->isCommandLineConfigContext() ? 0 : $this->getCurrentUserId();
        $query = (clone $this->model)->clearData()->clearQuery()
            ->where(BackendUserConfig::schema_fields_user_id, \max(0, $userId))
            ->where(BackendUserConfig::schema_fields_key, $key);
        if ($module !== '') {
            $query->where(BackendUserConfig::schema_fields_module, $module);
        }
        if ($name !== '') {
            $query->where(BackendUserConfig::schema_fields_name, $name);
        }
        $row = $query->find()->fetchArray();
        if (!\is_array($row) || $row === []) {
            return false;
        }

        $delete = (clone $this->model)->clearData()->clearQuery()
            ->where(BackendUserConfig::schema_fields_user_id, \max(0, $userId))
            ->where(BackendUserConfig::schema_fields_key, $key);
        if ($module !== '') {
            $delete->where(BackendUserConfig::schema_fields_module, $module);
        }
        if ($name !== '') {
            $delete->where(BackendUserConfig::schema_fields_name, $name);
        }
        return (bool)$delete->delete()->fetch();
    }

    private function writeForUser(
        int $userId,
        string $key,
        string $value,
        string $module,
        string $name,
        bool $check,
    ): bool {
        if ($check && !Env::getInstance()->getModuleInfo($module)) {
            if (DEV) {
                throw new \Exception((string)__('找不到模组: %{1}', $module));
            }
            return false;
        }

        $result = (clone $this->model)->clearData()->clearQuery()
            ->insert([
                BackendUserConfig::schema_fields_user_id => \max(0, $userId),
                BackendUserConfig::schema_fields_key => $key,
                BackendUserConfig::schema_fields_value => $value,
                BackendUserConfig::schema_fields_module => $module,
                BackendUserConfig::schema_fields_name => $name,
            ], [
                BackendUserConfig::schema_fields_user_id,
                BackendUserConfig::schema_fields_key,
            ])
            ->fetch();
        return (bool)$result;
    }

    private function isCommandLineConfigContext(): bool
    {
        if (!\defined('CLI') || !CLI) {
            return false;
        }
        if ($this->resolveSessionIdFromCookie() !== '') {
            return false;
        }

        foreach (['REQUEST_METHOD', 'REQUEST_URI', 'HTTP_HOST', 'SERVER_NAME', 'WELINE_AREA', 'WELINE_AREA_ROUTE'] as $key) {
            if ((string)($_SERVER[$key] ?? '') !== '') {
                return false;
            }
            if (\class_exists(\Weline\Framework\Env\WelineEnv::class)
                && (string)\Weline\Framework\Env\WelineEnv::server($key, '') !== ''
            ) {
                return false;
            }
        }
        return true;
    }

    private function resolveSessionIdFromCookie(): string
    {
        $cookieHeader = '';
        if (\class_exists(\Weline\Framework\Env\WelineEnv::class)) {
            $cookieHeader = (string)(
                \Weline\Framework\Env\WelineEnv::server('HTTP_COOKIE', '')
                ?: \Weline\Framework\Env\WelineEnv::get('server.http_cookie', '')
            );
        }
        if ($cookieHeader !== '' && \preg_match('/(?:^|;\s*)WELINE_SESSID=([^;]+)/', $cookieHeader, $matches)) {
            return \trim((string)\urldecode($matches[1]));
        }
        return \trim((string)($_COOKIE['WELINE_SESSID'] ?? ''));
    }
}
