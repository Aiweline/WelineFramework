<?php
declare(strict_types=1);

namespace Weline\Bot\Interface;

use Weline\Bot\Service\SkillContext;
use Weline\Bot\Service\SkillResult;

/**
 * 技能接口
 *
 * 所有技能必须实现此接口
 */
interface SkillInterface
{
    /**
     * 获取技能代码（唯一标识）
     */
    public function getCode(): string;

    /**
     * 获取技能名称
     */
    public function getName(): string;

    /**
     * 获取技能描述
     */
    public function getDescription(): string;

    /**
     * 获取分类
     *
     * filesystem/shell/browser/api/database/code
     */
    public function getCategory(): string;

    /**
     * 获取参数定义（JSON Schema 格式）
     */
    public function getParameters(): array;

    /**
     * 获取所需权限
     *
     * @return array 权限标识数组
     */
    public function getPermissionRequired(): array;

    /**
     * 执行技能
     *
     * @param array $params 参数
     * @param SkillContext $context 执行上下文
     * @return SkillResult
     */
    public function execute(array $params, SkillContext $context): SkillResult;

    /**
     * 是否危险操作
     */
    public function isDangerous(): bool;

    /**
     * 是否需要用户确认
     */
    public function requiresConfirmation(): bool;

    /**
     * 是否启用
     */
    public function isEnabled(): bool;
}
