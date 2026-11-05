<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Console\Theme\ColdProfile;

/**
 * 冷画像命令的穿章必须走唯一权威。
 *
 * 缺陷基线（2026-11-05 实测）：本机 `generated/framework/fpc_pierce_secret` 是
 * `0f0a2026…`，而 env 与边缘 `fpc-pierce.map.conf` 都是 `288c9d07…`。命令自己读
 * 文件就会带着**过期章**发请求 → 边缘不认 → 整页缓存命中 → 永远输出
 * 「本次没有产生新画像」，看上去像“没达阈值”，实际是密钥源分叉。
 *
 * 权威只有 `FpcPierceStamp::readSecret()`（env `wls.fpc_pierce_secret` 优先、
 * 文件兜底），本命令不得再自建一条读取路径。
 */
final class ColdProfilePierceSecretContractsTest extends TestCase
{
    public function testPierceSecretIsResolvedThroughTheSingleAuthority(): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass(ColdProfile::class))->getFileName());

        self::assertStringContainsString(
            'FpcPierceStamp::readSecret()',
            $source,
            'ColdProfile 必须委托 FpcPierceStamp 解析穿章密钥',
        );
        self::assertStringNotContainsString(
            'generated/framework/fpc_pierce_secret',
            $source,
            'ColdProfile 不得再直接读 generated 密钥文件（会拿到过期值）',
        );
    }
}
