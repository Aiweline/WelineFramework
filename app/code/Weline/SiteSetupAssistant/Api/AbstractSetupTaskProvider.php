<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Api;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\Websites\Model\Website;

/**
 * 建站任务 Provider 抽象基类：子类继承后声明条目并实现检测。
 */
abstract class AbstractSetupTaskProvider implements SetupTaskProviderInterface
{
    abstract public function provideTasks(array $context = []): array;

    /**
     * @param array{
     *   code: string,
     *   title: string,
     *   tip?: string,
     *   status?: 'todo'|'doing'|'done',
     *   href?: string,
     *   category?: string,
     *   module?: string,
     *   scenarios?: list<'new'|'migrate'>,
     *   parent_code?: string,
     *   sort?: int,
     *   meta?: array<string, mixed>
     * } $task
     * @return array{
     *   code: string,
     *   title: string,
     *   tip: string,
     *   status: 'todo'|'doing'|'done',
     *   href: string,
     *   category: string,
     *   module: string,
     *   scenarios: list<'new'|'migrate'>,
     *   parent_code: string,
     *   sort: int,
     *   meta: array<string, mixed>
     * }|null
     */
    protected function task(array $task): ?array
    {
        $code = trim((string)($task['code'] ?? ''));
        $title = trim((string)($task['title'] ?? ''));
        if ($code === '' || $title === '') {
            return null;
        }
        $status = (string)($task['status'] ?? 'todo');
        if (!in_array($status, ['todo', 'doing', 'done'], true)) {
            $status = 'todo';
        }
        $scenarios = $task['scenarios'] ?? ['new', 'migrate'];
        if (!is_array($scenarios) || $scenarios === []) {
            $scenarios = ['new', 'migrate'];
        }
        $scenarios = array_values(array_filter(
            $scenarios,
            static fn($s): bool => in_array((string)$s, ['new', 'migrate'], true)
        ));
        if ($scenarios === []) {
            $scenarios = ['new', 'migrate'];
        }

        return [
            'code' => $code,
            'title' => $title,
            'tip' => (string)($task['tip'] ?? ''),
            'status' => $status,
            'href' => (string)($task['href'] ?? ''),
            'category' => (string)($task['category'] ?? ''),
            'module' => (string)($task['module'] ?? ''),
            'scenarios' => $scenarios,
            'parent_code' => trim((string)($task['parent_code'] ?? '')),
            'sort' => (int)($task['sort'] ?? 100),
            'meta' => is_array($task['meta'] ?? null) ? $task['meta'] : [],
        ];
    }

