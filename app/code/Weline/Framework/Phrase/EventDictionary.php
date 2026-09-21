<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Phrase;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StateManager;

/**
 * Phrase 请求级覆盖词典（只读消费）。
 *
 * 由调用方直接写入 RequestContext 状态（或 refresh 清空）；`__()` 只 peek。
 * 收词与 AI/文件发布走 i18n:collect 定时链路，与本类无关。
 */
class EventDictionary
{
    public const MODE_OVERLAY = 'overlay';
    public const MODE_EXCLUSIVE = 'exclusive';

    private const CONTEXT_STATE_KEY = 'phrase.event_dictionary.state';

    /** 非持久运行时（CLI/FPM 单请求）回退存储 */
    private static ?array $fallbackState = null;

    private static bool $stateRegistered = false;

    private static function ensureStateRegistered(): void
    {
        if (self::$stateRegistered) {
            return;
        }
        self::$stateRegistered = true;
        StateManager::registerResetCallback('Phrase::EventDictionary::reset', static function () {
            self::$fallbackState = null;
        });
    }

    public static function isActive(string $locale): bool
    {
        $state = self::peekState($locale);
        return (bool)($state['active'] ?? false);
    }

    public static function isExclusive(string $locale): bool
    {
        $state = self::peekState($locale);
        return ($state['mode'] ?? '') === self::MODE_EXCLUSIVE;
    }

    public static function translate(string $word, string $locale): ?string
    {
        $state = self::peekState($locale);
        if (!($state['active'] ?? false)) {
            return null;
        }
        $translated = $state['words'][$word] ?? null;
        return (\is_string($translated) && $translated !== '') ? $translated : null;
    }

    public static function translateKey(string $entryKey, string $locale): ?string
    {
        $state = self::peekState($locale);
        if (!($state['active'] ?? false)) {
            return null;
        }
        $translated = $state['keyed_words'][$entryKey] ?? null;
        return (\is_string($translated) && $translated !== '') ? $translated : null;
    }

    /** 清空本请求覆盖词典。 */
    public static function refresh(): void
    {
        self::ensureStateRegistered();
        if (RequestContext::getId() !== null) {
            RequestContext::remove(self::CONTEXT_STATE_KEY);
            return;
        }
        self::$fallbackState = null;
    }

    /**
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
    public static function peekState(string $locale): array
    {
        self::ensureStateRegistered();

        $stored = self::getStoredState();
        if (\is_array($stored) && ($stored['locale'] ?? null) === $locale) {
            return $stored;
        }

        return self::inactiveState($locale);
    }

    /** @deprecated 使用 peekState */
    public static function currentState(string $locale): array
    {
        return self::peekState($locale);
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
}
