<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * D4（2026-11-01）：`s:up` 退出码语义。
 *
 * - 系统 + 主题都就绪 → 0；
 * - 主题世代推进失败 → **非零**，但站点继续服务旧版（active 不前进）。
 *
 * 观察者通过 payload 写回 `generation_promote_failed`；`Deploy\Upgrade::execute()`
 * 必须在派发后读取它并返回非零，否则就是"退出 0 但业务没生效"。
 */
final class DeployUpgradeExitCodeTest extends TestCase
{
    public function testUpgradeReadsGenerationPromoteFailure(): void
    {
        $src = (string)\file_get_contents(BP . 'app/code/Weline/Framework/Console/Console/Deploy/Upgrade.php');
        self::assertNotSame('', $src, 'Upgrade.php 不可读');

        self::assertMatchesRegularExpression(
            '/dispatch\(\s*[\'"]Weline_Framework_Deploy::upgrade_after[\'"]/',
            $src,
            '必须继续派发 upgrade_after'
        );
        self::assertStringContainsString(
            "generation_promote_failed",
            $src,
            'Upgrade 必须读取观察者写回的 generation_promote_failed'
        );
        // 只锁"失败分支里 return 非零"这个语义，不锁具体缩进/换行（源码格式化会漂）
        self::assertMatchesRegularExpression(
            '/if\s*\(!empty\(\$afterPayload\[\s*[\'"]generation_promote_failed[\'"]\s*\]\)\)\s*\{.*?return\s+1;/s',
            $src,
            '世代推进失败时 execute() 必须返回非零'
        );
    }

    public function testObserverWritesFailureMarkOnPayload(): void
    {
        $observer = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Observer/ThemeGenerationAfterSystemUpgrade.php'
        );
        self::assertStringContainsString(
            "setData('generation_promote_failed', true)",
            $observer,
            '观察者失败时必须写回 generation_promote_failed=true'
        );
    }
}
