<?php

declare(strict_types=1);

/*
 * AI 组件生成结果
 * 
 * 封装 AI 组件生成的结果数据
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

class AIComponentResult
{
    private bool $success = false;
    private string $code = '';
    private string $name = '';
    private string $description = '';
    private string $category = 'content';
    private string $templateContent = '';
    private array $fields = [];
    private string $prompt = '';
    private string $error = '';
    
    public function isSuccess(): bool
    {
        return $this->success;
    }
    
    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }
    
    public function getCode(): string
    {
        return $this->code;
    }
    
    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }
    
    public function getDescription(): string
    {
        return $this->description;
    }
    
    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }
    
    public function getCategory(): string
    {
        return $this->category;
    }
    
    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }
    
    public function getTemplateContent(): string
    {
        return $this->templateContent;
    }
    
    public function setTemplateContent(string $templateContent): self
    {
        $this->templateContent = $templateContent;
        return $this;
    }
    
    public function getFields(): array
    {
        return $this->fields;
    }
    
    public function setFields(array $fields): self
    {
        $this->fields = $fields;
        return $this;
    }
    
    public function getPrompt(): string
    {
        return $this->prompt;
    }
    
    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }
    
    public function getError(): string
    {
        return $this->error;
    }
    
    public function setError(string $error): self
    {
        $this->error = $error;
        return $this;
    }
    
    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'template_content' => $this->templateContent,
            'fields' => $this->fields,
            'prompt' => $this->prompt,
            'error' => $this->error,
        ];
    }
}
