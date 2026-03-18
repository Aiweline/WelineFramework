<?php
declare(strict_types=1);

namespace Weline\Cron\Attribute;

/**
 * 标记 Cron 类在「手动测试」中的说明；与可选的实例方法 test(array $options): string 配合使用。
 * --list 仅读属性，不执行 test()。
 *
 * manual_help：后台「手动运行」里「后缀 / WELINE_CRON_MANUAL_ARGS」的逐条说明；空则展示模块通用一句提示。
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CronTestHelp
{
    /**
     * @param list<string> $examples 示例命令行
     * @param list<string> $manual_help 本任务专属的手动参数说明（每条一行列表展示）
     */
    public function __construct(
        public string $description = '',
        public array $examples = [],
        public array $manual_help = [],
    ) {
    }
}
