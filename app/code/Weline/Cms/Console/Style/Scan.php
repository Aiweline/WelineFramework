<?php

declare(strict_types=1);

/*
 * Weline Cms Module
 * CMS内容管理系统样式扫描命令
 */

namespace Weline\Cms\Console\Style;

use Weline\Cms\Model\Style;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Scan implements CommandInterface
{

    private Style $styleModel;
    private Printing $printer;

    public function __construct(
        Style $styleModel,
        Printing $printer
    ) {
        $this->styleModel = $styleModel;
        $this->printer = $printer;
    }

    /**
     * 执行命令
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->printer->setup(__('开始扫描样式模板...'));

        // 样式模板基础路径
        $baseStylePath = BP . 'app/code/Weline/Cms/view/templates/style/';

        if (!is_dir($baseStylePath)) {
            $this->printer->error('样式目录不存在：' . $baseStylePath);
            return 1;
        }

        // 扫描样式目录
        $styleDirs = glob($baseStylePath . '*', GLOB_ONLYDIR);

        if (empty($styleDirs)) {
            $this->printer->warning('未找到任何样式模板');
            return 0;
        }

        $scannedCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $errorCount = 0;

        foreach ($styleDirs as $styleDir) {
            $scannedCount++;
            $styleName = basename($styleDir);
            
            $this->printer->note(__('扫描样式：%{1}', [$styleName]));

            // 检查必需文件
            $headerFile = $styleDir . '/header.phtml';
            $footerFile = $styleDir . '/footer.phtml';
            $readmeFile = $styleDir . '/readme.md';

            $errors = [];
            if (!file_exists($headerFile)) {
                $errors[] = 'header.phtml';
            }
            if (!file_exists($footerFile)) {
                $errors[] = 'footer.phtml';
            }
            if (!file_exists($readmeFile)) {
                $errors[] = 'readme.md';
            }

            if (!empty($errors)) {
                $this->printer->error(__('  缺少必需文件：%{1}', [implode(', ', $errors)]));
                $errorCount++;
                continue;
            }

            // 读取README内容
            $readmeContent = file_get_contents($readmeFile);
            $description = $this->extractDescription($readmeContent);

            // 相对路径
            $relativePath = 'style/' . $styleName;

            // 检查数据库中是否已存在
            $existingStyle = clone $this->styleModel;
            $existingStyle->clear()
                ->where(Style::fields_CODE, $styleName)
                ->find()
                ->fetch();

            if ($existingStyle->getId()) {
                // 更新现有样式
                $existingStyle->setData(Style::fields_NAME, $this->formatStyleName($styleName))
                    ->setData(Style::fields_DESCRIPTION, $description)
                    ->setData(Style::fields_PATH, $relativePath)
                    ->save();

                $this->printer->success(__('  ✓ 已更新'));
                $updatedCount++;
            } else {
                // 创建新样式
                $newStyle = clone $this->styleModel;
                $newStyle->clearData()
                    ->setData(Style::fields_CODE, $styleName)
                    ->setData(Style::fields_NAME, $this->formatStyleName($styleName))
                    ->setData(Style::fields_DESCRIPTION, $description)
                    ->setData(Style::fields_PATH, $relativePath)
                    ->setData(Style::fields_IS_ACTIVE, 1)
                    ->setData(Style::fields_SORT_ORDER, $scannedCount * 10)
                    ->save(true);

                $this->printer->success(__('  ✓ 已创建 (ID: %{1})', [$newStyle->getId()]));
                $createdCount++;
            }

            $this->printer->note(__('  路径：%{1}', [$relativePath]));
            $this->printer->note(__('  描述：%{1}', [mb_substr($description, 0, 50) . '...']));
        }

        // 总结
        $this->printer->note('');
        $this->printer->setup('=====================================');
        $this->printer->setup(__('扫描完成！'));
        $this->printer->setup(__('  扫描总数：%{1}', [$scannedCount]));
        $this->printer->success(__('  新建样式：%{1}', [$createdCount]));
        $this->printer->success(__('  更新样式：%{1}', [$updatedCount]));
        if ($errorCount > 0) {
            $this->printer->error(__('  错误数量：%{1}', [$errorCount]));
        }
        $this->printer->setup('=====================================');

        return 0;
    }

    /**
     * 从README中提取描述
     */
    private function extractDescription(string $content): string
    {
        // 移除markdown标题
        $content = preg_replace('/^#.*$/m', '', $content);
        
        // 获取第一段非空内容
        $lines = explode("\n", trim($content));
        $description = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $description = $line;
                break;
            }
        }
        
        return $description ?: '无描述';
    }

    /**
     * 格式化样式名称
     */
    private function formatStyleName(string $code): string
    {
        // 将下划线或连字符转换为空格，并首字母大写
        $name = str_replace(['_', '-'], ' ', $code);
        return ucwords($name);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '扫描并注册页面样式模板';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'cms:style:scan',
            '扫描 view/templates/style/ 目录下的样式模板并注册到数据库',
            [
                '-h, --help' => '显示帮助信息',
            ],
            [
                '样式模板目录结构：',
                '  view/templates/style/<style_code>/',
                '    ├── header.phtml  (必需)',
                '    ├── footer.phtml  (必需)',
                '    ├── content.phtml (可选)',
                '    └── readme.md     (必需)',
                '',
                '样式配置标记：',
                '  在模板文件中使用 @fields_start ... @fields_end 定义配置项',
                '  格式：key => 标签:类型:默认值|选项',
            ],
            [
                '扫描并注册所有样式模板' => 'php bin/w cms:style:scan',
            ],
            'php bin/w cms:style:scan'
        );
    }
}
