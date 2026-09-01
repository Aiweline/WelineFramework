<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\I18n;

use Weline\Framework\App\Exception;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Phrase\DictionaryCompiler;

final class Collect implements CommandInterface
{
    public const ALIASES = ['i18n:collect'];

    public function __construct(
        private readonly DictionaryCompiler $compiler,
        private readonly Printing $printing,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $moduleName = $args['module'] ?? $args['m'] ?? $args[1] ?? null;
        $moduleName = is_string($moduleName) ? trim($moduleName) : null;

        if ($moduleName !== null && $moduleName !== '') {
            $this->printing->note(__('正在收集模块：%{1}', [$moduleName]));
        } else {
            $this->printing->note(__('正在收集所有模块的翻译词...'));
        }

        try {
            $this->compiler->compile($moduleName !== '' ? $moduleName : null, false);
            $this->printing->success(
                $moduleName
                    ? __('模块 %{1} 语言包收集成功！', [$moduleName])
                    : __('语言包收集成功！'),
            );
        } catch (\Throwable $throwable) {
            $this->printing->error(
                $moduleName
                    ? __('模块 %{1} 语言包收集失败：%{2}', [$moduleName, $throwable->getMessage()])
                    : __('语言包收集失败：%{1}', [$throwable->getMessage()]),
            );
            return;
        }

        $this->printing->note(__('正在清理翻译缓存...'));
        try {
            DictionaryCompiler::clearTranslationCaches();
            $this->printing->success(__('翻译缓存清理成功！'));
        } catch (Exception $exception) {
            $this->printing->warning(
                __('翻译缓存清理失败：%{1}，但翻译收集已完成', [$exception->getMessage()]),
            );
        }
    }

    public function tip(): string
    {
        return __('收集翻译词');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => __('显示帮助信息'),
                '--module, -m' => __('仅收集指定模块'),
            ],
            [],
            [],
        );
    }
}
