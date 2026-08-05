<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Phrase;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StateManager;

/**
 * Phrase 事件词典（请求级）
 *
 * 通过 `Weline_Framework_Phrase::dictionary_collect` 允许模块向当前请求贡献词典层；
 * 组装完成后派发 `Weline_Framework_Phrase::dictionary_ready_after`；
 * 缺词时派发 `Weline_Framework_Phrase::word_missing`（同一请求同一缺词只通知一次）。
 *
 * 硬约束：
 * - 事件词典只存在于请求作用域（Fiber/Request Context），不进入按 locale+modules 区分的全局 Phrase 缓存。
 * - 存在 exclusive 独占层时，不加载模块 CSV、全局语言包或数据库全局词典。
 * - 两个不同 owner 同时声明独占视为作用域冲突：开发环境抛异常，生产环境记录错误并返回原文。
 * - Observer 异常不能影响页面输出（ready_after / word_missing 均被隔离）。
 * - 词典提供方建立/清理自己的请求上下文后调用 refresh()，下一次 Phrase 调用重新收集。
 */
class EventDictionary
{
    public const EVENT_DICTIONARY_COLLECT = 'Weline_Framework_Phrase::dictionary_collect';
    public const EVENT_DICTIONARY_READY_AFTER = 'Weline_Framework_Phrase::dictionary_ready_after';
    public const EVENT_WORD_MISSING = 'Weline_Framework_Phrase::word_missing';

    public const MODE_OVERLAY = 'overlay';
    public const MODE_EXCLUSIVE = 'exclusive';

    private const CONTEXT_STATE_KEY = 'phrase.event_dictionary.state';
    private const CONTEXT_MISSING_KEY = 'phrase.event_dictionary.missing_reported';
    private const MISSING_REPORT_MAX_PER_REQUEST = 512;

    /** 非持久运行时（CLI/FPM 单请求）回退存储 */
    private static ?array $fallbackState = null;
    private static array $fallbackMissingReported = [];

    /** 收集重入保护：收集过程中的 __() 走原有词典路径 */
    private static bool $collecting = false;

    private static bool $stateRegistered = false;

    private static function ensureStateRegistered(): void
    {
        if (self::$stateRegistered) {
            return;
        }
        self::$stateRegistered = true;
        StateManager::registerResetCallback('Phrase::EventDictionary::reset', static function () {
            self::$fallbackState = null;
            self::$fallbackMissingReported = [];
            self::$collecting = false;
        });
    }

    /**
     * 当前请求事件词典是否处于激活状态（存在至少一个词典层）。
     *
     * 首次调用触发 dictionary_collect 收集；同一请求作用域只收集一次。
     */
    public static function isActive(string $locale): bool
    {
        $state = self::currentState($locale);
        return (bool)($state['active'] ?? false);
    }

    public static function isExclusive(string $locale): bool
    {
        $state = self::currentState($locale);
        return ($state['mode'] ?? '') === self::MODE_EXCLUSIVE;
    }

    /**
     * 事件词典按原文翻译；未命中返回 null。
     */
    public static function translate(string $word, string $locale): ?string
    {
        $state = self::currentState($locale);
        if (!($state['active'] ?? false)) {
            return null;
        }
        $translated = $state['words'][$word] ?? null;
        return (\is_string($translated) && $translated !== '') ? $translated : null;
    }

    /**
     * 事件词典按 entry_key 精确翻译；未命中返回 null。
     */
    public static function translateKey(string $entryKey, string $locale): ?string
    {
        $state = self::currentState($locale);
        if (!($state['active'] ?? false)) {
            return null;
        }
        $translated = $state['keyed_words'][$entryKey] ?? null;
        return (\is_string($translated) && $translated !== '') ? $translated : null;
    }

    /**
     * 词典提供方（如 PageBuilder 页面控制器）建立或清理请求词典上下文后调用：
     * 丢弃本请求已组装的事件词典，下一次 Phrase 调用重新触发 dictionary_collect。
     */
    public static function refresh(): void
    {
        self::ensureStateRegistered();
        if (RequestContext::getId() !== null) {
            RequestContext::remove(self::CONTEXT_STATE_KEY);
            RequestContext::remove(self::CONTEXT_MISSING_KEY);
            return;
        }
        self::$fallbackState = null;
        self::$fallbackMissingReported = [];
    }

