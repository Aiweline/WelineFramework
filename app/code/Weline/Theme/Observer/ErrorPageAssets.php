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
use Weline\Theme\Font\FontFaceService;

/**
 * Contribute Theme UI font-face CSS to Framework error pages (neutral event).
 */
class ErrorPageAssets implements ObserverInterface
{
    public function __construct(
        private readonly FontFaceService $fontFaceService
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getEvenData();
        try {
            $regular = $this->fontFaceService->renderCss([
                'src' => 'Weline_Theme::NotoSansSC-Regular.ttf',
                'family' => 'Noto Sans SC',
                'weight' => '400',
                'display' => 'swap',
            ]);
            $bold = $this->fontFaceService->renderCss([
                'src' => 'Weline_Theme::NotoSansSC-Bold.ttf',
                'family' => 'Noto Sans SC',
                'weight' => '700',
                'display' => 'swap',
            ]);
            $css = \trim($regular . "\n" . $bold);
            if ($css === '' || \str_starts_with($css, '/*')) {
                return;
            }
            $data->setData('font_face_css', $css);
        } catch (\Throwable) {
            // Keep Framework error pages usable without Theme fonts.
        }
    }
}
