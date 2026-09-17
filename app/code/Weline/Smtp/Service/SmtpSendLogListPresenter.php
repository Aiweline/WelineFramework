<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

/**
 * 发件记录列表/详情展示整形（人性化收件、时间、语言）。
 */
final class SmtpSendLogListPresenter
{
    /**
     * @param array<string, mixed> $log
     * @param array<string, string> $channelNameMap
     * @return array<string, mixed>
     */
    public function presentRow(array $log, array $channelNameMap = []): array
    {
        $code = \trim((string)($log['channel'] ?? ''));
        $log['channel_label'] = $code === ''
            ? (string)\__('未标注渠道')
            : (string)($channelNameMap[$code] ?? $code);
        $logScope = \trim((string)($log['storage_scope'] ?? ''));
        $log['scope_label'] = $logScope === '' || $logScope === 'default.default.default'
            ? (string)\__('Global')
            : $logScope;
        $log['to_display'] = $this->formatRecipients((string)($log['to_email'] ?? ''));
        $log['locale_display'] = $this->formatLocale((string)($log['locale'] ?? ''));
        $log['time_display'] = $this->formatTime((string)($log['create_time'] ?? ''));
        $log['from_display'] = \trim((string)($log['from_email'] ?? ''));
        $senderName = \trim((string)($log['sender_name'] ?? ''));
        if ($senderName !== '' && $senderName !== $log['from_display']) {
            $log['from_meta'] = $senderName;
        } else {
            $log['from_meta'] = \trim((string)($log['sender_code'] ?? ''));
        }
        $log['module_display'] = \trim((string)($log['module'] ?? ''));
        $log['is_html_flag'] = ((string)($log['is_html'] ?? '1')) === '1';
        $log['content_excerpt'] = $this->buildContentExcerpt((string)($log['content'] ?? ''));

        return $log;
    }

    /**
     * 列表用纯文本摘录：去标签、折叠空白、截断。禁止把 HTML 正文塞进表格。
     */
    public function buildContentExcerpt(string $content, int $maxLen = 120): string
    {
        $text = \strip_tags($content);
        $text = \html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = \preg_replace('/\s+/u', ' ', $text);
        $text = \trim(\is_string($collapsed) ? $collapsed : $text);
        if ($text === '') {
            return '—';
        }
        if (\mb_strlen($text) > $maxLen) {
            return \mb_substr($text, 0, $maxLen) . '…';
        }

        return $text;
    }

    public function formatRecipients(string $raw): string
    {
        $raw = \trim($raw);
        if ($raw === '' || $raw === '[]') {
            return '—';
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            // Plain email or comma list
            return $raw;
        }
        $emails = [];
        foreach ($decoded as $item) {
            if (\is_string($item) && \trim($item) !== '') {
                $emails[] = \trim($item);
                continue;
            }
            if (!\is_array($item)) {
                continue;
            }
            $email = \trim((string)($item['email'] ?? $item[0] ?? ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }
        $emails = \array_values(\array_unique($emails));

        return $emails === [] ? '—' : \implode(', ', $emails);
    }

    public function formatLocale(string $locale): string
    {
        $locale = \trim($locale);
        if ($locale === '' || \strtolower($locale) === 'default') {
            return '—';
        }

        return $locale;
    }

    public function formatTime(string $time): string
    {
        $time = \trim($time);
        if ($time === '') {
            return '—';
        }
        // Strip microseconds: 2026-09-14 19:23:09.818889 → 2026-09-14 19:23:09
        if (\preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $time, $m) === 1) {
            return $m[1];
        }

        return $time;
    }
}
