<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\I18n;

use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Phrase\DictionaryCollectBusyException;
use Weline\Framework\Phrase\DictionaryCollectGate;
use Weline\Framework\Phrase\DictionaryCompiler;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;

final class Collect implements CommandInterface
{
    public const ALIASES = ['i18n:collect'];

    public function __construct(
        private readonly DictionaryCompiler $compiler,
        private readonly Printing $printing,
        private readonly ?RuntimeControlBroadcasterInterface $broadcastService = null,
    ) {
    }

    public function execute(array $args = [], array $data = []): int
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
        } catch (DictionaryCollectBusyException $busy) {
            $this->printing->warning($busy->getMessage());

            return 75; // EX_TEMPFAIL — duplicate launch refused
        } catch (\Throwable $throwable) {
            if (DictionaryCollectGate::isBusy($throwable)) {
                $this->printing->warning($throwable->getMessage());

                return 75;
            }
            $this->printing->error(
                $moduleName
                    ? __('模块 %{1} 语言包收集失败：%{2}', [$moduleName, $throwable->getMessage()])
                    : __('语言包收集失败：%{1}', [$throwable->getMessage()]),
            );

            return 1;
        }

        $this->printing->note(__('正在清理翻译缓存...'));
        try {
            DictionaryCompiler::clearTranslationCaches();
            $broadcaster = $this->broadcastService
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class)
                    ->resolve(RuntimeControlBroadcasterInterface::class);
            if ($broadcaster instanceof RuntimeControlBroadcasterInterface) {
                $result = $broadcaster->cacheClearAndWait(null, 12.0);
                if (($result['success'] ?? false) !== true || ($result['completed'] ?? false) !== true) {
                    throw new \RuntimeException((string)($result['message'] ?? __('未知错误')));
                }
            }
            $this->printing->success(__('翻译缓存清理成功！'));
        } catch (\Throwable $exception) {
            $this->printing->warning(
                __('翻译缓存清理失败：%{1}，但翻译收集已完成', [$exception->getMessage()]),
            );
        }

        return 0;
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
