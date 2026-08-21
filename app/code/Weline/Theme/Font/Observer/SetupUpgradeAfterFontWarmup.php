<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Font\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Font\FontWarmupService;
use Weline\Framework\Output\Cli\Printing;

/**
 * After setup:upgrade, warm language subsets for registered fonts.
 * Existing language subset files are skipped (no rebuild).
 */
class SetupUpgradeAfterFontWarmup implements ObserverInterface
{
    private static bool $hasRun = false;

    public function __construct(
        private readonly FontWarmupService $warmupService,
        private readonly Printing $printing
    ) {
    }

    public function execute(Event &$event): void
    {
        if (self::$hasRun) {
            return;
        }
        self::$hasRun = true;

        try {
            $this->printing->note(__('字体子集预热开始…'));
            $result = $this->warmupService->warmup();
            $this->printing->success(__(
                '字体子集预热完成：新建 %{built}，跳过 %{skipped}，失败 %{failed}',
                [
                    'built' => $result['built'],
                    'skipped' => $result['skipped'],
                    'failed' => $result['failed'],
                ]
            ));
            foreach ($result['items'] as $item) {
                if (($item['status'] ?? '') !== 'failed') {
                    continue;
                }
                $this->printing->warning(__(
                    '字体子集失败：%{font} [%{lang}] — %{error}',
                    [
                        'font' => $item['font'] ?? '',
                        'lang' => $item['lang'] ?? '',
                        'error' => $item['error'] ?? '',
                    ]
                ));
            }
        } catch (\Throwable $e) {
            // Do not abort setup:upgrade on font warmup failures.
            $this->printing->warning(__('字体子集预热异常：%{msg}', ['msg' => $e->getMessage()]));
        }
    }

    /**
     * @internal test helper
     */
    public static function resetHasRunFlag(): void
    {
        self::$hasRun = false;
    }
}
