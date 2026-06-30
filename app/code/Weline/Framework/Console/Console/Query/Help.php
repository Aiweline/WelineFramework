<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Query;

use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Service\Query\FrameworkQueryService;

class Help implements CommandInterface
{
    public function __construct(
        private readonly Printing $printing
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        /** @var FrameworkQueryService $queryService */
        $queryService = ObjectManager::getInstance(FrameworkQueryService::class);

        $asJson = isset($args['json']) || isset($args['j']);
        $provider = $this->resolvePositionalArg($args, 1);
        $operation = $this->resolvePositionalArg($args, 2);

        try {
            if ($provider === '' && $operation === '') {
                $result = $queryService->introspectHelp('', [], 'backend', false);
                $this->renderProvidersList($result, $asJson);
                return;
            }

            if ($operation === '') {
                $result = $queryService->introspectHelp($provider, [], 'backend', false);
                $this->renderProviderHelp($result, $asJson);
                return;
            }

            $result = $queryService->introspectHelp($provider, ['operation' => $operation], 'backend', false);
            $this->renderOperationHelp($provider, $result, $asJson);
        } catch (\Throwable $e) {
            $this->printing->error($e->getMessage());
        }
    }

    private function resolvePositionalArg(array $args, int $index): string
    {
        $value = $args[$index] ?? '';
        if (!\is_string($value)) {
            return '';
        }
        if (\str_starts_with($value, '-')) {
            return '';
        }

        return \trim($value);
    }

    /**
     * @param array<int, array<string, mixed>> $providers
     */
    private function renderProvidersList(array $providers, bool $asJson): void
    {
        if ($asJson) {
            $this->printing->printing(\json_encode($providers, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . \PHP_EOL);
            return;
        }

        $this->printing->note(__('已注册的 QueryProvider（%{1} 个）', \count($providers)));
        $this->printing->note('');
        foreach ($providers as $item) {
            $line = \sprintf(
                '  %-16s  %-24s  %s',
                (string)($item['provider'] ?? ''),
                (string)($item['module'] ?? ''),
                (string)($item['name'] ?? '')
            );
            $this->printing->note($line);
            $description = (string)($item['description'] ?? '');
            if ($description !== '') {
                $this->printing->note('    ' . $description);
            }
            $this->printing->note('    operations: ' . (string)($item['operation_count'] ?? 0));
            $this->printing->note('');
        }
        $this->printing->note(__('查看单个 provider：php bin/w query:help <provider|WeShop_Product>'));
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private function renderProviderHelp(array $descriptor, bool $asJson): void
    {
        if ($asJson) {
            $this->printing->printing(\json_encode($descriptor, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . \PHP_EOL);
            return;
        }

        $this->printing->note(__('Provider：%{1}（%{2}）', [
            (string)($descriptor['provider'] ?? ''),
            (string)($descriptor['module'] ?? ''),
        ]));
        $name = (string)($descriptor['name'] ?? '');
        if ($name !== '') {
            $this->printing->note(__('名称：%{1}', $name));
        }
        $description = (string)($descriptor['description'] ?? '');
        if ($description !== '') {
            $this->printing->note(__('说明：%{1}', $description));
        }
        $this->printing->note('');

        foreach (($descriptor['operations'] ?? []) as $operation) {
            if (!\is_array($operation)) {
                continue;
            }
            $this->printing->note('  ' . (string)($operation['name'] ?? ''));
            $opDesc = (string)($operation['description'] ?? '');
            if ($opDesc !== '') {
                $this->printing->note('    ' . $opDesc);
            }
            foreach (($operation['params'] ?? []) as $param) {
                if (!\is_array($param)) {
                    continue;
                }
                $required = (($param['required'] ?? false) === true) ? 'required' : 'optional';
                $this->printing->note(\sprintf(
                    '    - %s (%s, %s): %s',
                    (string)($param['name'] ?? ''),
                    (string)($param['type'] ?? 'mixed'),
                    $required,
                    (string)($param['description'] ?? '')
                ));
            }
            if (isset($operation['example'])) {
                $this->printing->note('    example: ' . (string)$operation['example']);
            }
            $this->printing->note('');
        }
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function renderOperationHelp(string $provider, array $operation, bool $asJson): void
    {
        if ($asJson) {
            $this->printing->printing(\json_encode($operation, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . \PHP_EOL);
            return;
        }

        $this->printing->note(__('Operation：%{1}.%{2}', [$provider, (string)($operation['name'] ?? '')]));
        $description = (string)($operation['description'] ?? '');
        if ($description !== '') {
            $this->printing->note(__('说明：%{1}', $description));
        }
        $this->printing->note('');
        foreach (($operation['params'] ?? []) as $param) {
            if (!\is_array($param)) {
                continue;
            }
            $required = (($param['required'] ?? false) === true) ? 'required' : 'optional';
            $this->printing->note(\sprintf(
                '  %s (%s, %s): %s',
                (string)($param['name'] ?? ''),
                (string)($param['type'] ?? 'mixed'),
                $required,
                (string)($param['description'] ?? '')
            ));
        }
        if (isset($operation['example'])) {
            $this->printing->note('');
            $this->printing->note('example: ' . (string)$operation['example']);
        }
        if (isset($operation['cli_example'])) {
            $this->printing->note('cli: ' . (string)$operation['cli_example']);
        }
    }

    public function tip(): string
    {
        return '查看 w_query QueryProvider 帮助（provider、模块名、operation）';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'query:help [provider|WeShop_Product] [operation] [--json]',
            $this->tip(),
            [
                '--json, -j' => '以 JSON 输出帮助结果',
                '-h, --help' => '显示帮助信息',
            ],
            [
                'php bin/w query:help',
                'php bin/w query:help widget',
                'php bin/w query:help WeShop_Product',
                'php bin/w query:help theme getActiveTheme --json',
            ],
            []
        );
    }
}
