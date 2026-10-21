<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Service;

/**
 * 发布门禁「跳过预算」。
 *
 * Playwright 的 `test.skip(true, reason)` 在列表报告里只体现为 `2 skipped`，
 * 门禁很容易被条件跳过悄悄放水（例如主题没注入 CTA 就跳过整条 UI 通路）。
 * 这里把 JSON 报告里的跳过项解析出来，只允许清单 `allowed_skips` 里显式声明的
 * 用例跳过；出现新的静默跳过就让门禁失败。
 */
final class E2eGateSkipBudget
{
    /**
     * 从 Playwright JSON 报告递归收集跳过项。
     *
     * @param array<string, mixed> $report
     * @return list<array{case:string, title:string, reason:string}>
     */
    public static function collectSkips(array $report): array
    {
        $skips = [];
        foreach ((array)($report['suites'] ?? []) as $suite) {
            if (\is_array($suite)) {
                self::walkSuite($suite, $skips);
            }
        }

        return $skips;
    }

    /**
     * @param array<string, mixed> $suite
     * @param list<array{case:string, title:string, reason:string}> $skips
     */
    private static function walkSuite(array $suite, array &$skips): void
    {
        foreach ((array)($suite['specs'] ?? []) as $spec) {
            if (!\is_array($spec)) {
                continue;
            }
            $reason = self::specSkipReason($spec);
            if ($reason === null) {
                continue;
            }
            $title = (string)($spec['title'] ?? '');
            $skips[] = [
                'case' => self::extractCaseId($title),
                'title' => $title,
                'reason' => $reason,
            ];
        }

        foreach ((array)($suite['suites'] ?? []) as $child) {
            if (\is_array($child)) {
                self::walkSuite($child, $skips);
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function specSkipReason(array $spec): ?string
    {
        $tests = (array)($spec['tests'] ?? []);
        $skipped = false;
        $reason = '';
        foreach ($tests as $test) {
            if (!\is_array($test)) {
                continue;
            }
            $status = (string)($test['status'] ?? '');
            $expected = (string)($test['expectedStatus'] ?? '');
            if ($status !== 'skipped' && $expected !== 'skipped') {
                continue;
            }
            $skipped = true;
            foreach ((array)($test['annotations'] ?? []) as $annotation) {
                if (!\is_array($annotation)) {
                    continue;
                }
                if ((string)($annotation['type'] ?? '') === 'skip') {
                    $reason = (string)($annotation['description'] ?? '');
                    if ($reason !== '') {
                        break 2;
                    }
                }
            }
        }

        return $skipped ? ($reason !== '' ? $reason : '(未写原因)') : null;
    }

    public static function extractCaseId(string $title): string
    {
        if (\preg_match('/\[case:([A-Za-z0-9_.\-]+)\]/', $title, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * @param list<array{case:string, title:string, reason:string}> $skips
     * @param array<string, string> $allowed  caseId => 允许理由
     * @return array{
     *   declared: list<array{case:string, title:string, reason:string}>,
     *   undeclared: list<array{case:string, title:string, reason:string}>
     * }
     */
    public static function partition(array $skips, array $allowed): array
    {
        $declared = [];
        $undeclared = [];
        foreach ($skips as $skip) {
            $case = (string)($skip['case'] ?? '');
            if ($case !== '' && \array_key_exists($case, $allowed)) {
                $declared[] = $skip;
                continue;
            }
            $undeclared[] = $skip;
        }

        return ['declared' => $declared, 'undeclared' => $undeclared];
    }

    /**
     * 读取清单里的 allowed_skips（支持 list<string> 与 list<{case,reason}>）。
     *
     * @param array<string, mixed> $manifest
     * @return array<string, string>
     */
    public static function allowedFromManifest(array $manifest): array
    {
        $allowed = [];
        foreach ((array)($manifest['allowed_skips'] ?? []) as $entry) {
            if (\is_string($entry) && \trim($entry) !== '') {
                $allowed[\trim($entry)] = '';
                continue;
            }
            if (!\is_array($entry)) {
                continue;
            }
            $case = \trim((string)($entry['case'] ?? $entry['id'] ?? ''));
            if ($case !== '') {
                $allowed[$case] = (string)($entry['reason'] ?? '');
            }
        }

        return $allowed;
    }
}
