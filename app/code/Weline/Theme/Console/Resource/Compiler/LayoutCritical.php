<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Resource\Compiler;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Resource\CompilerInterface;
use Weline\Theme\Service\Theme\LayoutCriticalCssMaterializer;

/**
 * `php bin/w theme:compile layoutCritical`
 *
 * 把各设计主题的「自动物化副本」同步到当前基线。
 *
 * 生成物是基线的纯函数（`LayoutCriticalCssMaterializer::buildGeneratedBody()`），
 * 因此本命令只写文件、不校验，也不需要主题模型/DB。
 * 已接管（fork，自行删掉 marker）的主题一律跳过，绝不覆写。
 *
 * 与 `theme:compile spec` 的分工：本命令 = 生成物对齐；spec = 只读门禁（S11/S12/S4）。
 * 门禁保持纯只读，避免「门禁顺手改文件」把真实的陈旧/越权改动掩盖掉。
 */
class LayoutCritical implements CompilerInterface
{
    public function compile(string $source_file = '', string $out_file = '')
    {
        /** @var LayoutCriticalCssMaterializer $materializer */
        $materializer = ObjectManager::getInstance(LayoutCriticalCssMaterializer::class);
        $result = $materializer->syncGeneratedCopies();

        foreach ($result['synced'] as $path) {
            echo sprintf("[SYNC] %s\n", str_replace(BP, '', $path));
        }
        foreach ($result['fork'] as $path) {
            echo sprintf("[SKIP] %s (fork takeover: marker removed, framework must not overwrite)\n", str_replace(BP, '', $path));
        }

        echo sprintf(
            "Layout-critical: %d synced, %d fork skipped, %d already current\n",
            count($result['synced']),
            count($result['fork']),
            count($result['current'])
        );

        return 0;
    }
}
