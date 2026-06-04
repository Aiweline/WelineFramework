<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\View\Support;

/**
 * 读取 AI 建站工作台 script-main 拆分后的 JS 片段（契约测试用）。
 */
final class AiSiteWorkspaceScriptReader
{
    public static function loadBundledJavaScript(): string
    {
        $dir = (\defined('BP') ? BP : \dirname(__DIR__, 4) . '/') . 'app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/';
        $parts = [
            'script-main-core.phtml',
            'script-main-stage-plan.phtml',
            'script-main-stage-publish.phtml',
            'script-main-boot.phtml',
        ];
        $bundle = '';
        foreach ($parts as $part) {
            $path = $dir . $part;
            if (!\is_file($path)) {
                throw new \RuntimeException('Missing workspace script chunk: ' . $path);
            }
            $bundle .= (string) \file_get_contents($path);
        }

        return $bundle;
    }
}
