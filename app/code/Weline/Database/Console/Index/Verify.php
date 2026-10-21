<?php

declare(strict_types=1);

namespace Weline\Database\Console\Index;

use Weline\Database\Service\Index\SchemaIndexDriftService;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

final class Verify implements CommandInterface
{
    public function __construct(
        private readonly SchemaIndexDriftService $driftService,
        private readonly Printing $printing,
    ) {
    }

    public function execute(array $args = [], array $data = []): string
    {
        $table = trim((string)($args['table'] ?? ''));
        $fix = !empty($args['fix']);
        $result = $this->driftService->verify($fix, $table === '' ? null : $table);
        $message = (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->printing->info($message);

        return $message;
    }

    public function tip(): string
    {
        return __('巡检声明索引的物理状态；--fix 时为漂移项入队修复任务。');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('index:verify', $this->tip(), [
            '--table' => __('只巡检指定表（逻辑表名）'),
            '--fix' => __('为漂移索引入队修复任务'),
            '--json' => __('输出 JSON（默认即 JSON）'),
        ], [
            'php bin/w index:verify',
            'php bin/w index:verify --table=w_i18n_locale_dictionary --fix',
        ], []);
    }
}
