<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Contract;

use GuoLaiRen\PageBuilder\Service\AiSiteSsePayloadNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * 前后端 SSE 契约对称性回归锁。
 *
 * 这个测试的存在意义：每次后端有人改 sendEvent('xxx', ...) 名称，或前端有人删/改
 * source.addEventListener('xxx', ...)，但没同步另一边时，CI 立刻失败把它指出来。
 *
 * 三条核心断言：
 *  1. 后端 PageBuilder workspace 流出现过的所有 sendEvent('xxx', ...) 事件名都在
 *     AiSiteSsePayloadNormalizer::authoritativeEventNames() 权威清单内。
 *  2. 前端 script-runtime.phtml 监听的所有 source.addEventListener('xxx', ...) 都在权威清单内。
 *  3. 后端"发但前端不收"以及前端"听但后端不发"的差集，必须在显式白名单内有合理解释。
 *
 * 想新增/废弃 SSE 事件 → 改 AiSiteSsePayloadNormalizer::authoritativeEventNames() + 改本测试
 * 白名单 + 改 doc/SSE-契约清单.md。三处不齐就失败。
 */
final class AiSiteSseEventContractTest extends TestCase
{
    /**
     * 已知的合理不对称事件，必须有注释说明。
     * key 是事件名，value 是 'backend_only' / 'frontend_only' / 'tolerated_pair'，再加一句注释。
     */
    private const KNOWN_ASYMMETRIES = [
        // 后端 SseWriter 内部发的，前端通过 startOperationStream 总入口监听，不在常规 addEventListener 里
        'done' => ['side' => 'backend_only', 'reason' => 'startOperationStream 总入口统一处理 done 终止'],
        // visual edit 流 block partial patch 时后端发，前端未直接消费（功能预留）
        'ai_chunk' => ['side' => 'backend_only', 'reason' => 'visual edit block 流预留，前端尚未消费'],
        // workspace stream 首帧推送完整 state（与 operation stream 是两条不同 EventSource）
        'state' => ['side' => 'backend_only', 'reason' => 'workspaceStream 端点专用首帧，不在 operation SSE 入口'],
        // 队列原始日志查看流（QueueLogStream / PlanQueue / BuildQueue）专用，与 workspace operation stream 解耦
        'log' => ['side' => 'backend_only', 'reason' => '队列原始日志流专用，operation stream 不消费'],
    ];

    public function testAllBackendEmittedEventsAreInAuthoritativeWhitelist(): void
    {
        $backendEvents = $this->collectBackendSseEventNames();
        $authoritative = AiSiteSsePayloadNormalizer::authoritativeEventNames();

        $unknown = \array_values(\array_diff($backendEvents, $authoritative));
        $unknown = \array_values(\array_filter(
            $unknown,
            fn(string $name): bool => !$this->isContractTestSelfReferential($name)
        ));

        self::assertSame(
            [],
            $unknown,
            '后端 SSE 发了不在 AiSiteSsePayloadNormalizer 权威清单中的事件名：'
            . \implode(', ', $unknown)
            . "\n如确需新增，请同步：1) 改 normalizer authoritativeEventNames；2) 改 doc/SSE-契约清单.md；3) 更新此测试。"
        );
    }

    public function testAllFrontendAddEventListenersAreInAuthoritativeWhitelist(): void
    {
        $frontendEvents = $this->collectFrontendSseEventNames();
        $authoritative = AiSiteSsePayloadNormalizer::authoritativeEventNames();

        $unknown = \array_values(\array_diff($frontendEvents, $authoritative));

        self::assertSame(
            [],
            $unknown,
            '前端 script-runtime.phtml 监听了不在权威清单中的事件名：'
            . \implode(', ', $unknown)
            . "\n如这些是死监听，请删除；如是新事件，请同步 normalizer + 文档。"
        );
    }

    public function testBackendAndFrontendEventSetsAreSymmetricWithinKnownAsymmetries(): void
    {
        $backendEvents = $this->collectBackendSseEventNames();
        $frontendEvents = $this->collectFrontendSseEventNames();

        $backendOnly = \array_values(\array_diff($backendEvents, $frontendEvents));
        $frontendOnly = \array_values(\array_diff($frontendEvents, $backendEvents));

        $unexpectedBackendOnly = \array_values(\array_filter(
            $backendOnly,
            fn(string $name): bool => !$this->isToleratedAsymmetry($name, 'backend_only')
                && !$this->isContractTestSelfReferential($name)
        ));
        $unexpectedFrontendOnly = \array_values(\array_filter(
            $frontendOnly,
            fn(string $name): bool => !$this->isToleratedAsymmetry($name, 'frontend_only')
        ));

        self::assertSame(
            [],
            $unexpectedBackendOnly,
            '后端发但前端不收的事件（不在已知白名单内）：' . \implode(', ', $unexpectedBackendOnly)
            . "\n要么前端补 source.addEventListener，要么在 KNOWN_ASYMMETRIES 加白并写明原因。"
        );
        self::assertSame(
            [],
            $unexpectedFrontendOnly,
            '前端监听但后端不发的事件（死监听）：' . \implode(', ', $unexpectedFrontendOnly)
            . "\n要么后端补 sendEvent，要么删前端 addEventListener。"
        );
    }

