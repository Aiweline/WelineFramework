<?php
declare(strict_types=1);

namespace Weline\Agent\Interface;

/**
 * 智能体插件接口
 *
 * 插件 = 可安装能力包：向角色贡献技能码、提示词片段、权限与可选
 * 任务型智能体引用。实现在 extends/module/Weline_Agent/Plugin 下，
 * 由 PluginScanner 扫描登记到 weline_agent_plugin，角色通过
 * plugins 字段绑定后形成有效能力并集。
 */
interface AgentPluginInterface
{
    /** 插件唯一代码（kebab/snake，≤64 字符） */
    public function getCode(): string;

    public function getName(): string;

    public function getVersion(): string;

    /**
     * 插件清单
     *
     * @return array{
     *   skills?: list<string>,
     *   prompt_fragments?: list<string>,
     *   permissions?: list<string>,
     *   agents?: list<string>,
     *   ui_panels?: list<string>
     * }
     */
    public function getManifest(): array;
}
