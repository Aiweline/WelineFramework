<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Model;

use Weline\Framework\DataObject\DataObject;

/**
 * 支付结果对象
 */
class PaymentResult extends DataObject
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    
    /**
     * 是否成功
     * 
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->getData('status') === self::STATUS_SUCCESS;
    }
    
    /**
     * 是否失败
     * 
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->getData('status') === self::STATUS_FAILED;
    }
    
    /**
     * 是否待处理
     * 
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->getData('status') === self::STATUS_PENDING;
    }
    
    /**
     * 是否处理中
     * 
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->getData('status') === self::STATUS_PROCESSING;
    }
    
    /**
     * 创建成功结果
     * 
     * @param array $data 数据
     * @return static
     */
    public static function success(array $data = []): static
    {
        $result = new static();
        $result->setData('status', self::STATUS_SUCCESS);
        $result->setData('message', $data['message'] ?? __('支付成功'));
        foreach ($data as $key => $value) {
            if ($key !== 'message') {
                $result->setData($key, $value);
            }
        }
        return $result;
    }
    
    /**
     * 创建失败结果
     * 
     * @param string $message 错误消息
     * @param array $data 其他数据
     * @return static
     */
    public static function failed(string $message, array $data = []): static
    {
        $result = new static();
        $result->setData('status', self::STATUS_FAILED);
        $result->setData('message', $message);
        foreach ($data as $key => $value) {
            $result->setData($key, $value);
        }
        return $result;
    }
    
    /**
     * 创建待处理结果
     * 
     * @param array $data 数据
     * @return static
     */
    public static function pending(array $data = []): static
    {
        $result = new static();
        $result->setData('status', self::STATUS_PENDING);
        $result->setData('message', $data['message'] ?? __('支付待处理'));
        foreach ($data as $key => $value) {
            if ($key !== 'message') {
                $result->setData($key, $value);
            }
        }
        return $result;
    }
    
    /**
     * 创建处理中结果
     * 
     * @param array $data 数据
     * @return static
     */
    public static function processing(array $data = []): static
    {
        $result = new static();
        $result->setData('status', self::STATUS_PROCESSING);
        $result->setData('message', $data['message'] ?? __('支付处理中'));
        foreach ($data as $key => $value) {
            if ($key !== 'message') {
                $result->setData($key, $value);
            }
        }
        return $result;
    }
}