    /**
     * @param list<array|null> $tasks
     * @return list<array<string, mixed>>
     */
    protected function tasks(array $tasks): array
    {
        $out = [];
        foreach ($tasks as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = isset($row['code']) ? $this->task($row) : null;
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * @param array{website_id?:int,website_code?:string,storage_scope?:string} $context
     */
    protected function resolveStorageScope(array $context): string
    {
        $explicit = trim((string)($context['storage_scope'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $code = trim((string)($context['website_code'] ?? ''));
        if ($code === '' || $code === 'default') {
            $websiteId = (int)($context['website_id'] ?? 0);
            if ($websiteId > 0) {
                try {
                    /** @var Website $website */
                    $website = ObjectManager::getInstance(Website::class);
                    $website->load($websiteId);
                    $code = trim((string)$website->getCode());
                } catch (\Throwable) {
                    $code = '';
                }
            }
        }
        if ($code === '' || $code === 'default') {
            return ConfigReader::SCOPE_GLOBAL;
        }

        return $code . '.default.default';
    }

    protected function backendPath(string $action): string
    {
        $action = ltrim($action, '/');
        $prefix = '';
        try {
            $env = \Weline\Framework\App\Env::getInstance()->getConfig('router')['area_routes']['backend']['prefix'] ?? '';
            $prefix = is_string($env) ? trim($env, '/') : '';
        } catch (\Throwable) {
            $prefix = '';
        }

        return $prefix !== '' ? '/' . $prefix . '/' . $action : '/' . $action;
    }

    /**
     * 统一配置中心深链（guide_key 高亮）。
     */
    protected function systemConfigPath(
        string $module,
        string $area,
        string $guideKey,
        string $title = '',
    ): string {
        $q = http_build_query(array_filter([
            'module' => $module,
            'area' => $area,
            'guide_key' => $guideKey,
            'guide_title' => $title !== '' ? $title : null,
        ], static fn($v) => $v !== null && $v !== ''));

        return $this->backendPath('weline_systemconfig/backend/config?' . $q);
    }

    /**
     * 读取 SystemConfig 字符串值（失败返回空串）。
     */
    protected function configString(
        string $module,
        string $area,
        string $key,
        ?string $scope = null,
    ): string {
        try {
            /** @var ConfigReader $reader */
            $reader = ObjectManager::getInstance(ConfigReader::class);
            $val = $reader->getConfig(
                $key,
                $module,
                $area,
                null,
                $scope ?? ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
            );

            return trim((string)($val ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param list<string> $keys
     */
    protected function anyConfigFilled(
        string $module,
        string $area,
        array $keys,
        ?string $scope = null,
    ): bool {
        foreach ($keys as $key) {
            $val = $this->configString($module, $area, (string)$key, $scope);
            if ($val !== '' && $val !== '0' && strtolower($val) !== 'false') {
                return true;
            }
        }

        return false;
    }

    /**
     * 带继承链的配置解析（站点无自有行时回落到全局；无库值时用 $default）。
     *
     * @return array{
     *   value: mixed,
     *   found: bool,
     *   inherited: bool,
     *   from_default: bool,
     *   source_scope: string,
     *   requested_scope: string
     * }
     */
    protected function resolveConfigProvenance(
        string $module,
        string $area,
        string $key,
        ?string $scope = null,
        mixed $default = null,
    ): array {
        $requested = $scope ?? ConfigReader::SCOPE_GLOBAL;
        try {
            /** @var \Weline\SystemConfig\Api\ConfigStore $store */
            $store = ObjectManager::getInstance(\Weline\SystemConfig\Api\ConfigStore::class);
            $resolved = $store->resolveConfig(
                $key,
                $module,
                $area,
                $requested,
                ConfigReader::LOCALE_DEFAULT,
                $default,
            );
            $found = !empty($resolved['found']);
            $sourceScope = '';
            if ($found && is_array($resolved['source'] ?? null)) {
                $sourceScope = (string)($resolved['source']['scope'] ?? '');
            }
            $inherited = $found && $sourceScope !== '' && $sourceScope !== (string)($resolved['requested_scope'] ?? $requested);

            return [
                'value' => $resolved['value'] ?? $default,
                'found' => $found,
                'inherited' => $inherited,
                'from_default' => !$found,
                'source_scope' => $sourceScope,
                'requested_scope' => (string)($resolved['requested_scope'] ?? $requested),
            ];
        } catch (\Throwable) {
            return [
                'value' => $default,
                'found' => false,
                'inherited' => false,
                'from_default' => true,
                'source_scope' => '',
                'requested_scope' => $requested,
            ];
        }
    }

    protected function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value !== 0.0;
        }
        $s = strtolower(trim((string)$value));

        return $s !== '' && !in_array($s, ['0', 'false', 'off', 'no', 'disabled', 'null'], true);
    }

    /**
     * 启用类开关：继承全局或应用默认已开 → true。
     */
    protected function isEffectivelyEnabled(
        string $module,
        string $area,
        string $key,
        ?string $scope = null,
        bool $default = false,
    ): bool {
        $prov = $this->resolveConfigProvenance($module, $area, $key, $scope, $default);

        return $this->isTruthy($prov['value']);
    }

    /**
     * 任一 key 在继承链（含默认）上为真。
     *
     * @param list<string> $keys
     * @param array<string, mixed> $defaultsByKey
     */
    protected function anyConfigEffectivelyTruthy(
        string $module,
        string $area,
        array $keys,
        ?string $scope = null,
        array $defaultsByKey = [],
    ): bool {
        foreach ($keys as $key) {
            $key = (string)$key;
            $default = $defaultsByKey[$key] ?? null;
            $prov = $this->resolveConfigProvenance($module, $area, $key, $scope, $default);
            if ($this->isTruthy($prov['value'])) {
                return true;
            }
        }

        return false;
    }
}
