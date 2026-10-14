<?php

declare(strict_types=1);

namespace Weline\I18n\Service\InlineEdit;

use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;

/**
 * Storefront inline-edit phrase markers.
 *
 * Runtime marking always uses the HTML-safe attribute protocol
 * [[W18N:src_b64url:tr_b64url]]. Custom-element wrapBody() must not be used for
 * emit()/__() — theme entities often compile <lang> inside title/aria-label with
 * emit(..., false); body tags inside quoted attributes split the markup.
 */
final class InlinePhraseMarker
{
    public const ATTR_PREFIX = '[[W18N:';
    public const ATTR_SUFFIX = ']]';

    public static function isSessionActive(): bool
    {
        try {
            /** @var InlineEditSession $session */
            $session = ObjectManager::getInstance(InlineEditSession::class);

            return $session->isActive();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Translate for JSON / operator chrome without [[W18N]] / <w-i18n-phrase> wraps.
     */
    public static function plain(string $source): string
    {
        return (string)\Weline\Framework\Phrase\Parser::withoutInlinePhraseMark(
            static fn(): string => (string)__($source)
        );
    }

    public static function encodeB64Url(string $raw): string
    {
        return \rtrim(\strtr(\base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decodeB64Url(string $encoded): string
    {
        $pad = 4 - (\strlen($encoded) % 4);
        if ($pad < 4) {
            $encoded .= \str_repeat('=', $pad);
        }
        $bin = \base64_decode(\strtr($encoded, '-_', '+/'), true);

        return $bin === false ? '' : $bin;
    }

    /**
     * Explicit custom-element wrap (tests / opt-in only). Unsafe inside HTML attributes.
     */
    public static function wrapBody(string $source, string $translated): string
    {
        $locale = (string)State::getLangLocal();
        $srcEsc = \htmlspecialchars($source, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $locEsc = \htmlspecialchars($locale, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        // Translated text is already storefront-safe from Phrase; escape to keep markup intact.
        $trEsc = \htmlspecialchars($translated, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return '<w-i18n-phrase data-source="' . $srcEsc . '" data-locale="' . $locEsc . '">'
            . $trEsc
            . '</w-i18n-phrase>';
    }

    /**
     * Peel nested/polluted [[W18N:…]] until plain text (dictionary may have stored markers).
     */
    public static function unwrapPlain(string $text): string
    {
        $out = (string)$text;
        for ($guard = 0; $guard < 8; $guard++) {
            if (!\str_contains($out, self::ATTR_PREFIX)) {
                break;
            }
            $next = \preg_replace_callback(
                '/\[\[W18N:([A-Za-z0-9_-]+):([A-Za-z0-9_-]+)\]\]/',
                static function (array $m): string {
                    $tr = self::decodeB64Url($m[2]);
                    $src = self::decodeB64Url($m[1]);

                    return $tr !== '' ? $tr : $src;
                },
                $out
            );
            if (!\is_string($next) || $next === $out) {
                break;
            }
            $out = $next;
        }

        return $out;
    }

    public static function wrapAttribute(string $source, string $translated): string
    {
        // Never nest: source/translate must be plain before encoding.
        $source = self::unwrapPlain($source);
        $translated = self::unwrapPlain($translated);

        return self::ATTR_PREFIX
            . self::encodeB64Url($source)
            . ':'
            . self::encodeB64Url($translated)
            . self::ATTR_SUFFIX;
    }

    /**
     * Runtime helper for __() / @lang / <lang> output (body or attribute).
     * Always attribute-protocol — HTML-safe when Taglib leaves quotes around emit(false).
     */
    public static function body(string $source, string $translated): string
    {
        $translated = self::unwrapPlain($translated);
        if (!self::isSessionActive()) {
            return $translated;
        }

        return self::wrapAttribute($source, $translated);
    }

    /**
     * Runtime helper for attribute contexts (@lang in title/alt/…).
     * Translates without nesting marks, then applies [[W18N:…]] when session active.
     */
    public static function attribute(string $source, string $translated = ''): string
    {
        $source = self::unwrapPlain((string)$source);
        if ($translated === '') {
            $translated = (string)\Weline\Framework\Phrase\Parser::withoutInlinePhraseMark(
                static fn(): string => (string)__($source)
            );
        }
        $translated = self::unwrapPlain($translated);
        if (!self::isSessionActive()) {
            return $translated;
        }

        return self::wrapAttribute($source, $translated);
    }

    /**
     * @lang / <lang> compile target. $forAttribute kept for Taglib call sites;
     * both paths use the same HTML-safe marker protocol.
     */
    public static function emit(string $source, bool $forAttribute = false): string
    {
        // Signature kept for Taglib emit(..., true|false); both are HTML-safe markers.
        return self::attribute($source);
    }
}
