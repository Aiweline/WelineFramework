<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Console\Ai;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\I18n\Service\AiTranslationService;

/**
 * AI翻译控制台命令
 */
class Translate implements CommandInterface
{
    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * @var AiTranslationService
     */
    private AiTranslationService $translationService;

    /**
     * 构造函数
     */
    public function __construct(
        Printing $printing,
        AiTranslationService $translationService
    ) {
        $this->printing = $printing;
        $this->translationService = $translationService;
    }

    /**
     * 执行命令
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->printing->setup();
        
        // 获取参数
        $locale = $data['locale'] ?? $data['l'] ?? 'en_US';
        $source = $data['source'] ?? $data['s'] ?? 'zh_Hans_CN';
        $batch = isset($data['batch']) || isset($data['b']);
        $limit = (int)($data['limit'] ?? 1000);

        $this->printing->note(__('开始AI翻译...'));
        $this->printing->note(__('目标语言: %{1}', [$locale]));
        $this->printing->note(__('源语言: %{1}', [$source]));
        $this->printing->note(__('批量大小: %{1}', [$limit]));
        $this->printing->printing();

        try {
            $startTime = microtime(true);

            // 执行批量翻译
            $result = $this->translationService->batchTranslateDictionary(
                $locale,
                $source,
                $limit
            );

            $duration = round(microtime(true) - $startTime, 2);

            // 显示结果
            if ($result['success']) {
                $this->printing->success(__('翻译完成!'));
                $this->printing->printing();
                $this->printing->note(__('翻译数量: %{1}', [$result['translated']]));
                $this->printing->note(__('跳过数量: %{1}', [$result['skipped']]));
                $this->printing->note(__('失败数量: %{1}', [$result['failed']]));
                $this->printing->note(__('总词数: %{1}', [$result['total']]));
                $this->printing->note(__('耗时: %{1}秒', [$duration]));

                if (!empty($result['errors'])) {
                    $this->printing->printing();
                    $this->printing->warning(__('错误信息:'));
                    foreach ($result['errors'] as $error) {
                        $this->printing->warning('  - ' . $error);
                    }
                }
            } else {
                $this->printing->error(__('翻译失败!'));
                $this->printing->printing();
                $this->printing->error(__('错误信息: %{1}', [$result['message']]));

                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->printing->error('  - ' . $error);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->printing->error(__('翻译异常: %{1}', [$e->getMessage()]));
            $this->printing->error(__('异常位置: %{1}:%{2}', [$e->getFile(), $e->getLine()]));
        }

        $this->printing->printing();
    }

    /**
     * 命令提示
     */
    public function tip(): string
    {
        return __('使用AI批量翻译词典');
    }

    /**
     * 帮助信息
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'i18n:ai:translate',
            __('使用AI批量翻译词典（增量翻译，已存在的翻译不会重新翻译）'),
            [
                '-l, --locale' => __('目标语言代码（如 en_US, ja_JP），默认 en_US'),
                '-s, --source' => __('源语言代码（如 zh_Hans_CN），默认 zh_Hans_CN'),
                '--limit' => __('每批翻译数量，默认 1000'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('翻译为英文（默认）') => 'php bin/w i18n:ai:translate',
                __('翻译为日文') => 'php bin/w i18n:ai:translate --locale=ja_JP',
                __('翻译500个词') => 'php bin/w i18n:ai:translate --locale=en_US --limit=500',
            ]
        );
    }
}

