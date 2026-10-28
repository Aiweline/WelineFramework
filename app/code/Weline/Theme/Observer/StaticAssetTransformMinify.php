<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Minify\StaticAssetMinifier;

/**
 * Minify css/js/mjs content during Framework deploy transform (production only).
 */
class StaticAssetTransformMinify implements ObserverInterface
{
    public function __construct(
        private readonly StaticAssetMinifier $minifier
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getEvenData();
        $targetPath = (string)$data->getData('target_path');
        $extension = strtolower((string)$data->getData('extension'));
        $pathForCheck = $targetPath !== '' ? $targetPath : ('file.' . $extension);

        if (defined('DEV') && DEV) {
            // DEV：不压缩内容，但超预算立即告警，让体积问题在开发期暴露。
            $this->assertDevBudget($pathForCheck, (string)$data->getData('content'));

            return;
        }

        if (!$this->minifier->shouldMinify($pathForCheck)) {
            return;
        }

        $content = (string)$data->getData('content');
        $minified = $this->minifier->minifyFileContent($content, $pathForCheck);
        if ($minified === $content) {
            return;
        }

        $data->setData('content', $minified);
        $data->setData('transformed', true);
    }

    /**
     * DEV 预算断言：仅 css/js/mjs（且非 `.min.*`）、内容 ≥ 1KB 时检查，超 100KB 记 error。
     */
    private function assertDevBudget(string $path, string $content): void
    {
        if (!$this->minifier->shouldMinify($path)) {
            return;
        }

        $size = strlen($content);
        if ($size < 1024 || $size <= StaticAssetMinifier::DEV_ASSET_WARN_BYTES) {
            return;
        }

        if (!function_exists('w_log_error')) {
            return;
        }

        w_log_error(sprintf(
            '[Theme Minify] %s is %d bytes in DEV (budget %d); minify or split before deploy.',
            $path,
            $size,
            StaticAssetMinifier::DEV_ASSET_WARN_BYTES
        ));
    }
}
