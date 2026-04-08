<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Extends;

use Weline\Framework\App\Env;

/**
 * 完备性检查器
 * 检查 extends.md 文档和 doc/ 目录下的扩展文档是否完整
 */
class CompletenessChecker
{
    /**
     * 检查所有模块的完备性
     *
     * @param array $scannedData 扫描的数据
     * @return array 检查报告
     */
    public function checkAll(array $scannedData): array
    {
        $report = [];
        $modules = Env::getInstance()->getModuleList();

        foreach ($scannedData as $moduleName => $data) {
            if (!isset($modules[$moduleName])) {
                continue;
            }

            $module = $modules[$moduleName];
            if (!($module['status'] ?? false)) {
                continue;
            }
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath)) {
                continue;
            }

            $report[$moduleName] = $this->checkModule($moduleName, $basePath, $data);
        }

        return $report;
    }

    /**
     * 检查单个模块的完备性
     *
     * @param string $moduleName 模块名
     * @param string $basePath 模块基础路径
     * @param array $data 模块扩展数据
     * @return array
     */
    private function checkModule(string $moduleName, string $basePath, array $data): array
    {
        $report = [
            'has_extends_php' => false,
            'has_extends_md' => false,
            'has_doc_directory' => false,
            'warnings' => [],
            'errors' => []
        ];

        // 检查 extends.php
        $extendsPhpFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends.php';
        $report['has_extends_php'] = file_exists($extendsPhpFile);

        if (!$report['has_extends_php']) {
            return $report; // 没有 extends.php，无需进一步检查
        }

        // 检查 extends.md
        $extendsMdFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'extends.md';
        $report['has_extends_md'] = file_exists($extendsMdFile);

        if (!$report['has_extends_md']) {
            $report['warnings'][] = "模块 {$moduleName} 定义了 extends.php 但缺少 extends.md 文档";
        } else {
            // 检查 extends.md 内容完整性
            $mdContent = file_get_contents($extendsMdFile);
            $mdReport = $this->checkMarkdownContent($mdContent, $moduleName);
            $report['warnings'] = array_merge($report['warnings'], $mdReport['warnings']);
            $report['errors'] = array_merge($report['errors'], $mdReport['errors']);
        }

        // 检查 doc/ 目录
        $docDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'doc';
        $report['has_doc_directory'] = is_dir($docDir);

        if ($report['has_doc_directory']) {
            // 检查 doc/ 目录下的扩展文档
            $docReport = $this->checkDocDirectory($docDir, $moduleName, $data);
            $report['warnings'] = array_merge($report['warnings'], $docReport['warnings']);
            $report['errors'] = array_merge($report['errors'], $docReport['errors']);
        } else {
            $report['warnings'][] = "模块 {$moduleName} 缺少 doc/ 目录，建议添加扩展功能的使用文档";
        }

        return $report;
    }

    /**
     * 检查 Markdown 内容完整性
     *
     * @param string $content Markdown 内容
     * @param string $moduleName 模块名
     * @return array
     */
    private function checkMarkdownContent(string $content, string $moduleName): array
    {
        $report = [
            'warnings' => [],
            'errors' => []
        ];

        // 必须包含的章节
        $requiredSections = [
            '概述' => ['概述', '简介', '介绍', 'Overview'],
            '快速开始' => ['快速开始', '快速入门', 'Quick Start', 'Getting Started'],
            '详细说明' => ['详细说明', '详细文档', '详细', 'Details', 'Documentation'],
            '示例' => ['示例', '例子', 'Example', 'Examples', '代码示例']
        ];

        $foundSections = [];
        foreach ($requiredSections as $sectionName => $keywords) {
            foreach ($keywords as $keyword) {
                // 检查是否包含该章节（通过标题检测）
                if (preg_match('/^#+\s*' . preg_quote($keyword, '/') . '/mi', $content)) {
                    $foundSections[$sectionName] = true;
                    break;
                }
            }
        }

        // 检查缺失的章节
        foreach ($requiredSections as $sectionName => $keywords) {
            if (!isset($foundSections[$sectionName])) {
                $report['warnings'][] = "模块 {$moduleName} 的 extends.md 缺少 '{$sectionName}' 章节";
            }
        }

        // 检查是否包含代码示例
        if (!preg_match('/```(?:php|html|javascript|js|css)/i', $content)) {
            $report['warnings'][] = "模块 {$moduleName} 的 extends.md 缺少代码示例";
        }

        return $report;
    }

    /**
     * 检查 doc/ 目录
     *
     * @param string $docDir doc 目录路径
     * @param string $moduleName 模块名
     * @param array $data 模块扩展数据
     * @return array
     */
    private function checkDocDirectory(string $docDir, string $moduleName, array $data): array
    {
        $report = [
            'warnings' => [],
            'errors' => []
        ];

        // 检查是否有扩展相关的文档文件
        $docFiles = glob($docDir . DIRECTORY_SEPARATOR . '*extends*.md');
        $docFiles = array_merge($docFiles, glob($docDir . DIRECTORY_SEPARATOR . '*扩展*.md'));

        if (empty($docFiles)) {
            $report['warnings'][] = "模块 {$moduleName} 的 doc/ 目录下缺少扩展功能相关的文档";
        }

        // 如果有扩展定义，检查是否有对应的文档
        if (!empty($data['extends'])) {
            foreach ($data['extends'] as $extendName => $extendConfig) {
                $extendDocFound = false;
                foreach ($docFiles as $docFile) {
                    $docContent = file_get_contents($docFile);
                    if (stripos($docContent, $extendName) !== false) {
                        $extendDocFound = true;
                        break;
                    }
                }
                if (!$extendDocFound) {
                    $report['warnings'][] = "模块 {$moduleName} 的扩展点 '{$extendName}' 缺少对应的文档说明";
                }
            }
        }

        return $report;
    }
}

