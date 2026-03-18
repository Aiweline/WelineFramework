<?php
declare(strict_types=1);

namespace Weline\Cron\Attribute;

/**
 * 标记 Cron 类在「手动测试」中的说明；与可选的实例方法 test(array $options): string 配合使用。
 * --list 仅读属性，不执行 test()。
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CronTestHelp
{
    /**
     * @param list<string> $examples 示例命令行
     */
    public function __construct(
        public string $description = '',
        public array $examples = [],
    ) {
    }
}
