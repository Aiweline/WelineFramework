<?php

declare(strict_types=1);

namespace Weline\Mail\Service\Mailbox;

/**
 * Mail reader body presentation: keep plain text for reply/quote, expose sanitized HTML for preview.
 * HTML preview MUST be rendered in a sandboxed iframe without allow-scripts.
 *
 * Thread history uses compact separator lines (── 引用 · … ──) instead of sparse nested blanks.
 */
final class MailBodyPresentation
{
    private const BODY_LIMIT = 100000;

    /** Visible separator glyph for quoted history blocks. */
    public const THREAD_SEP = '──';

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
     * Compact plain quote for reply/forward drafts (separator + body, no sparse blanks).
     *
     * @param array{from?:string,received_at?:string,sent_at?:string,subject?:string,body?:string,preview?:string,html_body?:string} $message
     */
    public static function gmailQuotePlain(array $message): string
    {
        $from = trim((string)($message['from'] ?? ''));
        $dateRaw = trim((string)($message['received_at'] ?? $message['sent_at'] ?? ''));
        $date = self::formatCompactDate($dateRaw);
        $body = trim((string)($message['body'] ?? ''));
        if ($body === '') {
            $html = trim((string)($message['html_body'] ?? ''));
            $body = $html !== '' ? self::htmlToPlainText($html) : trim((string)($message['preview'] ?? ''));
        }
        $body = self::compactPlainBody(self::normalizePlainThread($body));
        $who = $from !== '' ? $from : 'Unknown';
        $sep = self::formatSepLabel($date, $who, '');

        return "\n\n" . $sep . "\n" . self::prefixQuoteLines($body);
    }

