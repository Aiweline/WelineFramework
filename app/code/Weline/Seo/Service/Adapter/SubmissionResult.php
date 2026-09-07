<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Adapter;

/** Provider acknowledgements describe receipt, never search indexing. */
final class SubmissionResult
{
    public static function urls(array $urls): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn($url): string => is_string($url) ? trim($url) : '', $urls
        ))));
    }

    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    public static function belongsToSite(string $url, string $site): bool
    {
        if (!self::validUrl($url) || !self::validUrl($site)) { return false; }
        $u = parse_url($url);
        $s = parse_url($site);
        $path = rtrim((string)($s['path'] ?? ''), '/');
        return strtolower($u['host']) === strtolower($s['host'])
            && strtolower($u['scheme']) === strtolower($s['scheme'])
            && ($u['port'] ?? null) === ($s['port'] ?? null)
            && ($path === '' || ($u['path'] ?? '/') === $path || str_starts_with($u['path'] ?? '/', $path . '/'));
    }

    public static function failure(string $message, string $status = 'failed'): array
    {
        return ['success' => false, 'accepted' => false, 'status' => $status, 'message' => $message];
    }

    public static function complete(int $total, array $submitted, array $pending = [], array $rejected = [], array $errors = [], array $extra = []): array
    {
        $received = count($submitted) + count($pending);
        $success = $total > 0 && count($submitted) === $total && $errors === [];
        $status = $success ? 'accepted' : ($received === 0 ? 'failed' : ($received < $total ? 'partial' : 'pending_verification'));
        return [
            'success' => $success,
            'accepted' => $received > 0,
            'status' => $status,
            'message' => __('提交结果：已接收 %{1}，待验证 %{2}，未接收 %{3}', [count($submitted), count($pending), max(0, $total - $received)]) . ($errors ? '；' . implode('；', $errors) : ''),
            'data' => array_merge($extra, [
                'submitted_urls' => count($submitted), 'pending_urls' => count($pending),
                'accepted_url_list' => array_merge($submitted, $pending),
                'rejected_urls' => array_values(array_unique($rejected)), 'errors' => $errors,
            ]),
        ];
    }
}