    /**
     * 上报缺词（同一请求同一缺词只通知一次；Observer 异常不影响输出）。
     */
    public static function reportMissing(string $sourceText, ?string $entryKey, string $locale): void
    {
        $state = self::currentState($locale);
        if (!($state['active'] ?? false)) {
            return;
        }

        $missingKey = ($entryKey !== null && $entryKey !== '') ? 'k:' . $entryKey : 'w:' . $sourceText;
        $reported = self::getMissingReported();
        if (isset($reported[$missingKey]) || \count($reported) >= self::MISSING_REPORT_MAX_PER_REQUEST) {
            return;
        }
        $reported[$missingKey] = true;
        self::setMissingReported($reported);

        try {
            $eventData = [
                'request_id' => (string)(RequestContext::getId() ?? ''),
                'locale' => (string)($state['locale'] ?? $locale),
                'scope_key' => (string)($state['scope_key'] ?? ''),
                'source_text' => $sourceText,
                'entry_key' => $entryKey,
                'owners' => (array)($state['owners'] ?? []),
            ];
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventsManager->dispatch(self::EVENT_WORD_MISSING, $eventData);
        } catch (\Throwable $throwable) {
            self::logWarning('word_missing observer failed: ' . $throwable->getMessage());
        }
    }

    /**
     * 获取（必要时收集）当前请求事件词典状态。
     *
     * @return array{
     *     active: bool,
     *     mode: string,
     *     scope_key: string,
     *     locale: string,
     *     owners: array,
     *     words: array<string,string>,
     *     keyed_words: array<string,string>,
     *     layer_hashes: array<string,string>
     * }
     */
    public static function currentState(string $locale): array
    {
        self::ensureStateRegistered();

        if (self::$collecting) {
            return self::inactiveState($locale);
        }

        $stored = self::getStoredState();
        if (\is_array($stored) && ($stored['locale'] ?? null) === $locale) {
            return $stored;
        }

        self::$collecting = true;
        try {
            $state = self::collect($locale);
        } finally {
            self::$collecting = false;
        }
        self::setStoredState($state);

        if ($state['active']) {
            self::dispatchReadyAfter($state);
        }

        return $state;
    }

    /**
     * 派发 dictionary_collect 并组装词典层。
     */
    private static function collect(string $locale): array
    {
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            if (!$eventsManager->hasObservers(self::EVENT_DICTIONARY_COLLECT)) {
                return self::inactiveState($locale);
            }
            $eventData = [
                'request_id' => (string)(RequestContext::getId() ?? ''),
                'locale' => $locale,
                'modules' => Parser::resolveRequestModules(),
                'layers' => [],
            ];
            $eventsManager->dispatch(self::EVENT_DICTIONARY_COLLECT, $eventData);
        } catch (\Throwable $throwable) {
            self::logWarning('dictionary_collect dispatch failed: ' . $throwable->getMessage());
            return self::inactiveState($locale);
        }

        $layers = [];
        foreach ((array)($eventData['layers'] ?? []) as $layer) {
            if (!\is_array($layer)) {
                continue;
            }
            $owner = \trim((string)($layer['owner'] ?? ''));
            if ($owner === '') {
                continue;
            }
            $mode = (string)($layer['mode'] ?? self::MODE_OVERLAY);
            if (!\in_array($mode, [self::MODE_OVERLAY, self::MODE_EXCLUSIVE], true)) {
                $mode = self::MODE_OVERLAY;
            }
            $layers[] = [
                'owner' => $owner,
                'name' => (string)($layer['name'] ?? ''),
                'scope_key' => (string)($layer['scope_key'] ?? ''),
                'mode' => $mode,
                'priority' => (int)($layer['priority'] ?? 0),
                'hash' => (string)($layer['hash'] ?? ''),
                'words' => \is_array($layer['words'] ?? null) ? $layer['words'] : [],
                'keyed_words' => \is_array($layer['keyed_words'] ?? null) ? $layer['keyed_words'] : [],
                'error' => $layer['error'] ?? null,
            ];
        }

        if ($layers === []) {
            return self::inactiveState($locale);
        }

        // 独占层判定与作用域冲突检查
        $exclusiveOwners = [];
        foreach ($layers as $layer) {
            if ($layer['mode'] === self::MODE_EXCLUSIVE) {
                $exclusiveOwners[$layer['owner']] = true;
            }
        }
        $mode = $exclusiveOwners !== [] ? self::MODE_EXCLUSIVE : self::MODE_OVERLAY;
        if (\count($exclusiveOwners) > 1) {
            $conflictMessage = 'Phrase event dictionary exclusive scope conflict between owners: '
                . \implode(', ', \array_keys($exclusiveOwners));
            if (\defined('DEV') && DEV) {
                throw new \RuntimeException($conflictMessage);
            }
            self::logWarning($conflictMessage);
            // 生产环境：记录错误并返回原文（空独占词典，禁止回退全局词典）
            return [
                'active' => true,
                'mode' => self::MODE_EXCLUSIVE,
                'scope_key' => '',
                'locale' => $locale,
                'owners' => \array_keys($exclusiveOwners),
                'words' => [],
                'keyed_words' => [],
                'layer_hashes' => [],
            ];
        }

