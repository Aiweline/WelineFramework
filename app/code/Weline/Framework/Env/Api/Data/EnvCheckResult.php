<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Api\Data;

/**
 * 环境检测结果数据对象
 * 
 * @DESC 封装环境检测的结果，包含详细信息和状态
 */
class EnvCheckResult
{
    /** @var bool 是否有错误 */
    private bool $hasError = false;

    /** @var array 检测详情 [key => status] */
    private array $details = [];

    /** @var array 未满足的扩展列表 */
    private array $missingExtensions = [];

    /** @var array 被禁用的函数列表 */
    private array $disabledFunctions = [];

    /** @var array 未满足的 items 列表 */
    private array $unsatisfiedItems = [];

    /** @var string|null PHP 版本问题描述 */
    private ?string $phpVersionIssue = null;

    /** @var array 缺失的推荐扩展列表（不影响 hasError） */
    private array $missingRecommendedExtensions = [];

    /** @var array 被禁用的推荐函数列表（不影响 hasError） */
    private array $disabledRecommendedFunctions = [];

    /** @var array 未满足的推荐 items 列表（不影响 hasError） */
    private array $unsatisfiedRecommendedItems = [];

    /** @var string 当前 PHP 版本 */
    private string $currentPhpVersion;

    /** @var string 消息 */
    private string $message = '';

    public function __construct()
    {
        $this->currentPhpVersion = PHP_VERSION;
    }

    /**
     * 设置是否有错误
     */
    public function setHasError(bool $hasError): self
    {
        $this->hasError = $hasError;
        return $this;
    }

    /**
     * 是否有错误
     */
    public function hasError(): bool
    {
        return $this->hasError;
    }

    /**
     * 添加检测详情
     */
    public function addDetail(string $key, string $status, bool $passed): self
    {
        $this->details[$key] = [
            'status' => $status,
            'passed' => $passed,
        ];
        return $this;
    }

    /**
     * 获取检测详情
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * 添加缺失的扩展
     */
    public function addMissingExtension(string $extension): self
    {
        $this->missingExtensions[] = $extension;
        $this->hasError = true;
        return $this;
    }

    /**
     * 获取缺失的扩展列表
     */
    public function getMissingExtensions(): array
    {
        return $this->missingExtensions;
    }

    /**
     * 添加被禁用的函数
     */
    public function addDisabledFunction(string $function): self
    {
        $this->disabledFunctions[] = $function;
        $this->hasError = true;
        return $this;
    }

    /**
     * 获取被禁用的函数列表
     */
    public function getDisabledFunctions(): array
    {
        return $this->disabledFunctions;
    }

    /**
     * 添加未满足的 item
     */
    public function addUnsatisfiedItem(array $item): self
    {
        $this->unsatisfiedItems[] = $item;
        $this->hasError = true;
        return $this;
    }

    /**
     * 获取未满足的 items 列表
     */
    public function getUnsatisfiedItems(): array
    {
        return $this->unsatisfiedItems;
    }

    /**
     * 设置 PHP 版本问题
     */
    public function setPhpVersionIssue(string $issue): self
    {
        $this->phpVersionIssue = $issue;
        $this->hasError = true;
        return $this;
    }

    /**
     * 获取 PHP 版本问题
     */
    public function getPhpVersionIssue(): ?string
    {
        return $this->phpVersionIssue;
    }

    /**
     * 获取当前 PHP 版本
     */
    public function getCurrentPhpVersion(): string
    {
        return $this->currentPhpVersion;
    }

    // ==================== 推荐项结果（不影响 hasError）====================

    /**
     * 添加缺失的推荐扩展
     */
    public function addMissingRecommendedExtension(string $extension): self
    {
        $this->missingRecommendedExtensions[] = $extension;
        return $this;
    }

    /**
     * 获取缺失的推荐扩展列表
     */
    public function getMissingRecommendedExtensions(): array
    {
        return $this->missingRecommendedExtensions;
    }

    /**
     * 添加被禁用的推荐函数
     */
    public function addDisabledRecommendedFunction(string $function): self
    {
        $this->disabledRecommendedFunctions[] = $function;
        return $this;
    }

    /**
     * 获取被禁用的推荐函数列表
     */
    public function getDisabledRecommendedFunctions(): array
    {
        return $this->disabledRecommendedFunctions;
    }

    /**
     * 添加未满足的推荐 item
     */
    public function addUnsatisfiedRecommendedItem(array $item): self
    {
        $this->unsatisfiedRecommendedItems[] = $item;
        return $this;
    }

    /**
     * 获取未满足的推荐 items 列表
     */
    public function getUnsatisfiedRecommendedItems(): array
    {
        return $this->unsatisfiedRecommendedItems;
    }

    /**
     * 是否有推荐项未满足（不阻断，仅提示）
     */
    public function hasRecommendation(): bool
    {
        return !empty($this->missingRecommendedExtensions)
            || !empty($this->disabledRecommendedFunctions)
            || !empty($this->unsatisfiedRecommendedItems);
    }

    // ==================== 消息 ====================

    /**
     * 设置消息
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * 获取消息
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * 转换为数组（便于 JSON 输出）
     */
    public function toArray(): array
    {
        return [
            'has_error' => $this->hasError,
            'has_recommendation' => $this->hasRecommendation(),
            'message' => $this->message,
            'php_version' => $this->currentPhpVersion,
            'php_version_issue' => $this->phpVersionIssue,
            'missing_extensions' => $this->missingExtensions,
            'disabled_functions' => $this->disabledFunctions,
            'unsatisfied_items' => $this->unsatisfiedItems,
            'missing_recommended_extensions' => $this->missingRecommendedExtensions,
            'disabled_recommended_functions' => $this->disabledRecommendedFunctions,
            'unsatisfied_recommended_items' => $this->unsatisfiedRecommendedItems,
            'details' => $this->details,
        ];
    }
}
