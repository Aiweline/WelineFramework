<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Exception;

/**
 * 阶段更新抽象类
 * 
 * 提供通用的阶段管理功能
 * 
 * @package Weline\Framework\Setup\Stage
 */
abstract class AbstractStage implements StageInterface
{
    /**
     * @var bool 是否已准备
     */
    protected bool $prepared = false;
    
    /**
     * @var bool 是否已提交
     */
    protected bool $committed = false;
    
    /**
     * @var array 备份数据（用于回滚）
     */
    protected array $backup = [];
    
    /**
     * @var array 阶段数据
     */
    protected array $stageData = [];
    
    /**
     * @var array 错误信息
     */
    protected array $errors = [];
    
    /**
     * @inheritDoc
     */
    public function isPrepared(): bool
    {
        return $this->prepared;
    }
    
    /**
     * @inheritDoc
     */
    public function isCommitted(): bool
    {
        return $this->committed;
    }
    
    /**
     * @inheritDoc
     */
    public function validate(): bool
    {
        // 默认验证：检查是否已准备
        if (!$this->prepared) {
            $this->errors[] = __('阶段 %{1} 尚未准备', [$this->getName()]);
            return false;
        }
        
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function getStatus(): array
    {
        return [
            'name' => $this->getName(),
            'prepared' => $this->prepared,
            'committed' => $this->committed,
            'errors' => $this->errors,
            'has_backup' => !empty($this->backup),
        ];
    }
    
    /**
     * 添加错误信息
     * 
     * @param string $error
     * @return void
     */
    protected function addError(string $error): void
    {
        $this->errors[] = $error;
    }
    
    /**
     * 清除错误信息
     * 
     * @return void
     */
    protected function clearErrors(): void
    {
        $this->errors = [];
    }
    
    /**
     * 创建备份
     * 
     * @param string $key 备份键
     * @param mixed $data 备份数据
     * @return void
     */
    protected function createBackup(string $key, $data): void
    {
        $this->backup[$key] = $data;
    }
    
    /**
     * 获取备份数据
     * 
     * @param string $key 备份键
     * @return mixed|null
     */
    protected function getBackup(string $key)
    {
        return $this->backup[$key] ?? null;
    }
    
    /**
     * 清除备份
     * 
     * @return void
     */
    protected function clearBackup(): void
    {
        $this->backup = [];
    }
}