        // 独占模式下仅保留独占 owner 的层，防止其他 overlay owner 注入词条
        if ($mode === self::MODE_EXCLUSIVE) {
            $exclusiveOwner = \array_key_first($exclusiveOwners);
            $layers = \array_values(\array_filter(
                $layers,
                static fn(array $layer): bool => $layer['owner'] === $exclusiveOwner
            ));
        }

        // 按 priority 升序合并：高优先级层（数值大）后合并覆盖低优先级层
        \usort($layers, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $words = [];
        $keyedWords = [];
        $layerHashes = [];
        $owners = [];
        $scopeKey = '';
        foreach ($layers as $layer) {
            foreach ($layer['words'] as $source => $translated) {
                if (\is_string($source) && $source !== '' && \is_string($translated) && $translated !== '') {
                    $words[$source] = $translated;
                }
            }
            foreach ($layer['keyed_words'] as $entryKey => $translated) {
                if (\is_string($entryKey) && $entryKey !== '' && \is_string($translated) && $translated !== '') {
                    $keyedWords[$entryKey] = $translated;
                }
            }
            if ($layer['name'] !== '') {
                $layerHashes[$layer['name']] = $layer['hash'];
            }
            $owners[$layer['owner']] = true;
            if ($layer['scope_key'] !== '') {
                // 最高优先级层的 scope_key 作为请求词典作用域标识
                $scopeKey = $layer['scope_key'];
            }
        }

        return [
            'active' => true,
            'mode' => $mode,
            'scope_key' => $scopeKey,
            'locale' => $locale,
            'owners' => \array_keys($owners),
            'words' => $words,
            'keyed_words' => $keyedWords,
            'layer_hashes' => $layerHashes,
        ];
    }

    private static function dispatchReadyAfter(array $state): void
    {
        try {
            $eventData = [
                'request_id' => (string)(RequestContext::getId() ?? ''),
                'locale' => (string)($state['locale'] ?? ''),
                'scope_key' => (string)($state['scope_key'] ?? ''),
                'mode' => (string)($state['mode'] ?? ''),
                'owners' => (array)($state['owners'] ?? []),
                'layer_hashes' => (array)($state['layer_hashes'] ?? []),
                'word_count' => \count((array)($state['words'] ?? [])),
                'keyed_word_count' => \count((array)($state['keyed_words'] ?? [])),
            ];
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventsManager->dispatch(self::EVENT_DICTIONARY_READY_AFTER, $eventData);
        } catch (\Throwable $throwable) {
            // 诊断事件不允许影响页面输出
            self::logWarning('dictionary_ready_after observer failed: ' . $throwable->getMessage());
        }
    }

    private static function inactiveState(string $locale): array
    {
        return [
            'active' => false,
            'mode' => '',
            'scope_key' => '',
            'locale' => $locale,
            'owners' => [],
            'words' => [],
            'keyed_words' => [],
            'layer_hashes' => [],
        ];
    }

    private static function getStoredState(): ?array
    {
        if (RequestContext::getId() !== null) {
            $state = RequestContext::get(self::CONTEXT_STATE_KEY);
            return \is_array($state) ? $state : null;
        }
        return self::$fallbackState;
    }

    private static function setStoredState(array $state): void
    {
        if (RequestContext::getId() !== null) {
            RequestContext::set(self::CONTEXT_STATE_KEY, $state);
            return;
        }
        self::$fallbackState = $state;
    }

    private static function getMissingReported(): array
    {
        if (RequestContext::getId() !== null) {
            $reported = RequestContext::get(self::CONTEXT_MISSING_KEY);
            return \is_array($reported) ? $reported : [];
        }
        return self::$fallbackMissingReported;
    }

    private static function setMissingReported(array $reported): void
    {
        if (RequestContext::getId() !== null) {
            RequestContext::set(self::CONTEXT_MISSING_KEY, $reported);
            return;
        }
        self::$fallbackMissingReported = $reported;
    }

    private static function logWarning(string $message): void
    {
        if (\function_exists('w_log_warning')) {
            \w_log_warning('[Phrase] ' . $message, [], 'phrase');
        }
    }
}
