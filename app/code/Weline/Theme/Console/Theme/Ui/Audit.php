<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme\Ui;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Theme\Service\Ui\AuditService;

final class Audit extends CommandAbstract
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function execute(array $args = [], array $data = []): int
    {
        $report = $this->auditService->auditRepository();
        $format = strtolower((string)($args['format'] ?? ($args['json'] ?? 'text')));
        if ($format === 'json' || $format === '1' || $format === 'true') {
            fwrite(STDOUT, json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL);
        } else {
            $this->printer->note(__('Weline UI 审计：扫描 %{1} 个文件', [$report['files_scanned']]));
            foreach ($report['violations'] as $violation) {
                $this->printer->error(sprintf(
                    '%s:%d [%s] %s',
                    $violation['path'],
                    $violation['line'],
                    $violation['code'],
                    $violation['match'],
                ));
            }
            if ($report['ok']) {
                $this->printer->success(__('Weline UI 审计通过'));
            } else {
                $this->printer->error(__('Weline UI 审计失败：%{1} 项违规', [count($report['violations'])]));
            }
        }

        return $report['ok'] ? 0 : 1;
    }

    public function tip(): string
    {
        return __('审计 Weline UI 2.0 旧依赖、资源归属和性能预算');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'theme:ui:audit',
            $this->tip(),
            ['--format=json' => __('输出机器可读 JSON')],
            [],
            [__('完整审计') => 'php bin/w theme:ui:audit', __('JSON 审计') => 'php bin/w theme:ui:audit --format=json'],
        );
    }
}
