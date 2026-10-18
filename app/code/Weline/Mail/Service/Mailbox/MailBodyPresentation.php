<?php

declare(strict_types=1);

namespace Weline\Mail\Service\Mailbox;

/**
 * Mail reader body presentation: keep plain text for reply/quote, expose sanitized HTML for preview.
 * HTML preview MUST be rendered in a sandboxed iframe without allow-scripts.
 */
final class MailBodyPresentation
{
    private const BODY_LIMIT = 100000;

    /**
     * @param array<string, mixed> $message JMAP Email/get payload
     * @return array{body: string, html_body: string, body_is_html: bool}
     */
    public static function fromJmapMessage(array $message, bool $includeBody): array
    {
        if (!$includeBody) {
            return ['body' => '', 'html_body' => '', 'body_is_html' => false];
        }

        $text = self::extractBodyType($message, 'textBody');
        $html = self::extractBodyType($message, 'htmlBody');
        // QQ/客户端有时把 HTML 整份塞进 text/plain；不能当纯文本留给阅读器/回复引用。
        if ($text !== '' && self::looksLikeHtml($text)) {
            if ($html === '') {
                $html = $text;
            }
            $text = '';
        }
        if ($html === '' && $text === '') {
            $preview = trim((string)($message['preview'] ?? ''));
            if ($preview !== '' && self::looksLikeHtml($preview)) {
                $html = $preview;
            } else {
                $text = $preview;
            }
        }

        return self::pack($text, $html);
    }

    /**
     * @return array{body: string, html_body: string, body_is_html: bool}
     */
    public static function fromStoredBody(string $stored, bool $includeBody): array
    {
        if (!$includeBody) {
            return ['body' => '', 'html_body' => '', 'body_is_html' => false];
        }
        $stored = trim($stored);
        if ($stored === '') {
            return ['body' => '', 'html_body' => '', 'body_is_html' => false];
        }
        if (self::looksLikeHtml($stored)) {
            return self::pack('', $stored);
        }

        return self::pack($stored, '');
    }

    public static function looksLikeHtml(string $value): bool
    {
        $sample = ltrim($value);
        if ($sample === '') {
            return false;
        }
        if (preg_match('/^(?:<!DOCTYPE\s+html\b|<html\b|<head\b|<body\b)/i', $sample) === 1) {
            return true;
        }

        return preg_match('/<(?:div|table|p|span|br|img|a|style|font|center|h[1-6])\b/i', $sample) === 1
            && preg_match('/<\/?[a-z][^>]*>/i', $sample) === 1;
    }

    public static function sanitizeHtml(string $html): string
    {
        $out = preg_replace('#<(script|iframe|object|embed|link|meta|base)\b[^>]*>.*?</\1>#is', '', $html);
        $out = is_string($out) ? $out : $html;
        $out = preg_replace('#<(script|iframe|object|embed|link|meta|base)\b[^>]*/?>#is', '', $out) ?? $out;
        $out = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/is', '', $out) ?? $out;
        $out = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/is', '', $out) ?? $out;
        $out = preg_replace('/\s+(href|src)\s*=\s*(["\'])\s*javascript\s*:.*?\2/is', ' $1="#"', $out) ?? $out;
        $out = preg_replace('/javascript\s*:/i', '', $out) ?? $out;

        return self::limitText($out, self::BODY_LIMIT);
    }

    public static function htmlToPlainText(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $html) ?? '';
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $html = preg_replace('~</p\s*>~i', "\n\n", $html) ?? $html;

        return self::limitText(
            html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            self::BODY_LIMIT
        );
    }

    /**
     * @return array{body: string, html_body: string, body_is_html: bool}
     */
    private static function pack(string $text, string $html): array
    {
        $html = trim($html);
        $text = trim($text);
        if ($html !== '') {
            $plain = $text !== '' ? self::limitText($text, self::BODY_LIMIT) : self::htmlToPlainText($html);

            return [
                'body' => $plain,
                'html_body' => self::sanitizeHtml($html),
                'body_is_html' => true,
            ];
        }

        return [
            'body' => self::limitText($text, self::BODY_LIMIT),
            'html_body' => '',
            'body_is_html' => false,
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function extractBodyType(array $message, string $bodyType): string
    {
        $bodyValues = is_array($message['bodyValues'] ?? null) ? $message['bodyValues'] : [];
        $chunks = [];
        foreach ((array)($message[$bodyType] ?? []) as $part) {
            if (!is_array($part)) {
                continue;
            }
            $partId = (string)($part['partId'] ?? '');
            $value = $partId !== '' && is_array($bodyValues[$partId] ?? null)
                ? (string)($bodyValues[$partId]['value'] ?? '')
                : '';
            if ($value !== '') {
                $chunks[] = $value;
            }
        }

        return trim(implode("\n\n", $chunks));
    }

    private static function limitText(string $text, int $limit): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{4,}/u", "\n\n\n", $text) ?? $text;
        $text = trim($text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }

        return substr($text, 0, $limit);
    }
}
