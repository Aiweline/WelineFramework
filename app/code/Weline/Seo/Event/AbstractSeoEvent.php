<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Event;

use Weline\Framework\DataObject\DataObject;
use Weline\Seo\Interface\EventContractInterface;

/**
 * SEO事件抽象基类
 * 
 * 提供标准化的事件数据结构和验证
 * 
 * @package Weline_Seo
 */
abstract class AbstractSeoEvent extends DataObject implements EventContractInterface
{
    /**
     * 事件元数据
     */
    protected const EVENT_NAME = '';
    protected const EVENT_VERSION = '1.0.0';
    protected const EVENT_TYPE = 'domain'; // domain, integration, application
    protected const EVENT_DESCRIPTION = '';

    /**
     * 构造函数
     * 
     * @param array $data 事件数据
     */
    public function __construct(array $data = [])
    {
        // 验证数据契约
        if (!empty($data)) {
            $this->validateData($data);
        }
        
        parent::__construct($data);
    }

    /**
     * 获取事件名称
     */
    public function getEventName(): string
    {
        return static::EVENT_NAME;
    }

    /**
     * 获取事件版本
     */
    public function getVersion(): string
    {
        return static::EVENT_VERSION;
    }

    /**
     * 获取事件类型
     */
    public function getEventType(): string
    {
        return static::EVENT_TYPE;
    }

    /**
     * 获取事件描述
     */
    public function getDescription(): string
    {
        return static::EVENT_DESCRIPTION;
    }

    /**
     * 获取数据契约
     * 
     * 子类必须实现此方法，定义事件的数据契约
     */
    abstract public function getDataContract(): array;

    /**
     * 验证事件数据是否符合契约
     */
    public function validateData(array $data): bool
    {
        $contract = $this->getDataContract();
        
        foreach ($contract as $field => $definition) {
            $required = $definition['required'] ?? false;
            $type = $definition['type'] ?? 'mixed';
            
            // 检查必填字段
            if ($required && !isset($data[$field])) {
                throw new \InvalidArgumentException(
                    sprintf(
                        '事件 %s 缺少必填字段: %s',
                        $this->getEventName(),
                        $field
                    )
                );
            }
            
            // 如果字段存在，检查类型
            if (isset($data[$field])) {
                $this->validateFieldType($field, $data[$field], $type);
            }
        }
        
        return true;
    }

    /**
     * 验证字段类型
     * 
     * @param string $field 字段名
     * @param mixed $value 字段值
     * @param string $expectedType 期望类型
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateFieldType(string $field, $value, string $expectedType): void
    {
        $typeMap = [
            'string' => 'is_string',
            'integer' => 'is_int',
            'float' => 'is_float',
            'boolean' => 'is_bool',
            'array' => 'is_array',
            'object' => 'is_object',
        ];
        
        // 处理联合类型（如 string|integer）
        $types = explode('|', $expectedType);
        $valid = false;
        
        foreach ($types as $type) {
            $type = trim($type);
            
            if (isset($typeMap[$type])) {
                if ($typeMap[$type]($value)) {
                    $valid = true;
                    break;
                }
            } elseif ($type === 'mixed') {
                $valid = true;
                break;
            }
        }
        
        if (!$valid) {
            throw new \InvalidArgumentException(
                sprintf(
                    '事件 %s 字段 %s 类型错误，期望 %s，实际 %s',
                    $this->getEventName(),
                    $field,
                    $expectedType,
                    gettype($value)
                )
            );
        }
    }

    /**
     * 获取事件时间戳
     * 
     * @return string ISO 8601格式的时间戳
     */
    public function getTimestamp(): string
    {
        return date('c'); // ISO 8601格式
    }

    /**
     * 获取事件ID（唯一标识）
     * 
     * @return string 事件ID
     */
    public function getEventId(): string
    {
        if (!$this->hasData('event_id')) {
            $this->setData('event_id', uniqid('seo_event_', true));
        }
        return $this->getData('event_id');
    }
}

