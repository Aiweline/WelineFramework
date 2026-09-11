<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader as SystemConfig;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * 配置范围级「已发现/自定义」事件池：扩大搭接下拉，不写回仓库字典。
 * 按 storage_scope 隔离（网站/店铺/渠道主题不同，自定义事件禁止跨范围共用）。
 */
final class DiscoveredEventService
{
    public const MODULE = 'Weline_Visitor';
    public const KEY_PREFIX = 'visitor/tracking/discovered_events.';

    public function __construct(
        private readonly ?EventDictionaryService $dictionary = null,
        private readonly ?SystemConfig $systemConfig = null,
        private readonly ?ConfigStore $configStore = null,
    ) {
    }

    /**
     * 规范化范围键：优先 storage_scope；空则回落 website.{id}。
     */
    public function scopeKeyFromStorage(string $storageScope, int $websiteId = 0): string
    {
        $s = \strtolower(\trim($storageScope));
        $s = \preg_replace('/[^a-z0-9._-]+/', '_', $s) ?: '';
        if ($s !== '') {
            return 'scope.' . $s;
        }

        return 'website.' . \max(0, $websiteId);
    }

    private function configKey(string $scopeKey): string
    {
        return self::KEY_PREFIX . $scopeKey;
    }

    /**
     * 旧版仅 websiteId 的 key（兼容读取）。
     */
    private function legacyWebsiteKey(int $websiteId): string
    {
        return self::KEY_PREFIX . \max(0, $websiteId);
    }

