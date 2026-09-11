<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

/**
 * Human-readable cache invalidation summary for save/rollback success feedback.
 *
 * Keeps toast scannable: short title + short line-broken details (no jargon pile-up).
 */
final class ConfigCacheInvalidationFeedback
{
    /**
     * @param list<string> $namespaces
     * @param list<array{pool?:string,keys?:list<string>}> $cacheOps
     * @return array{
     *   namespaces:list<string>,
     *   namespace_labels:list<string>,
     *   friendly_labels:list<string>,
     *   pools:list<string>,
     *   cache_key_count:int,
     *   changed_key_count:int,
     *   summary:string,
     *   title:string,
     *   body:string,
     *   detail_lines:list<string>
     * }
     */
    public function build(array $namespaces, array $cacheOps, int $changedKeyCount = 0): array
    {
        $namespaces = $this->stringList($namespaces);
        $labels = [];
        $friendly = [];
        foreach ($namespaces as $ns) {
            $label = $this->labelForNamespace($ns);
            $labels[] = $label;
            $human = $this->friendlyLabel($label);
            if ($human !== '') {
                $friendly[$human] = $human;
            }
        }
        $labels = $this->stringList($labels);
        $friendly = array_values($friendly);
        sort($friendly, SORT_STRING);

        $pools = [];
        $keyCount = 0;
        foreach ($cacheOps as $op) {
            if (!is_array($op)) {
                continue;
            }
            $pool = trim((string)($op['pool'] ?? ''));
            if ($pool !== '') {
                $pools[$pool] = $pool;
            }
            $keys = $op['keys'] ?? null;
            if (is_array($keys)) {
                $keyCount = max($keyCount, count($keys));
            }
        }
        $pools = array_values($pools);
        sort($pools, SORT_STRING);

        $detailLines = $this->formatDetailLines($friendly, $keyCount, max(0, $changedKeyCount));
        $summary = implode(' ', $detailLines);

        return [
            'namespaces' => $namespaces,
            'namespace_labels' => $labels,
            'friendly_labels' => $friendly,
            'pools' => $pools,
            'cache_key_count' => $keyCount,
            'changed_key_count' => max(0, $changedKeyCount),
            'summary' => $summary,
            'title' => (string)__('配置已保存'),
            'body' => implode("\n", $detailLines),
            'detail_lines' => $detailLines,
        ];
    }

    /**
     * @param array<string, mixed> $invalidation from build()
     * @return array{title:string,body:string,message:string}
     */
    public function formatSaveParts(
        string|int|null $versionId,
        array $invalidation,
        bool $importedOAuthJson = false,
    ): array {
        $version = trim((string)($versionId ?? ''));
        if ($importedOAuthJson) {
            $title = (string)__('已导入 Google OAuth 并保存');
        } else {
            $title = trim((string)($invalidation['title'] ?? '')) !== ''
                ? (string)$invalidation['title']
                : (string)__('配置已保存');
        }

        $lines = [];
        if ($version !== '') {
            $lines[] = (string)__('版本批次 %{1}', [$version]);
        }
        $detailLines = is_array($invalidation['detail_lines'] ?? null)
            ? array_values(array_filter(array_map('strval', $invalidation['detail_lines'])))
            : [];
        if ($detailLines === []) {
            $summary = trim((string)($invalidation['summary'] ?? ''));
            if ($summary !== '') {
                $detailLines = [$summary];
            }
        }
        foreach ($detailLines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $body = implode("\n", $lines);
        $message = $body === '' ? $title : ($title . "\n" . $body);

        return [
            'title' => $title,
            'body' => $body,
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $invalidation from build()
     */
    public function formatSaveMessage(string|int|null $versionId, array $invalidation, bool $importedOAuthJson = false): string
    {
        return $this->formatSaveParts($versionId, $invalidation, $importedOAuthJson)['message'];
    }

    /**
     * @param array<string, mixed> $invalidation from build()
     * @return array{title:string,body:string,message:string}
     */
    public function formatRollbackParts(string|int|null $rollbackVersionId, array $invalidation): array
    {
        $title = (string)__('配置已回滚');
        $version = trim((string)($rollbackVersionId ?? ''));
        $lines = [];
        if ($version !== '') {
            $lines[] = (string)__('回滚批次 %{1}', [$version]);
        }
        foreach ((array)($invalidation['detail_lines'] ?? []) as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $body = implode("\n", $lines);

        return [
            'title' => $title,
            'body' => $body,
            'message' => $body === '' ? $title : ($title . "\n" . $body),
        ];
    }

    /**
     * @param array<string, mixed> $invalidation from build()
     */
    public function formatRollbackMessage(string|int|null $rollbackVersionId, array $invalidation): string
    {
        return $this->formatRollbackParts($rollbackVersionId, $invalidation)['message'];
    }

    /**
     * @param list<string> $friendlyLabels
     * @return list<string>
     */
    private function formatDetailLines(array $friendlyLabels, int $keyCount, int $changedKeyCount): array
    {
        $lines = [];
        if ($friendlyLabels !== []) {
            $shown = array_slice($friendlyLabels, 0, 3);
            $extra = count($friendlyLabels) - count($shown);
            $list = implode('、', $shown);
            if ($extra > 0) {
                $list .= (string)__(' 等 %{1} 项', [(string)count($friendlyLabels)]);
            }
            $lines[] = (string)__('已刷新缓存：%{1}', [$list]);
        }
        if ($keyCount > 0) {
            if ($changedKeyCount > 0) {
                $lines[] = (string)__('已清理配置快照约 %{1} 项（变更 %{2} 个字段）', [
                    (string)$keyCount,
                    (string)$changedKeyCount,
                ]);
            } else {
                $lines[] = (string)__('已清理配置快照约 %{1} 项', [(string)$keyCount]);
            }
        }
        if ($lines === []) {
            $lines[] = (string)__('已清理本模块配置请求缓存');
        }

        return $lines;
    }

    private function friendlyLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '' || str_starts_with($label, 'system-config')) {
            return '';
        }
        if ($label === 'storefront/captcha' || str_ends_with($label, '/captcha')) {
            return (string)__('人机验证');
        }
        if ($label === 'storefront/auth' || str_ends_with($label, '/auth')) {
            return (string)__('登录认证');
        }
        if ($label === 'storefront/config' || $label === 'storefront') {
            return (string)__('店面配置');
        }
        if ($label === 'storefront/theme' || str_ends_with($label, '/theme')) {
            return (string)__('主题');
        }
        if ($label === 'storefront/price' || str_ends_with($label, '/price')) {
            return (string)__('价格');
        }

        return $label;
    }

    private function labelForNamespace(string $namespace): string
    {
        $namespace = trim($namespace);
        if (str_starts_with($namespace, 'global/')) {
            return substr($namespace, strlen('global/'));
        }
        if (str_starts_with($namespace, 'website/')) {
            return $namespace;
        }

        return $namespace;
    }

    /** @return list<string> */
    private function stringList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $out[$value] = $value;
            }
        }
        $out = array_values($out);
        sort($out, SORT_STRING);
        return $out;
    }
}
