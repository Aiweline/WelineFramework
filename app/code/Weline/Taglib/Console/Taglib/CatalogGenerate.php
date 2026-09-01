<?php

declare(strict_types=1);

namespace Weline\Taglib\Console\Taglib;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Taglib\TaglibRegistry;

class CatalogGenerate implements CommandInterface
{
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    public function execute(array $args = [], array $data = []): void
    {
        $outputPath = BP . 'app' . DIRECTORY_SEPARATOR . 'code'
            . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Taglib'
            . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . '标签全量索引.md';

        $content = $this->buildMarkdown();
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_put_contents($outputPath, $content, LOCK_EX) === false) {
            $this->printing->error(__('无法写入 %{1}', [$outputPath]));
            return;
        }
        $this->printing->success(__('标签全量索引已生成：%{1}', [$outputPath]));
    }

    private function buildMarkdown(): string
    {
        $lines = [];
        $lines[] = '<!-- weline:taglib-catalog:auto-generated -->';
        $lines[] = '# Taglib 标签全量索引';
        $lines[] = '';
        $lines[] = '> 本文件由 `php bin/w taglib:catalog:generate` 自动生成。**禁止手改正文**；变更标签后重新运行命令。';
        $lines[] = '';
        $lines[] = '生成时间：' . date('Y-m-d H:i:s');
        $lines[] = '';
        $lines[] = '场景选型见 [场景映射表.md](./场景映射表.md)。';
        $lines[] = '';
        $lines[] = '## 框架内置标签';
        $lines[] = '';
        $lines[] = '权威目录：[Framework/doc/4-内置标签/README.md](../../Framework/doc/4-内置标签/README.md)';
        $lines[] = '';

        $builtinDir = BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'Framework' . DIRECTORY_SEPARATOR . 'doc'
            . DIRECTORY_SEPARATOR . '4-内置标签';
        if (is_dir($builtinDir)) {
            $files = glob($builtinDir . DIRECTORY_SEPARATOR . '*.md') ?: [];
            sort($files);
            foreach ($files as $file) {
                $base = basename($file);
                if ($base === 'README.md') {
                    continue;
                }
                $rel = '../../Framework/doc/4-内置标签/' . $base;
                $lines[] = '- [' . $base . '](' . $rel . ')';
            }
        }

        $lines[] = '';
        $lines[] = '## 扩展标签（generated/taglibs.php）';
        $lines[] = '';
        $lines[] = '| 标签名 | 模块 | 类 | 文档摘要 |';
        $lines[] = '|--------|------|-----|----------|';

        $registryFile = TaglibRegistry::REGISTRY_FILE;
        if (!file_exists($registryFile)) {
            $lines[] = '| _（注册表不存在，请先运行 setup:upgrade 或 taglib:collect）_ | | | |';
        } else {
            $registry = include $registryFile;
            $tags = is_array($registry['tags'] ?? null) ? $registry['tags'] : [];
            ksort($tags);
            foreach ($tags as $name => $config) {
                if (!is_array($config)) {
                    continue;
                }
                $module = (string) ($config['module_name'] ?? '-');
                $class = (string) ($config['class'] ?? '-');
                $doc = trim(strip_tags((string) ($config['doc'] ?? '')));
                $doc = str_replace(['|', "\n", "\r"], ['/', ' ', ''], $doc);
                if (strlen($doc) > 120) {
                    $doc = substr($doc, 0, 117) . '...';
                }
                if ($doc === '') {
                    $doc = '-';
                }
                $lines[] = sprintf(
                    '| `%s` | %s | `%s` | %s |',
                    $name,
                    $module,
                    $class,
                    $doc,
                );
            }
            $lines[] = '';
            $lines[] = '共 **' . count($tags) . '** 个扩展标签。';
        }

        $lines[] = '';
        $lines[] = '## 相关';
        $lines[] = '';
        $lines[] = '- [场景映射表.md](./场景映射表.md)';
        $lines[] = '- [AI硬规则索引.md](../../Ai/doc/AI硬规则索引.md)';
        $lines[] = '';

        return implode("\n", $lines);
    }

    public function tip(): string
    {
        return __('生成 Taglib 标签全量索引 Markdown');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'taglib:catalog:generate',
            $this->tip(),
            [
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('生成 Taglib/doc/标签全量索引.md') => 'taglib:catalog:generate',
            ],
        );
    }
}
