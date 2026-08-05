<?php

declare(strict_types=1);

namespace Weline\Framework\UnitTest\Console\PhpUnit;

/**
 * 兼容历史命令注册类名。
 *
 * 已安装环境可能仍把 phpunit:run 指向旧 UnitTest 命名空间；统一委托到
 * canonical Test 实现，避免继续执行 vendor 中缺少零测试 fail-fast 的旧副本。
 */
class Run extends \Weline\Framework\Test\Console\PhpUnit\Run
{
}
