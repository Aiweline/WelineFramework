<?php
declare(strict_types=1);

namespace Weline\Agent\Skill\FrameworkKnowledge;

use Weline\Agent\Interface\SkillInterface;
use Weline\Agent\Service\SkillContext;
use Weline\Agent\Service\SkillResult;

/**
 * 框架知识技能基类
 *
 * 为 framework_knowledge 插件提供只读知识检索能力：
 * 模块文档搜索、索引检索、代码 3D 图查询、文档文件读取。
 * 全部只读、无危险操作、无需确认。
 */
abstract class FrameworkKnowledgeSkill implements SkillInterface
{
    public function getName(): string
    {
        return $this->getCode();
    }

    public function getCategory(): string
    {
        return 'knowledge';
    }

    public function getPermissionRequired(): array
    {
        return [];
    }

    public function isDangerous(): bool
    {
        return false;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function execute(array $params, SkillContext $context): SkillResult
    {
        return $this->run($params);
    }

    /** @return array<string,mixed> JSON Schema */
    abstract public function getParameters(): array;

    abstract protected function run(array $params): SkillResult;

    /** app/code 根目录 */
    protected function codeRoot(): string
    {
        // app/code/Weline/Agent/Skill/FrameworkKnowledge → app/code
        return dirname(__DIR__, 4);
    }

    /** 仓库根（bin/w 所在） */
    protected function projectRoot(): string
    {
        return dirname($this->codeRoot(), 2);
    }

    /**
     * 在各模块 doc 目录下递归搜索关键词，返回 path+snippet 列表
     *
     * @return list<array{path:string,snippet:string}>
     */
    protected function grepDocs(string $keyword, int $limit = 20, ?string $filePattern = '*.md'): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $root = $this->codeRoot();
        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (count($results) >= $limit) {
                break;
            }
            /** @var \SplFileInfo $file */
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_contains($path, '/doc/')) {
                continue;
            }
            if ($filePattern !== null && !$file->isFile()) {
                continue;
            }
            if ($filePattern !== null && !preg_match('/' . preg_quote($filePattern, '/') . '$/i', $file->getFilename())) {
                continue;
            }
            $size = $file->getSize();
            if ($size === false || $size > 2_000_000) {
                continue;
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }
            $pos = mb_stripos($content, $keyword);
            if ($pos === false) {
                continue;
            }
            $start = max(0, $pos - 80);
            $snippet = mb_substr($content, $start, 240);
            $results[] = [
                'path' => str_replace($root . '/', '', $path),
                'snippet' => trim(preg_replace('/\s+/', ' ', $snippet) ?? $snippet),
            ];
        }
        return $results;
    }

    /**
     * 读取 app/code 内的文档文件（限定 .md/.txt，禁止路径逃逸）
     */
    protected function readDocFile(string $relativePath): SkillResult
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        // 只允许 app/code 下的文档文件
        if (!preg_match('#^(Weline|[A-Za-z]+)/[^/]+/doc/.+\.(md|txt|markdown)$#i', $relativePath)) {
            return SkillResult::error('Only doc files under app/code/*/doc are readable');
        }
        if (str_contains($relativePath, '..')) {
            return SkillResult::error('Path traversal is not allowed');
        }
        $full = $this->codeRoot() . '/' . $relativePath;
        $real = realpath($full);
        if ($real === false || !is_file($real) || !str_starts_with($real, $this->codeRoot())) {
            return SkillResult::error('Doc file not found');
        }
        $content = file_get_contents($real);
        if ($content === false) {
            return SkillResult::error('Failed to read doc file');
        }
        if (strlen($content) > 200_000) {
            $content = mb_substr($content, 0, 200_000) . "\n...[truncated]";
        }
        return SkillResult::success(['path' => $relativePath, 'content' => $content]);
    }

    /** 执行只读 CLI 查询（code3d:query），超时受限 */
    protected function runCode3dQuery(array $args, int $timeout = 15): SkillResult
    {
        $cmd = 'php ' . escapeshellarg($this->projectRoot() . '/bin/w') . ' code3d:query '
            . implode(' ', array_map('escapeshellarg', $args)) . ' --json 2>/dev/null';
        $output = [];
        $code = 1;
        try {
            exec($cmd, $output, $code);
        } catch (\Throwable $e) {
            return SkillResult::error('code3d query failed: ' . $e->getMessage());
        }
        $text = implode("\n", $output);
        if ($code !== 0 || trim($text) === '') {
            return SkillResult::error('code3d query returned no data (index may not be built)');
        }
        $decoded = json_decode($text, true);
        return SkillResult::success($decoded ?? ['raw' => mb_substr($text, 0, 50_000)]);
    }
}