    /**
     * Compact HTML quote block under the inline composer.
     *
     * @param array{from?:string,received_at?:string,sent_at?:string,body?:string,preview?:string,html_body?:string} $message
     */
    public static function gmailQuoteHtml(array $message): string
    {
        $from = trim((string)($message['from'] ?? ''));
        $dateRaw = trim((string)($message['received_at'] ?? $message['sent_at'] ?? ''));
        $date = self::formatCompactDate($dateRaw);
        $who = $from !== '' ? $from : 'Unknown';
        $sep = self::formatSepLabel($date, $who, '');

        $plain = trim((string)($message['body'] ?? ''));
        if ($plain === '') {
            $html = trim((string)($message['html_body'] ?? ''));
            $plain = $html !== '' && !self::isOurThreadMarkup($html)
                ? (self::looksLikeHtml($html) ? self::htmlToPlainText($html) : $html)
                : trim((string)($message['preview'] ?? ''));
        }
        $plain = self::compactPlainBody(self::normalizePlainThread($plain));

        return self::sanitizeHtml(
            '<div class="mail-thread mail-thread--quote" data-mail-thread="1">'
            . '<div class="mail-thread__sep">' . htmlspecialchars($sep, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>'
            . '<div class="mail-thread__part mail-thread__part--quoted">'
            . self::escapeBodyHtml($plain)
            . '</div></div>'
        );
    }

    public static function isOurThreadMarkup(string $html): bool
    {
        return str_contains($html, 'mail-thread') || str_contains($html, 'gmail_quote');
    }

    /**
     * Detect flat legacy separators / >-quoted threads that need compact presentation.
     */
    public static function looksThreadedPlain(string $plain): bool
    {
        $plain = self::normalizePlainThread($plain);
        if ($plain === '') {
            return false;
        }
        if (preg_match('/^>/m', $plain) === 1) {
            return true;
        }
        if (preg_match('/On .{8,80} wrote:/i', $plain) === 1) {
            return true;
        }
        if (str_contains($plain, self::THREAD_SEP)) {
            return true;
        }

        return preg_match(
            '/[-—_]{3,}\s*(?:原始邮件|转发的邮件|Replied Message|Forwarded message)\s*[-—_]{3,}/iu',
            $plain
        ) === 1;
    }

    /**
     * Convert flat/legacy plain threads into compact separator HTML for the reader.
     */
    public static function plainThreadToGmailHtml(string $plain): string
    {
        $plain = self::normalizePlainThread($plain);
        if ($plain === '') {
            return '';
        }

        $parts = preg_split(
            '/\s*[-—_]{3,}\s*(?:原始邮件|转发的邮件|Replied Message|Forwarded message)\s*[-—_]{3,}\s*/iu',
            $plain
        );
        if (!is_array($parts)) {
            $parts = [$plain];
        }

        // Also split on our own compact separators if re-rendering.
        if (count($parts) < 2 && str_contains($plain, self::THREAD_SEP)) {
            $parts = preg_split(
                '/\n*\s*' . preg_quote(self::THREAD_SEP, '/') . '\s*引用[^\n]*'
                . preg_quote(self::THREAD_SEP, '/') . '\s*\n*/u',
                $plain
            ) ?: [$plain];
        }

        if (count($parts) < 2) {
            $body = self::compactPlainBody($plain);
            // Still try to fold QQ header blobs inside a single segment.
            $parsed = self::parseLegacyQuotedSegment($body);
            if ($parsed['attr_date'] !== '' || $parsed['attr_from'] !== '') {
                $sep = self::formatSepLabel($parsed['attr_date'], $parsed['attr_from'], $parsed['attr_subject']);
                $content = self::compactPlainBody($parsed['body']);

                return '<div class="mail-thread" data-mail-thread="1">'
                    . '<div class="mail-thread__sep">' . htmlspecialchars($sep, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>'
                    . '<div class="mail-thread__part mail-thread__part--quoted">'
                    . self::escapeBodyHtml($content)
                    . '</div></div>';
            }

            return '<div class="mail-thread" data-mail-thread="1"><div class="mail-thread__part">'
                . self::escapeBodyHtml($body)
                . '</div></div>';
        }

        $chunks = [];
        foreach ($parts as $i => $segment) {
            $segment = trim((string)$segment);
            if ($segment === '') {
                continue;
            }
            if ($i === 0) {
                $chunks[] = '<div class="mail-thread__part">'
                    . self::escapeBodyHtml(self::compactPlainBody($segment))
                    . '</div>';
                continue;
            }
            $parsed = self::parseLegacyQuotedSegment($segment);
            $sep = self::formatSepLabel($parsed['attr_date'], $parsed['attr_from'], $parsed['attr_subject']);
            $chunks[] = '<div class="mail-thread__sep">'
                . htmlspecialchars($sep, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '</div>';
            $chunks[] = '<div class="mail-thread__part mail-thread__part--quoted">'
                . self::escapeBodyHtml(self::compactPlainBody($parsed['body']))
                . '</div>';
        }

        return '<div class="mail-thread" data-mail-thread="1">' . implode('', $chunks) . '</div>';
    }

    /**
     * @return array{body: string, html_body: string, body_is_html: bool}
     */
    private static function pack(string $text, string $html): array
    {
        $html = trim($html);
        $text = trim($text);
        // Some clients put plain/thread text into htmlBody without real markup.
        if ($html !== '' && !self::looksLikeHtml($html)) {
            if ($text === '') {
                $text = $html;
            }
            $html = '';
        }
        if ($html !== '') {
            $plain = $text !== '' ? self::limitText($text, self::BODY_LIMIT) : self::htmlToPlainText($html);
            if (self::looksThreadedPlain($plain) && !self::isOurThreadMarkup($html)) {
                return [
                    'body' => $plain,
                    'html_body' => self::sanitizeHtml(self::plainThreadToGmailHtml($plain)),
                    'body_is_html' => true,
                ];
            }

            return [
                'body' => $plain,
                'html_body' => self::sanitizeHtml($html),
                'body_is_html' => true,
            ];
        }

        $plain = self::limitText($text, self::BODY_LIMIT);
        if (self::looksThreadedPlain($plain)) {
            return [
                'body' => $plain,
                'html_body' => self::sanitizeHtml(self::plainThreadToGmailHtml($plain)),
                'body_is_html' => true,
            ];
        }

        return [
            'body' => $plain,
            'html_body' => '',
            'body_is_html' => false,
        ];
    }

    /**
     * @return array{attr_from:string,attr_date:string,attr_subject:string,body:string}
     */
    private static function parseLegacyQuotedSegment(string $segment): array
    {
        $segment = self::foldHeaderLabelValueLines($segment);
        $from = '';
        $date = '';
        $subject = '';
        $body = $segment;

        if (preg_match('/^[ \t]*(?:发件人|From)\s*[:：]\s*(.+)$/imu', $segment, $m) === 1) {
            $from = trim($m[1]);
        }
        if (preg_match('/^[ \t]*(?:日期|Date)\s*[:：]\s*(.+)$/imu', $segment, $m) === 1) {
            $date = trim($m[1]);
        }
        if (preg_match('/^[ \t]*(?:主题|Subject)\s*[:：]\s*(.+)$/imu', $segment, $m) === 1) {
            $subject = trim($m[1]);
        }

        // Strip compacted header lines (and optional To).
        $stripped = preg_replace(
            '/^(?:[ \t]*(?:发件人|From|日期|Date|主题|Subject|收件人|To)\s*[:：]\s*[^\n]*\n?)+/iu',
            '',
            $segment
        );
        if (is_string($stripped) && trim($stripped) !== '') {
            $body = trim($stripped);
        }

        // Drop dangling signature-only email/name lines duplicated after body.
        $body = self::stripTrailingIdentityNoise($body, $from);

        return [
            'attr_from' => $from,
            'attr_date' => self::formatCompactDate($date),
            'attr_subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * Fold QQ-style "From\\n\\n&lt;email&gt;" into "From: &lt;email&gt;".
     */
    private static function foldHeaderLabelValueLines(string $segment): string
    {
        $segment = str_replace(["\r\n", "\r"], "\n", $segment);
        // Collapse runs of blank lines first so label/value are adjacent enough.
        $segment = preg_replace("/\n{2,}/u", "\n", $segment) ?? $segment;
        // QQ often indents labels ("        From"); allow leading whitespace.
        $segment = preg_replace(
            '/^[ \t]*(From|Date|To|Subject|发件人|日期|收件人|主题)[ \t]*\n[ \t]*(.+)$/imu',
            '$1: $2',
            $segment
        ) ?? $segment;

        return trim($segment);
    }

    private static function stripTrailingIdentityNoise(string $body, string $from): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        $lines = preg_split("/\n/u", $body) ?: [];
        while ($lines !== []) {
            $last = trim((string)end($lines));
            if ($last === '') {
                array_pop($lines);
                continue;
            }
            $isEmail = (bool)filter_var(trim($last, '<>'), FILTER_VALIDATE_EMAIL);
            $isBareName = $from !== '' && (
                strcasecmp($last, $from) === 0
                || str_contains(strtolower($from), strtolower($last))
            );
            if ($isEmail || $isBareName) {
                array_pop($lines);
                continue;
            }
            break;
        }

        return trim(implode("\n", $lines));
    }

    private static function compactPlainBody(string $plain): string
    {
        $plain = str_replace(["\r\n", "\r"], "\n", $plain);
        $plain = self::foldHeaderLabelValueLines($plain);
        // Drop leftover bare header keywords that never got values.
        $plain = preg_replace(
            '/^(From|Date|To|Subject|发件人|日期|收件人|主题)\s*$/imu',
            '',
            $plain
        ) ?? $plain;
        $plain = preg_replace("/[ \t]+/u", ' ', $plain) ?? $plain;
        $plain = preg_replace("/\n{2,}/u", "\n", $plain) ?? $plain;

        return trim($plain);
    }

    private static function escapeBodyHtml(string $plain): string
    {
        $plain = str_replace(["\r\n", "\r"], "\n", $plain);
        $lines = explode("\n", $plain);
        $out = [];
        foreach ($lines as $line) {
            $out[] = htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return implode('<br>', $out);
    }

    private static function formatSepLabel(string $date, string $from, string $subject): string
    {
        $bits = ['引用'];
        if ($date !== '') {
            $bits[] = $date;
        }
        if ($from !== '') {
            $bits[] = $from;
        } elseif ($subject !== '') {
            $bits[] = $subject;
        }

        return self::THREAD_SEP . ' ' . implode(' · ', $bits) . ' ' . self::THREAD_SEP;
    }

    private static function prefixQuoteLines(string $body): string
    {
        if ($body === '') {
            return '> ';
        }
        $lines = explode("\n", $body);
        $out = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '>')) {
                $out[] = '>' . $line;
            } else {
                $out[] = '> ' . $line;
            }
        }

        return implode("\n", $out);
    }

    private static function normalizePlainThread(string $plain): string
    {
        $plain = str_replace(["\r\n", "\r"], "\n", $plain);
        $plain = preg_replace(
            '/([^\n])\s*([-—_]{3,}\s*(?:原始邮件|转发的邮件|Replied Message|Forwarded message)\s*[-—_]{3,})\s*/iu',
            "$1\n$2\n",
            $plain
        ) ?? $plain;
        // Aggressive collapse before presentation (max one blank line).
        $plain = preg_replace("/\n{3,}/u", "\n\n", $plain) ?? $plain;

        return trim($plain);
    }

    private static function formatCompactDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            // Already human like 10/19/2026 14:31
            return $raw;
        }

        return $dt->format('Y-m-d H:i');
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
