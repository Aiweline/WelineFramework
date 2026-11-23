<?php
declare(strict_types=1);

namespace Weline\Agent\Extends\Module\Weline_Agent\Plugin;

use Weline\Agent\Interface\AgentPluginInterface;

/**
 * 框架知识插件（内置）
 *
 * 向绑定角色注入框架文档/代码图谱只读检索能力：
 * search_module_docs / search_module_index / query_code_graph / read_doc_file。
 */
class FrameworkKnowledgePlugin implements AgentPluginInterface
{
    public function getCode(): string
    {
        return 'framework_knowledge';
    }

    public function getName(): string
    {
        return '框架知识库';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getManifest(): array
    {
        return [
            'skills' => [
                'search_module_docs',
                'search_module_index',
                'query_code_graph',
                'read_doc_file',
            ],
            'prompt_fragments' => [
                '你可以使用 framework_knowledge 技能检索框架模块文档与代码结构。回答框架相关问题时，优先用 search_module_index 定位、read_doc_file 精读、query_code_graph 查类/方法/调用关系。',
            ],
            'permissions' => [],
            'agents' => [],
            'ui_panels' => [],
        ];
    }
}
