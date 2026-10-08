<?php

declare(strict_types=1);

namespace Weline\Framework\View;

/**
 * Production com_* minify: collapse HTML whitespace outside PHP tags;
 * strip safe block comments inside PHP. Never touches string/heredoc contents.
 */
final class CompiledTemplateMinifier
{
    public static function shouldMinify(): bool
    {
        return !(\defined('DEV') && DEV);
    }

    public static function minify(string $compiled): string
    {
        if ($compiled === '' || !self::shouldMinify()) {
            return $compiled;
        }
        try {
            $tokens = \token_get_all($compiled);
        } catch (\Throwable) {
            return $compiled;
        }

        $out = '';
        $inPhp = false;
        foreach ($tokens as $token) {
            if (\is_array($token)) {
                [$id, $text] = [$token[0], $token[1]];
                if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                    $inPhp = true;
                    $out .= $text;
                    continue;
                }
                if ($id === T_CLOSE_TAG) {
                    $inPhp = false;
                    $out .= $text;
                    continue;
                }
                if ($inPhp) {
                    if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                        // Keep hash header + baked-hook markers for identity/acceptance.
                        if (\str_contains($text, 'hash:') || \str_contains($text, 'baked-hook:')) {
                            $out .= $text;
                        }
                        continue;
                    }
                    $out .= $text;
                    continue;
                }
                // HTML / text outside PHP
                $out .= self::collapseHtmlWhitespace($text);
                continue;
            }
            // single-char tokens
            $out .= $token;
            if ($token === '?' ) {
                // handled via T_CLOSE_TAG normally
            }
        }

        return $out !== '' ? $out : $compiled;
    }

    private static function collapseHtmlWhitespace(string $html): string
    {
        // Preserve pre/textarea/script/style content roughly by not minifying if present.
        if (\preg_match('/<(pre|textarea|script|style)\b/i', $html) === 1) {
            return $html;
        }
        $html = \preg_replace('/<!--(?!@\/?weline-slot:|baked-hook:)[\s\S]*?-->/', '', $html) ?? $html;
        $html = \preg_replace('/[ \t]+/', ' ', $html) ?? $html;
        $html = \preg_replace('/\n\s*\n+/', "\n", $html) ?? $html;

        return $html;
    }
}
