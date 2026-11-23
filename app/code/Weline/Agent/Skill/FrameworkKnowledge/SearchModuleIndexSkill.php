<?php
declare(strict_types=1);

namespace Weline\Agent\Skill\FrameworkKnowledge;

use Weline\Agent\Service\SkillResult;

/**
 * 模块索引检索（doc 下 *INDEX* / AI-INDEX / 文档索引 等索引文件）
 */
class SearchModuleIndexSkill extends FrameworkKnowledgeSkill
{
    public function getCode(): string
    {
        return 'search_module_index';
    }

    public function getDescription(): string
    {
        return '在模块文档索引文件（*INDEX*.md、AI-INDEX.md、文档索引.md 等）中按关键词检索，适合先定位文档再精读。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => '搜索关键词'],
                'limit' => ['type' => 'integer', 'default' => 20],
            ],
            'required' => ['keyword'],
        ];
    }

    protected function run(array $params): SkillResult
    {
        $keyword = trim((string)($params['keyword'] ?? ''));
        $limit = max(1, min(50, (int)($params['limit'] ?? 20)));
        if ($keyword === '') {
            return SkillResult::error('keyword is required');
        }

        // 逐个索引文件名模式检索（文件名级过滤在 grepDocs 内按 filePattern 做单正则，
        // 这里改为收集索引文件后逐个 grep）
        $root = $this->codeRoot();
        $indexFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with(strtolower($file->getFilename()), '.md')) {
                continue;
            }
            $name = $file->getFilename();
            if (stripos($name, 'index') !== false || str_contains($name, '索引')) {
                $indexFiles[] = str_replace('\\', '/', $file->getPathname());
            }
            if (count($indexFiles) >= 200) {
                break;
            }
        }

        $results = [];
        $kw = mb_strtolower($keyword);
        foreach ($indexFiles as $path) {
            if (count($results) >= $limit) {
                break;
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }
            foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
                if (mb_stripos($line, $kw) !== false) {
                    $results[] = [
                        'path' => str_replace($root . '/', '', $path),
                        'snippet' => trim($line),
                    ];
                    break; // 每个索引文件取首个命中行
                }
            }
        }

        return SkillResult::success([
            'keyword' => $keyword,
            'count' => count($results),
            'results' => $results,
        ]);
    }
}
