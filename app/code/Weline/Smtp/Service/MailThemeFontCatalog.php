<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Theme\Helper\StorefrontFontPresetCatalog;

/**
 * 邮件可视化编辑器可选字体（主题预设 + 邮件安全系统栈）。
 */
final class MailThemeFontCatalog
{
    /**
     * @return list<array{id:string,label:string,stack:string}>
     */
    public static function mailEditorFonts(): array
    {
        $out = [];
        $seen = [];
        if (class_exists(StorefrontFontPresetCatalog::class)) {
            foreach (StorefrontFontPresetCatalog::presets() as $preset) {
                $id = trim((string)($preset['id'] ?? ''));
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = [
                    'id' => $id,
                    'label' => (string)($preset['label'] ?? $id),
                    'stack' => (string)($preset['stack'] ?? ''),
                ];
            }
        }
        foreach ([
            ['id' => 'noto-serif-sc', 'label' => '思源宋体', 'stack' => '"Noto Serif SC","Songti SC",serif'],
            ['id' => 'noto-sans-sc', 'label' => '思源黑体', 'stack' => '"Noto Sans SC","PingFang SC",sans-serif'],
            ['id' => 'arial', 'label' => 'Arial', 'stack' => 'Arial,Helvetica,sans-serif'],
            ['id' => 'georgia', 'label' => 'Georgia', 'stack' => "Georgia,'Times New Roman',serif"],
        ] as $fallback) {
            if (isset($seen[$fallback['id']])) {
                continue;
            }
            $out[] = $fallback;
        }

        return $out;
    }
}
