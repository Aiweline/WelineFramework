<?php

declare(strict_types=1);

namespace Weline\I18n\Console\I18n\Dictionary;

use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\I18n\Service\DictionaryInvalidWordPurgeService;

/**
 * php bin/w i18n:dictionary:purge-invalid [--dry-run]
 */
class PurgeInvalid implements CommandInterface
{
    public function __construct(
        private readonly DictionaryInvalidWordPurgeService $purgeService,
        private readonly Printing $printing,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $dryRun = in_array('--dry-run', $args, true)
            || in_array('-n', $args, true)
            || (($data['dry-run'] ?? false) === true);

        $result = $this->purgeService->purge($dryRun);
        $this->printing->success($dryRun ? 'dry-run（未删除）' : '已清理无效词典行');
        $this->printing->printing(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');
    }

    public function tip(): string
    {
        return (string)__('清理超长/换行/BOM/引号失控/非法地区码的词典脏行');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'i18n:dictionary:purge-invalid',
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
