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
 * 脚本执行结果数据对象
 * 
 * @DESC 封装安装脚本的执行结果
 */
class ExecutionResult
{
    /** @var bool 是否成功 */
    private bool $success;

    /** @var int 退出码 */
    private int $exitCode;

    /** @var string 标准输出 */
    private string $output;

    /** @var string 错误输出 */
    private string $errorOutput;

    /** @var string 执行的命令/脚本 */
    private string $command;

    /** @var string 执行动作（check 或 install） */
    private string $action;

    /** @var string|null 模块名称 */
    private ?string $moduleName = null;

    /** @var string|null item 名称 */
    private ?string $itemName = null;

    /** @var float 执行时长（秒） */
    private float $duration = 0.0;

    public function __construct(
        bool $success,
        int $exitCode,
        string $output = '',
        string $errorOutput = '',
        string $command = '',
        string $action = ''
    ) {
        $this->success = $success;
        $this->exitCode = $exitCode;
        $this->output = $output;
        $this->errorOutput = $errorOutput;
        $this->command = $command;
        $this->action = $action;
    }

    /**
     * 创建成功结果
     */
    public static function success(string $output = '', string $command = '', string $action = ''): self
    {
        return new self(true, 0, $output, '', $command, $action);
    }

    /**
     * 创建失败结果
     */
    public static function failure(int $exitCode, string $errorOutput = '', string $command = '', string $action = ''): self
    {
        return new self(false, $exitCode, '', $errorOutput, $command, $action);
    }

    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * 获取退出码
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * 获取标准输出
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * 获取错误输出
     */
    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    /**
     * 获取执行的命令
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * 获取执行动作
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * 设置模块名称
     */
    public function setModuleName(string $moduleName): self
    {
        $this->moduleName = $moduleName;
        return $this;
    }

    /**
     * 获取模块名称
     */
    public function getModuleName(): ?string
    {
        return $this->moduleName;
    }

    /**
     * 设置 item 名称
     */
    public function setItemName(string $itemName): self
    {
        $this->itemName = $itemName;
        return $this;
    }

    /**
     * 获取 item 名称
     */
    public function getItemName(): ?string
    {
        return $this->itemName;
    }

    /**
     * 设置执行时长
     */
    public function setDuration(float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    /**
     * 获取执行时长
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'exit_code' => $this->exitCode,
            'output' => $this->output,
            'error_output' => $this->errorOutput,
            'command' => $this->command,
            'action' => $this->action,
            'module_name' => $this->moduleName,
            'item_name' => $this->itemName,
            'duration' => $this->duration,
        ];
    }
}
