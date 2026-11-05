<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归 Aiweline 所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n;

use Weline\Framework\Phrase\Parser as FrameworkPhraseParser;

/**
 * I18n compatibility bridge.
 *
 * Runtime translation ownership lives in FrameworkPhraseParser so WLS has one
 * request-scoped dictionary pipeline. Keeping a second generated-language-file
 * cache here previously loaded every locale/module dictionary in every Worker.
 */
class Parser
{
    public static function parse(string $words, array $args): string
    {
        return (string)FrameworkPhraseParser::parse($words, $args);
    }

    public static function preloadWorkerDictionaries(): void
    {
        // The dictionary file layer is framework-owned: FrameworkPhraseParser
        // warms the bounded global layer at Worker boot and leaves module
        // dictionaries to the first request that renders them (route-scoped, for
        // RSS). This bridge owns nothing of its own; delegating keeps every
        // "preload dictionaries" entry point answering the same question instead
        // of silently doing nothing.
        FrameworkPhraseParser::preloadWorkerDictionaries();
    }

    public static function clearWorkerCaches(): void
    {
        FrameworkPhraseParser::clearWorkerCaches();
    }
}