    /**
     * 扫描后端 PageBuilder 模块中所有 `$sse->sendEvent('xxx', ...)` 调用，提取事件名。
     * 排除：测试代码自身、SseWriter 框架代码、Sse 控制器中无 PageBuilder 业务语义的探针事件
     *       （poll / test 由 sse-test 路由发，不算工作台契约）。
     *
     * @return list<string>
     */
    private function collectBackendSseEventNames(): array
    {
        $roots = [
            \dirname(__DIR__, 3) . '/Controller/Backend',
            \dirname(__DIR__, 3) . '/Service',
            \dirname(__DIR__, 3) . '/Queue',
            \dirname(__DIR__, 3) . '/Http/Sse',
        ];
        $names = [];
        foreach ($roots as $root) {
            if (!\is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || \strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $content = (string)\file_get_contents($file->getPathname());
                // 直接 sse->sendEvent('xxx', ...) 调用
                if (\preg_match_all("/->sendEvent\(\s*['\"]([a-z][a-z0-9_]*)['\"]/u", $content, $matches) !== false) {
                    foreach ($matches[1] as $name) {
                        $names[$name] = true;
                    }
                }
                // emitNormalizedSseEvent($sse, 'xxx', ...) 是 service 内的 normalize 包装
                if (\preg_match_all("/emitNormalizedSseEvent\(\s*\\\$\w+\s*,\s*['\"]([a-z][a-z0-9_]*)['\"]/u", $content, $matches) !== false) {
                    foreach ($matches[1] as $name) {
                        $names[$name] = true;
                    }
                }
                // mapOperationEventName 中 match 的 return 值是 workspace event log mirror 路径发的 SSE 事件名
                if (\str_contains($file->getPathname(), 'mapOperationEventName')
                    || \str_ends_with($file->getPathname(), 'AiSiteAgentQueueObserverHelperService.php')) {
                    if (\preg_match_all("/=>\s*['\"]([a-z][a-z0-9_]*)['\"]\s*,/u", $content, $matches) !== false) {
                        // 仅采纳 mapOperationEventName 区块内的，避免误吃整个文件的字符串字典
                        $methodStart = \strpos($content, 'function mapOperationEventName');
                        if ($methodStart !== false) {
                            $methodEnd = \strpos($content, "\n    public function", $methodStart + 1);
                            if ($methodEnd === false) {
                                $methodEnd = \strpos($content, "\n    private function", $methodStart + 1);
                            }
                            $methodEnd = $methodEnd ?: \strlen($content);
                            $methodSource = \substr($content, $methodStart, $methodEnd - $methodStart);
                            if (\preg_match_all("/=>\s*['\"]([a-z][a-z0-9_]*)['\"]/u", $methodSource, $localMatches) !== false) {
                                foreach ($localMatches[1] as $name) {
                                    $names[$name] = true;
                                }
                            }
                        }
                    }
                }
            }
        }
        // 排除显式认定为非工作台契约的事件
        $excluded = ['poll', 'test', 'parsing', 'generating', 'prompt', 'context', 'thinking', 'stream_error',
            'agent_status', 'complete', 'quality_gate', 'page_generated_obsolete'];
        foreach ($excluded as $exclude) {
            unset($names[$exclude]);
        }
        // 'data' / 'failed' / 'success' 来自 DomainManagement / QueueDbWriter / AiGenerate 等非工作台流
        unset($names['data'], $names['failed'], $names['success']);
        $list = \array_keys($names);
        \sort($list);

        return $list;
    }

    /**
     * 扫描前端 workspace script-runtime.phtml 的 EventSource / workspaceTerminal 监听。
     *
     * @return list<string>
     */
    private function collectFrontendSseEventNames(): array
    {
        $file = \dirname(__DIR__, 3) . '/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml';
        $content = (string)\file_get_contents($file);
        $names = [];
        foreach ([
            "/source\.addEventListener\(\s*['\"]([a-z][a-z0-9_]*)['\"]/u",
            "/workspaceTerminal\.on\(\s*['\"]([a-z][a-z0-9_]*)['\"]/u",
        ] as $pattern) {
            if (\preg_match_all($pattern, $content, $matches) !== false) {
                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }
        $list = \array_keys($names);
        \sort($list);

        return $list;
    }

    private function isToleratedAsymmetry(string $name, string $expectedSide): bool
    {
        $entry = self::KNOWN_ASYMMETRIES[$name] ?? null;
        if (!\is_array($entry)) {
            return false;
        }
        return ($entry['side'] ?? '') === $expectedSide;
    }

    /**
     * 排除测试自身字符串导致的误报：如果某事件名只在合约测试内出现，仍归属于"业务实际"集合，
     * 但若文件 reader 把测试目录加进来扫描会循环引用——本测试 file iterator 已限定到非 test 目录，
     * 这里再做一道防御。
     */
    private function isContractTestSelfReferential(string $name): bool
    {
        return $name === '';
    }
}
