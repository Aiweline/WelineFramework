<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Frontend;

use Weline\Framework\App\Exception;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Rules\Frontend\WidgetLayoutStabilityScanner;

final class CheckWidgetLayoutStability extends CommandAbstract
{
    /** @var list<string> */
    public const ALIASES = [
        'frontend:check-widget-layout-stability',
    ];

    public function __construct(
        private readonly Printing $printing,
        private readonly WidgetLayoutStabilityScanner $scanner,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $json = isset($args['json']);
        // Default: app/code + app/design (theme overrides). Pass a single root via env only for tests.
        $violations = $this->scanner->scanProject(null);

        if ($json) {
            echo (string)json_encode(
                [
                    'ok' => $violations === [],
                    'count' => count($violations),
                    'violations' => $violations,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ), PHP_EOL;
        } else {
            $this->printing->note(__(
                '部件布局稳定检查：发现 %{1} 条违规。',
                [count($violations)],
            ));
            foreach (array_slice($violations, 0, 200) as $violation) {
                $this->printing->error($this->scanner->formatViolation($violation));
            }
            if (count($violations) > 200) {
                $this->printing->warning(__(
                    '其余 %{1} 条问题已省略，使用 --json 查看全部。',
                    [count($violations) - 200],
                ));
            }
        }

        if ($violations !== []) {
            throw new Exception(__(
                '【致命错误】部件布局稳定门禁失败：共 %{1} 处缺少 .w-frame / .w-skeleton[data-size=card]。'
                . '见 Theme/doc/开发/spec/widget-layout-stability.md',
                [count($violations)],
            ));
        }

        $this->printing->success(__('部件布局稳定检查通过。'));
    }

    public function tip(): string
    {
        return __('检查店面部件与商品卡是否挂 Theme .w-frame / .w-skeleton 布局稳定类');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'frontend:check-widget-layout-stability',
            $this->tip(),
            [
                '--json' => __('JSON 格式输出完整报告'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('本地门禁') => 'php bin/w frontend:check-widget-layout-stability',
                __('JSON 报告') => 'php bin/w frontend:check-widget-layout-stability --json',
            ],
        );
    }
}
