<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteSsePayloadNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * 锁住 AiSiteSsePayloadNormalizer 的归一化契约。
 *
 * 这是 PageBuilder AI 建站工作台所有 SSE payload 的统一过滤点：
 *  - 别名 → 权威：调用方误用 status / job_status / task_progress / build_task_summary 等老字段时，
 *    自动写入权威 queue_status / task_summary，避免老入口与新前端读不到。
 *  - 权威 → 别名（过渡期）：前端老读取点仍能从 alias 拿到正确数据。
 *  - queue_status 强制小写。
 *
 * 如果将来 AiSiteSsePayloadNormalizer::EMITTED_DEPRECATED_ALIASES 关掉，需要：
 *   1) 前端所有 fallback 已删除
 *   2) 本测试对应的 alias mirror 断言改为 assertArrayNotHasKey
 */
final class AiSiteSsePayloadNormalizerTest extends TestCase
{
    public function testNormalizeFillsQueueStatusFromStatusAlias(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $payload = $normalizer->normalize([
            'status' => 'Running',
            'message' => 'queue running',
        ]);

        // 权威字段被回填且小写化。
        self::assertSame('running', $payload['queue_status']);
        // 过渡期镜像：别名同样被强制小写。
        self::assertSame('running', $payload['status']);
        self::assertSame('running', $payload['job_status']);
    }

    public function testNormalizeFillsQueueStatusFromJobStatusAlias(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $payload = $normalizer->normalize([
            'job_status' => 'Done',
        ]);

        self::assertSame('done', $payload['queue_status']);
        self::assertSame('done', $payload['status']);
        self::assertSame('done', $payload['job_status']);
    }

    public function testNormalizeMirrorsAuthoritativeQueueStatusToAliases(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $payload = $normalizer->normalize([
            'queue_status' => 'Pending',
        ]);

        self::assertSame('pending', $payload['queue_status']);
        self::assertSame('pending', $payload['status']);
        self::assertSame('pending', $payload['job_status']);
    }

    public function testNormalizeFillsTaskSummaryFromTaskProgressAlias(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();
        $summary = ['total' => 12, 'done' => 4, 'failed' => 0];

        $payload = $normalizer->normalize([
            'task_progress' => $summary,
        ]);

        self::assertSame($summary, $payload['task_summary']);
        self::assertSame($summary, $payload['task_progress']);
        self::assertSame($summary, $payload['build_task_summary']);
    }

    public function testNormalizeMirrorsTaskSummaryToBothAliases(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();
        $summary = ['total' => 5, 'done' => 5, 'failed' => 0];

        $payload = $normalizer->normalize([
            'task_summary' => $summary,
        ]);

        self::assertSame($summary, $payload['task_summary']);
        self::assertSame($summary, $payload['task_progress']);
        self::assertSame($summary, $payload['build_task_summary']);
    }

    public function testNormalizeKeepsExistingAuthoritativeWhenAliasAlsoPresent(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $payload = $normalizer->normalize([
            'queue_status' => 'running',
            // 老入口同时塞了 alias 但值不一致；权威字段必须胜出，alias 被镜像覆盖。
            'status' => 'pending',
        ]);

        self::assertSame('running', $payload['queue_status']);
        self::assertSame('running', $payload['status']);
    }

    public function testNormalizeIsIdempotent(): void
    {
        $normalizer = new AiSiteSsePayloadNormalizer();

        $first = $normalizer->normalize([
            'queue_status' => 'running',
            'task_summary' => ['total' => 3],
            'operation' => 'build',
        ]);
        $second = $normalizer->normalize($first);

        self::assertSame($first, $second, 'normalize 必须幂等：第二次归一不应再改变结果');
    }

    public function testAuthoritativeEventNamesIsClosedWhitelist(): void
    {
        $events = AiSiteSsePayloadNormalizer::authoritativeEventNames();

        // 必含的最核心 7 个事件，缺一不可：
        $required = [
            'start', 'progress', 'chunk', 'done', 'error',
            'task_progress', 'task_failed',
        ];
        foreach ($required = $required as $name) {
            self::assertContains($name, $events, "SSE 权威事件清单缺失：{$name}");
        }

        // 不应允许任何空字符串或非小写下划线命名（防止后续误传）。
        foreach ($events as $name) {
            self::assertSame(
                $name,
                \strtolower($name),
                "事件名必须全小写下划线，违例：{$name}"
            );
            self::assertSame(
                1,
                \preg_match('/^[a-z][a-z0-9_]*$/', $name),
                "事件名格式不合规：{$name}"
            );
        }
    }

    public function testAliasMapPointsToAuthoritativeFields(): void
    {
        $authoritative = AiSiteSsePayloadNormalizer::authoritativePayloadFields();
        $aliasMap = AiSiteSsePayloadNormalizer::aliasToAuthoritativeMap();

        foreach ($aliasMap as $alias => $target) {
            self::assertContains(
                $target,
                $authoritative,
                "alias {$alias} 映射到非权威字段 {$target}"
            );
        }
    }
}
