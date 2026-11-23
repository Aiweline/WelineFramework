<?php
declare(strict_types=1);

namespace Weline\Agent\Skill\FrameworkKnowledge;

use Weline\Agent\Service\SkillResult;

/**
 * 读取模块文档文件（app/code 各模块 doc 目录，限定 .md/.txt）
 */
class ReadDocFileSkill extends FrameworkKnowledgeSkill
{
    public function getCode(): string
    {
        return 'read_doc_file';
    }

    public function getDescription(): string
    {
        return '读取 app/code/{Module}/doc/ 下的文档文件内容（Markdown/文本，单文件上限约 200KB）。';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => '相对 app/code 的文档路径，如 Weline/Agent/doc/需求.md',
                ],
            ],
            'required' => ['path'],
        ];
    }

    protected function run(array $params): SkillResult
    {
        $path = trim((string)($params['path'] ?? ''));
        if ($path === '') {
            return SkillResult::error('path is required');
        }
        return $this->readDocFile($path);
    }
}
