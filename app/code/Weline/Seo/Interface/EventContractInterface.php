<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Interface;

/**
 * 事件契约接口
 * 
 * 按照国际标准设计的事件数据契约，确保事件数据的标准化和类型安全
 * 
 * @package Weline_Seo
 */
interface EventContractInterface
{
    /**
     * 获取事件名称
     * 
     * @return string 事件名称，格式：模块名::事件类型::事件名称
     */
    public function getEventName(): string;

    /**
     * 获取事件版本
     * 
     * @return string 事件版本，格式：主版本.次版本.修订版本
     */
    public function getVersion(): string;

    /**
     * 获取事件类型
     * 
     * @return string 事件类型：domain, integration, application
     */
    public function getEventType(): string;

    /**
     * 获取事件数据契约
     * 
     * @return array 数据契约定义，格式：
     *   [
     *     'field_name' => [
     *       'type' => 'string|integer|array|object',
     *       'required' => true|false,
     *       'description' => '字段说明',
     *       'default' => '默认值（可选）',
     *     ],
     *     ...
     *   ]
     */
    public function getDataContract(): array;

    /**
     * 验证事件数据是否符合契约
     * 
     * @param array $data 事件数据
     * @return bool 是否符合契约
     * @throws \InvalidArgumentException 如果数据不符合契约
     */
    public function validateData(array $data): bool;

    /**
     * 获取事件描述
     * 
     * @return string 事件描述
     */
    public function getDescription(): string;
}

