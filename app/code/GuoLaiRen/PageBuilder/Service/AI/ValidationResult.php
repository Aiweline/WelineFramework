<?php

declare(strict_types=1);

/*
 * AI 组件验证结果
 * 
 * 封装组件验证的结果数据
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

class ValidationResult
{
    private bool $valid = false;
    private array $errors = [];
    private array $warnings = [];
    
    public function isValid(): bool
    {
        return $this->valid;
    }
    
    public function setValid(bool $valid): self
    {
        $this->valid = $valid;
        return $this;
    }
    
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    public function setErrors(array $errors): self
    {
        $this->errors = $errors;
        return $this;
    }
    
    public function addError(string $error): self
    {
        $this->errors[] = $error;
        return $this;
    }
    
    public function getWarnings(): array
    {
        return $this->warnings;
    }
    
    public function setWarnings(array $warnings): self
    {
        $this->warnings = $warnings;
        return $this;
    }
    
    public function addWarning(string $warning): self
    {
        $this->warnings[] = $warning;
        return $this;
    }
    
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }
    
    /**
     * 获取所有消息（错误 + 警告）
     */
    public function getAllMessages(): array
    {
        return array_merge(
            array_map(fn($e) => ['type' => 'error', 'message' => $e], $this->errors),
            array_map(fn($w) => ['type' => 'warning', 'message' => $w], $this->warnings)
        );
    }
    
    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
