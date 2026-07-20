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
        // Intentionally lazy: the request route and rendered module sources are
        // the authoritative preload manifest.
    }

    public static function clearWorkerCaches(): void
    {
        FrameworkPhraseParser::clearWorkerCaches();
    }
}
