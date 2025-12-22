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
 * CSV词典导入控制台命令
 */
class ImportCsv implements CommandInterface
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
        $locale = $data['locale'] ?? $data['l'] ?? '';
        $file = $data['file'] ?? $data['f'] ?? '';
        $all = isset($data['all']) || isset($data['a']);

        if ($all) {
            // 导入所有模块的CSV文件
            if (empty($locale)) {
                $this->printing->error(__('参数错误：--locale 或 -l 参数不能为空'));
                $this->printing->printing();
                return;
            }

            $this->printing->note(__('开始导入所有模块的CSV翻译文件...'));
            $this->printing->note(__('目标语言: %{1}', [$locale]));
            $this->printing->printing();

            try {
                $startTime = microtime(true);

                $result = $this->translationService->importModuleCsvFiles($locale);

                $duration = round(microtime(true) - $startTime, 2);

                // 显示结果
                if ($result['success']) {
                    $this->printing->success(__('导入完成!'));
                    $this->printing->printing();
                    $this->printing->note(__('处理文件数: %{1}', [$result['files']]));
                    $this->printing->note(__('导入数量: %{1}', [$result['imported']]));
                    $this->printing->note(__('跳过数量: %{1}', [$result['skipped']]));
                    $this->printing->note(__('失败数量: %{1}', [$result['failed']]));
                    $this->printing->note(__('耗时: %{1}秒', [$duration]));

                    if (!empty($result['errors'])) {
                        $this->printing->printing();
                        $this->printing->warning(__('错误信息:'));
                        foreach ($result['errors'] as $error) {
                            $this->printing->warning('  - ' . $error);
                        }
                    }
                } else {
                    $this->printing->error(__('导入失败!'));
                    $this->printing->printing();
                    $this->printing->error(__('错误信息: %{1}', [$result['message']]));
                }
            } catch (\Exception $e) {
                $this->printing->error(__('导入异常: %{1}', [$e->getMessage()]));
            }
        } else {
            // 导入指定的CSV文件
            if (empty($file) || empty($locale)) {
                $this->printing->error(__('参数错误：--file 和 --locale 参数不能为空'));
                $this->printing->note(__('使用 --help 查看帮助信息'));
                $this->printing->printing();
                return;
            }

            $this->printing->note(__('开始导入CSV翻译文件...'));
            $this->printing->note(__('文件路径: %{1}', [$file]));
            $this->printing->note(__('目标语言: %{1}', [$locale]));
            $this->printing->printing();

            try {
                $startTime = microtime(true);

                $result = $this->translationService->importFromCsv($file, $locale);

                $duration = round(microtime(true) - $startTime, 2);

                // 显示结果
                if ($result['success']) {
                    $this->printing->success(__('导入完成!'));
                    $this->printing->printing();
                    $this->printing->note(__('导入数量: %{1}', [$result['imported']]));
                    $this->printing->note(__('跳过数量: %{1}', [$result['skipped']]));
                    $this->printing->note(__('失败数量: %{1}', [$result['failed']]));
                    $this->printing->note(__('总行数: %{1}', [$result['total']]));
                    $this->printing->note(__('耗时: %{1}秒', [$duration]));

                    if (!empty($result['errors'])) {
                        $this->printing->printing();
                        $this->printing->warning(__('错误信息:'));
                        foreach ($result['errors'] as $error) {
                            $this->printing->warning('  - ' . $error);
                        }
                    }
                } else {
                    $this->printing->error(__('导入失败!'));
                    $this->printing->printing();
                    $this->printing->error(__('错误信息: %{1}', [$result['message']]));
                }
            } catch (\Exception $e) {
                $this->printing->error(__('导入异常: %{1}', [$e->getMessage()]));
            }
        }

        $this->printing->printing();
    }

    /**
     * 命令提示
     */
    public function tip(): string
    {
        return __('从CSV文件导入翻译到词典');
    }

    /**
     * 帮助信息
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'i18n:ai:import-csv',
            __('从CSV文件导入翻译到词典（已存在的翻译会自动跳过）'),
            [
                '-f, --file' => __('CSV文件路径（导入单个文件时必填）'),
                '-l, --locale' => __('目标语言代码（如 en_US, ja_JP）【必填】'),
                '-a, --all' => __('导入所有模块的CSV文件（自动扫描所有模块的i18n目录）'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('导入指定CSV文件') => 'php bin/w i18n:ai:import-csv --file=path/to/en_US.csv --locale=en_US',
                __('导入所有模块的英文翻译') => 'php bin/w i18n:ai:import-csv --all --locale=en_US',
                __('导入所有模块的日文翻译') => 'php bin/w i18n:ai:import-csv --all --locale=ja_JP',
            ]
        );
    }
}

