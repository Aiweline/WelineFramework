<?php

declare(strict_types=1);

namespace Weline\Visitor\Console\Pixel;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Visitor\Service\PixelChannelRuleSeedService;

/**
 * B08：PixelSource → pixel_channel rule 种子。
 *
 * 用法：
 *   php bin/w pixel:channel-rule-seed
 *   php bin/w pixel:channel-rule-seed dry-run
 *   php bin/w pixel:channel-rule-seed apply --enable-apply
 */
class ChannelRuleSeed extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $action = 'dry-run';
        foreach ($args as $arg) {
            $lower = \strtolower(\trim((string)$arg));
            if (\in_array($lower, ['help', 'dry-run', 'dry_run', 'apply'], true)) {
                $action = $lower === 'dry_run' ? 'dry-run' : $lower;
                break;
            }
        }

        if ($action === 'help' || $this->wantsCommandHelp($args)) {
            $help = $this->help();
            $encoded = \is_array($help)
                ? (\json_encode($help, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}')
                : (string)$help;
            $printing->printing($encoded, 'success');

            return $encoded;
        }

        /** @var PixelChannelRuleSeedService $service */
        $service = ObjectManager::getInstance(PixelChannelRuleSeedService::class);

        if ($action === 'apply') {
            if (!$this->hasFlag($args, 'enable-apply')) {
                $msg = 'apply 需要显式 --enable-apply';
                $printing->printing($msg, 'error');

                return $msg;
            }
            $result = $service->seed(false);
        } else {
            $result = $service->seed(true);
        }

        $summary = \sprintf(
            'pixel:channel-rule-seed %s source=%s planned=%d inserted=%d updated=%d skipped=%d errors=%d',
            $action,
            $result['source'],
            \count($result['planned']),
            $result['inserted'],
            $result['updated'],
            $result['skipped'],
            \count($result['errors'])
        );
        $printing->printing($summary, $result['ok'] ? 'success' : 'warning');
        if ($action === 'dry-run' && $result['planned'] !== []) {
            $codes = \array_map(
                static fn(array $row): string => (string)($row['code'] ?? ''),
                \array_slice($result['planned'], 0, 20)
            );
            $printing->printing('codes: ' . \implode(', ', $codes), 'note');
        }
        foreach ($result['errors'] as $error) {
            $printing->printing($error, 'error');
        }

        return $summary;
    }

    public function tip(): string
    {
        return 'Seed pixel_channel rule rows from PixelSource (B08)';
    }

    public function help(): array|string
    {
        return [
            'command' => 'pixel:channel-rule-seed',
            'actions' => ['dry-run', 'apply', 'help'],
            'flags' => ['--enable-apply'],
            'note' => '默认 dry-run；apply 需 --enable-apply。幂等写入 kind=rule。',
        ];
    }

    /** @param array<int|string, mixed> $args */
    private function wantsCommandHelp(array $args): bool
    {
        foreach ($args as $arg) {
            if (\in_array(\strtolower(\trim((string)$arg)), ['-h', '--help', 'help'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int|string, mixed> $args */
    private function hasFlag(array $args, string $flag): bool
    {
        $needle = '--' . $flag;
        foreach ($args as $arg) {
            if (\strtolower(\trim((string)$arg)) === \strtolower($needle)) {
                return true;
            }
        }

        return false;
    }
}
