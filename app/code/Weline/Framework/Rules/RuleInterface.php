<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Rules;

use Weline\Framework\App\Exception;

/**
 * 框架规则接口
 * 
 * 职责：定义框架约束规则的统一接口
 * 
 * 所有框架约束规则必须实现此接口，确保：
 * - 规则名称、简述、描述等信息完整
 * - 统一的验证方法
 * - 统一的错误报告格式
 */
interface RuleInterface
{
    /**
     * 获取规则名称
     * 
     * @return string 规则名称（简短，用于标识）
     */
    public function getName(): string;
    
    /**
     * 获取规则简述
     * 
     * @return string 规则简述（一句话描述规则的目的）
     */
    public function getBrief(): string;
    
    /**
     * 获取规则详细描述
     * 
     * @return string 规则详细描述（说明规则的具体要求、约束条件等）
     */
    public function getDescription(): string;
    
    /**
     * 获取规则优先级
     * 
     * 数值越小优先级越高，0 为最高优先级
     * 
     * @return int 优先级（0-100）
     */
    public function getPriority(): int;
    
    /**
     * 验证规则
     * 
     * @return void
     * @throws Exception 如果规则验证失败，抛出异常并包含详细的错误信息
     */
    public function validate(): void;
    
    /**
     * 获取规则分类
     * 
     * @return string 规则分类（如：test、code-style、security 等）
     */
    public function getCategory(): string;
}
