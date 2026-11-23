<?php

declare(strict_types=1);

namespace Weline\Agent\Setup;

use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentSkill;
use Weline\Agent\Service\PluginScanner;
use Weline\Framework\Manager\ObjectManager;

/**
 * framework_knowledge 插件种子
 *
 * 1. 写入 4 个框架知识技能行（weline_agent_skill）；
 * 2. PluginScanner->sync() 扫描登记插件；
 * 3. general 默认角色绑定 framework_knowledge 插件。
 */
final class PluginSeeder
{
    private const NS = 'Weline\\Agent\\Skill\\FrameworkKnowledge\\';

    /** @return list<array<string,mixed>> */
    private function skillRows(): array
    {
        return [
            [
                'code' => 'search_module_docs',
                'name' => '模块文档搜索',
                'description' => '在 app/code/*/doc 下的 Markdown 文档中按关键词全文搜索',
                'class_name' => self::NS . 'SearchModuleDocsSkill',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => '搜索关键词'],
                        'limit' => ['type' => 'integer', 'default' => 20],
                    ],
                    'required' => ['keyword'],
                ],
            ],
            [
                'code' => 'search_module_index',
                'name' => '模块索引检索',
                'description' => '在模块文档索引文件（*INDEX*、AI-INDEX、文档索引）中按关键词检索',
                'class_name' => self::NS . 'SearchModuleIndexSkill',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => '搜索关键词'],
                        'limit' => ['type' => 'integer', 'default' => 20],
                    ],
                    'required' => ['keyword'],
                ],
            ],
            [
                'code' => 'query_code_graph',
                'name' => '代码图谱查询',
                'description' => '查询代码 3D 图：类/方法/调用关系/模块（code3d:query）',
                'class_name' => self::NS . 'QueryCodeGraphSkill',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => ['stats', 'find', 'class', 'method', 'callers', 'callees', 'modules', 'flow']],
                        'target' => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'default' => 20],
                    ],
                    'required' => ['action'],
                ],
            ],
            [
                'code' => 'read_doc_file',
                'name' => '读取文档文件',
                'description' => '读取 app/code/{Module}/doc/ 下的文档文件（.md/.txt）',
                'class_name' => self::NS . 'ReadDocFileSkill',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => '相对 app/code 的文档路径'],
                    ],
                    'required' => ['path'],
                ],
            ],
        ];
    }

    public function seed(): void
    {
        $this->seedSkills();
        $this->syncPlugins();
        $this->bindGeneralRole();
    }

    private function seedSkills(): void
    {
        try {
            $prototype = ObjectManager::getInstance(AgentSkill::class);
        } catch (\Throwable) {
            return;
        }

        foreach ($this->skillRows() as $row) {
            try {
                $existing = clone $prototype;
                $existing->reset()->clearQuery()
                    ->where(AgentSkill::schema_fields_CODE, $row['code'])
                    ->find()->fetch();

                if ($existing->getId()) {
                    continue;
                }

                $skill = clone $prototype;
                $skill->reset()->clearQuery();
                $skill->setData(AgentSkill::schema_fields_CODE, $row['code']);
                $skill->setData(AgentSkill::schema_fields_NAME, $row['name']);
                $skill->setData(AgentSkill::schema_fields_DESCRIPTION, $row['description']);
                $skill->setData(AgentSkill::schema_fields_CATEGORY, 'knowledge');
                $skill->setData(AgentSkill::schema_fields_CLASS_NAME, $row['class_name']);
                $skill->setParameters($row['parameters']);
                $skill->setData(AgentSkill::schema_fields_PERMISSION_REQUIRED, '[]');
                $skill->setData(AgentSkill::schema_fields_IS_DANGEROUS, 0);
                $skill->setData(AgentSkill::schema_fields_REQUIRES_CONFIRMATION, 0);
                $skill->setData(AgentSkill::schema_fields_MODULE, 'Weline_Agent');
                $skill->setData(AgentSkill::schema_fields_IS_ACTIVE, 1);
                $skill->setData(AgentSkill::schema_fields_IS_BUILTIN, 1);
                $now = time();
                $skill->setData('created_at', $now);
                $skill->setData('updated_at', $now);
                $skill->save();
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function syncPlugins(): void
    {
        try {
            (ObjectManager::getInstance(PluginScanner::class))->sync();
        } catch (\Throwable) {
        }
    }

    /** general 默认角色绑定 framework_knowledge 插件（幂等） */
    private function bindGeneralRole(): void
    {
        try {
            $prototype = ObjectManager::getInstance(AgentRole::class);
            $role = clone $prototype;
            $role->reset()->clearQuery()
                ->where(AgentRole::schema_fields_CODE, GeneralRoleSeeder::ROLE_CODE)
                ->find()->fetch();
            if (!$role->getId()) {
                return;
            }
            $plugins = $role->getPlugins();
            if (!in_array('framework_knowledge', $plugins, true)) {
                $plugins[] = 'framework_knowledge';
                $role->setPlugins($plugins);
                $role->save();
            }
        } catch (\Throwable) {
        }
    }
}