    /**
     * @return list<string>
     */
    private function readRawList(string $configKey): array
    {
        $raw = '';
        try {
            $raw = (string)$this->config()->get(
                $configKey,
                self::MODULE,
                SystemConfig::area_BACKEND,
                '[]',
                SystemConfig::SCOPE_GLOBAL
            );
        } catch (\Throwable) {
            $raw = '[]';
        }
        $decoded = \json_decode($raw !== '' ? $raw : '[]', true);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $name) {
            $n = $this->dictionary()->normalizeEventName((string)$name);
            if ($n !== '' && !\in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $list
     */
    private function writeRawList(string $configKey, array $list): bool
    {
        try {
            return $this->store()->setScopedConfig(
                $configKey,
                \json_encode(\array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                self::MODULE,
                SystemConfig::area_BACKEND,
                SystemConfig::SCOPE_GLOBAL,
                SystemConfig::LOCALE_DEFAULT
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 是否网站级范围（无独立 store/channel 段时允许读旧 website 池作迁移）。
     */
    private function isWebsiteLevelStorage(string $storageScope): bool
    {
        $s = \strtolower(\trim($storageScope));
        if ($s === '') {
            return true;
        }
        $parts = \explode('.', $s);

        // website.store.channel → 三段；仅网站时常为 1～2 段或 store/channel 为空占位
        if (\count($parts) >= 3) {
            $store = $parts[1] ?? '';
            $channel = $parts[2] ?? '';

            return ($store === '' || $store === 'default') && ($channel === '' || $channel === 'default');
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function list(int $websiteId, string $storageScope = ''): array
    {
        $scopeKey = $this->scopeKeyFromStorage($storageScope, $websiteId);
        $out = $this->readRawList($this->configKey($scopeKey));
        // 仅网站级：兼容旧 key visitor/tracking/discovered_events.{websiteId}
        if ($out === [] && $this->isWebsiteLevelStorage($storageScope)) {
            $out = $this->readRawList($this->legacyWebsiteKey($websiteId));
        }

        return $out;
    }

    public function add(int $websiteId, string $eventName, string $storageScope = ''): bool
    {
        $n = $this->dictionary()->normalizeEventName($eventName);
        if ($n === '') {
            return false;
        }
        $scopeKey = $this->scopeKeyFromStorage($storageScope, $websiteId);
        $configKey = $this->configKey($scopeKey);
        $list = $this->readRawList($configKey);
        // 网站级首次写入：把旧池并入新 key，避免迁移后丢失
        if ($list === [] && $this->isWebsiteLevelStorage($storageScope)) {
            $list = $this->readRawList($this->legacyWebsiteKey($websiteId));
        }
        if (\in_array($n, $list, true)) {
            return true;
        }
        $list[] = $n;

        return $this->writeRawList($configKey, $list);
    }

    /**
     * 从本范围自定义池移除事件名（不跨范围；系统字典项不会出现在自定义列表）。
     */
    public function remove(int $websiteId, string $eventName, string $storageScope = ''): bool
    {
        $n = $this->dictionary()->normalizeEventName($eventName);
        if ($n === '') {
            return false;
        }
        $scopeKey = $this->scopeKeyFromStorage($storageScope, $websiteId);
        $configKey = $this->configKey($scopeKey);
        $list = $this->readRawList($configKey);
        $fromLegacy = false;
        if ($list === [] && $this->isWebsiteLevelStorage($storageScope)) {
            $list = $this->readRawList($this->legacyWebsiteKey($websiteId));
            $fromLegacy = $list !== [];
        }
        if (!\in_array($n, $list, true)) {
            return true;
        }
        $next = \array_values(\array_filter($list, static fn(string $item): bool => $item !== $n));
        $ok = $this->writeRawList($configKey, $next);
        if ($ok && ($fromLegacy || $this->isWebsiteLevelStorage($storageScope))) {
            $legacy = $this->readRawList($this->legacyWebsiteKey($websiteId));
            if ($legacy !== []) {
                $legacyNext = \array_values(\array_filter($legacy, static fn(string $item): bool => $item !== $n));
                $this->writeRawList($this->legacyWebsiteKey($websiteId), $legacyNext);
            }
        }

        return $ok;
    }

    /**
     * 仅自定义（不在系统字典内）的事件名列表。
     *
     * @return list<array{name: string, label_zh: string, event_family: string, ga4_event: string, custom: bool}>
     */
    public function listCustomEvents(int $websiteId, string $storageScope = ''): array
    {
        $system = [];
        foreach ($this->dictionary()->getEvents() as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $n = $this->dictionary()->normalizeEventName((string)($entry['weline_event'] ?? ''));
            if ($n !== '') {
                $system[$n] = true;
            }
        }
        $out = [];
        foreach ($this->list($websiteId, $storageScope) as $name) {
            if (isset($system[$name])) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'label_zh' => $name . '（自定义）',
                'event_family' => 'custom',
                'ga4_event' => $name,
                'custom' => true,
            ];
        }

        return $out;
    }

    /**
     * 字典可搭接项 + 本范围已发现自定义项（自定义带 label「自定义」）。
     * 自定义不跨范围继承。
     *
     * @return list<array{name: string, label_zh: string, event_family: string, ga4_event: string, custom?: bool}>
     */
    public function listMappableWithDiscovered(int $websiteId, string $storageScope = ''): array
    {
        $base = $this->dictionary()->listMappableEvents();
        $known = [];
        foreach ($base as $row) {
            $known[$row['name']] = true;
        }
        foreach ($this->list($websiteId, $storageScope) as $name) {
            if (isset($known[$name])) {
                continue;
            }
            $base[] = [
                'name' => $name,
                'label_zh' => $name . '（自定义）',
                'event_family' => 'custom',
                'ga4_event' => $name,
                'custom' => true,
            ];
            $known[$name] = true;
        }

        return $base;
    }

    private function dictionary(): EventDictionaryService
    {
        return $this->dictionary ?? ObjectManager::getInstance(EventDictionaryService::class);
    }

    private function config(): SystemConfig
    {
        return $this->systemConfig ?? ObjectManager::getInstance(SystemConfig::class);
    }

    private function store(): ConfigStore
    {
        return $this->configStore ?? ObjectManager::getInstance(ConfigStore::class);
    }
}
