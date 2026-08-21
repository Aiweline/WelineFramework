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
        if (defined('DEV') && DEV) {
            return;
        }

        $data = $event->getEvenData();
        $targetPath = (string)$data->getData('target_path');
        $extension = strtolower((string)$data->getData('extension'));
        $pathForCheck = $targetPath !== '' ? $targetPath : ('file.' . $extension);

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
}
