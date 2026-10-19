<?php

declare(strict_types=1);

namespace Weline\I18n\Console\I18n\Dictionary;

use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\I18n\Service\DictionaryFanoutDemoteService;

/**
 * php bin/w i18n:dictionary:demote-fanout [--dry-run]
 */
class DemoteFanout implements CommandInterface
{
    public function __construct(
        private readonly DictionaryFanoutDemoteService $demoteService,
        private readonly Printing $printing,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $dryRun = in_array('--dry-run', $args, true)
            || in_array('-n', $args, true)
            || (($data['dry-run'] ?? false) === true);

        $result = $this->demoteService->demote($dryRun);
        $this->printing->success($dryRun ? 'dry-run（未删除）' : '已降级扇出为稀表');
        $this->printing->printing(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');
    }

    public function tip(): string
    {
        return (string)__('删除非默认语占位扇出行（译=源），保留真覆盖；配合稀表架构');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'i18n:dictionary:demote-fanout',
            $this->tip(),
            [
                '-n, --dry-run' => '只统计不删除',
                '-h, --help' => '显示帮助',
            ],
            [],
            []
        );
    }
}
