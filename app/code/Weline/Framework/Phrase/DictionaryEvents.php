<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * Phrase 词典维护冷路径：登记、批量翻译、编译。
 * Framework 只 dispatch 契约，不写 DB、不调 AI。
 */
final class DictionaryEvents
{
    public const EVENT_DICTIONARY_COMPILE = 'Weline_Framework_Phrase::dictionary_compile';
    public const EVENT_DICTIONARY_COMPILE_AFTER = 'Weline_Framework_Phrase::dictionary_compile_after';
    public const EVENT_DICTIONARY_REGISTER = 'Weline_Framework_Phrase::dictionary_register';
    public const EVENT_DICTIONARY_TRANSLATE = 'Weline_Framework_Phrase::dictionary_translate';

    /**
     * @param list<array{word: string, translate: string, module?: string, locale?: string, is_backend?: int}> $entries
     */
    public static function register(array $entries, ?string $module = null): void
    {
        if ($entries === []) {
            return;
        }

        $payload = [
            'entries' => $entries,
            'translations' => $entries,
            'module' => $module,
        ];
        self::dispatch(self::EVENT_DICTIONARY_REGISTER, $payload);
    }

    /**
     * @param array{
     *     source_locale?: string,
     *     entries?: list<array{word: string, translate?: string}>,
     *     word_prefix?: string,
     *     publish?: bool,
     *     write_csv?: bool,
     *     batch_size?: int,
     *     strategy?: string,
     *     owner?: string,
     *     word_filter?: list<string>,
     *     words?: list<string>,
     *     allow_key_only_words?: bool,
     *     skip_lock?: bool,
     * } $options
     * @return array<string, mixed>
     */
    public static function translate(string $module, string $targetLocale, array $options = []): array
    {
        $payload = array_merge([
            'module' => $module,
            'target_locale' => $targetLocale,
            'publish' => true,
            'write_csv' => false,
        ], $options);

        self::dispatch(self::EVENT_DICTIONARY_TRANSLATE, $payload);

        return (array)($payload['result'] ?? [
            'success' => true,
            'translated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function compile(?string $module = null): array
    {
        /** @var DictionaryCompiler $compiler */
        $compiler = ObjectManager::getInstance(DictionaryCompiler::class);

        return $compiler->compile($module, false);
    }

    private static function dispatch(string $eventName, array &$payload): void
    {
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch($eventName, $payload);
    }
}
