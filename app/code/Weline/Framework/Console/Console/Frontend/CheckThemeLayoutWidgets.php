<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Frontend;

use Weline\Framework\App\Exception;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Rules\Frontend\ThemeLayoutWidgetOwnerScanner;

final class CheckThemeLayoutWidgets extends CommandAbstract
{
    /** @var list<string> */
    public const ALIASES = [
        'frontend:check-theme-layout-widgets',
    ];

    public function __construct(
        private readonly Printing $printing,
        private readonly ThemeLayoutWidgetOwnerScanner $scanner,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $json = isset($args['json']);
        $root = BP . '/app/code';
        $violations = $this->scanner->scanProject($root);

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
                'Theme 布局部件归属检查：发现 %{1} 条违规。',
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
                '【致命错误】Theme 布局/partial 内嵌非 Theme 部件：共 %{1} 处。请改为 default_injections 后重新运行 frontend:check-theme-layout-widgets。',
                [count($violations)],
            ));
        }

        $this->printing->success(__('Theme 布局部件归属检查通过。'));
    }

    public function tip(): string
    {
        return __('检查 Theme layouts/partials 是否仅内嵌 Weline_Theme 部件');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'frontend:check-theme-layout-widgets',
            $this->tip(),
            [
                '--json' => __('JSON 格式输出完整报告'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('本地门禁') => 'php bin/w frontend:check-theme-layout-widgets',
                __('JSON 报告') => 'php bin/w frontend:check-theme-layout-widgets --json',
            ],
        );
    }
}
