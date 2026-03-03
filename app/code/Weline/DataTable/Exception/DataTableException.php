<?php
/**
 * DataTable 模块统一异常处理类
 * 提供统一的异常处理和错误信息格式化
 */

namespace Weline\DataTable\Exception;

use Weline\Framework\App\Exception as FrameworkException;

class DataTableException extends FrameworkException
{
    /**
     * 错误代码常量
     */
    const CODE_MODEL_NOT_FOUND = 1001;
    const CODE_FIELD_NOT_FOUND = 1002;
    const CODE_VALIDATION_FAILED = 1003;
    const CODE_PERMISSION_DENIED = 1004;
    const CODE_IMPORT_FAILED = 1005;
    const CODE_EXPORT_FAILED = 1006;
    const CODE_UPLOAD_FAILED = 1007;
    const CODE_QUERY_FAILED = 1008;
    const CODE_SAVE_FAILED = 1009;
    const CODE_DELETE_FAILED = 1010;

    /**
     * 错误消息模板
     */
    private static array $errorMessages = [
        self::CODE_MODEL_NOT_FOUND => '模型类不存在: %s',
        self::CODE_FIELD_NOT_FOUND => '字段不存在: %s',
        self::CODE_VALIDATION_FAILED => '数据验证失败: %s',
        self::CODE_PERMISSION_DENIED => '权限不足: %s',
        self::CODE_IMPORT_FAILED => '数据导入失败: %s',
        self::CODE_EXPORT_FAILED => '数据导出失败: %s',
        self::CODE_UPLOAD_FAILED => '文件上传失败: %s',
        self::CODE_QUERY_FAILED => '数据查询失败: %s',
        self::CODE_SAVE_FAILED => '数据保存失败: %s',
        self::CODE_DELETE_FAILED => '数据删除失败: %s',
    ];

    /**
     * 构造函数
     *
     * @param int $code 错误代码
     * @param string|array $message 错误消息或参数
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(int $code, $message = '', ?\Throwable $previous = null)
    {
        $errorMessage = self::getErrorMessage($code, $message);
        // 注意：父类 Core 的参数顺序是 (message, cause, code)
        parent::__construct($errorMessage, $previous, $code);
    }

    /**
     * 获取格式化的错误消息
     *
     * @param int $code 错误代码
     * @param string|array $params 消息参数
     * @return string
     */
    public static function getErrorMessage(int $code, $params = ''): string
    {
        $template = self::$errorMessages[$code] ?? '未知错误: %s';
        
        if (is_array($params)) {
            return vsprintf($template, $params);
        }
        
        return sprintf($template, $params ?: '');
    }

    /**
     * 创建模型不存在异常
     *
     * @param string $model 模型类名
     * @return self
     */
    public static function modelNotFound(string $model): self
    {
        return new self(self::CODE_MODEL_NOT_FOUND, $model);
    }

    /**
     * 创建字段不存在异常
     *
     * @param string $field 字段名
     * @return self
     */
    public static function fieldNotFound(string $field): self
    {
        return new self(self::CODE_FIELD_NOT_FOUND, $field);
    }

    /**
     * 创建验证失败异常
     *
     * @param string|array $errors 验证错误信息
     * @return self
     */
    public static function validationFailed($errors): self
    {
        $message = is_array($errors) ? implode(', ', $errors) : $errors;
        return new self(self::CODE_VALIDATION_FAILED, $message);
    }

    /**
     * 创建权限不足异常
     *
     * @param string $action 操作名称
     * @return self
     */
    public static function permissionDenied(string $action): self
    {
        return new self(self::CODE_PERMISSION_DENIED, $action);
    }

    /**
     * 创建导入失败异常
     *
     * @param string $reason 失败原因
     * @return self
     */
    public static function importFailed(string $reason): self
    {
        return new self(self::CODE_IMPORT_FAILED, $reason);
    }

    /**
     * 创建导出失败异常
     *
     * @param string $reason 失败原因
     * @return self
     */
    public static function exportFailed(string $reason): self
    {
        return new self(self::CODE_EXPORT_FAILED, $reason);
    }

    /**
     * 创建上传失败异常
     *
     * @param string $reason 失败原因
     * @return self
     */
    public static function uploadFailed(string $reason): self
    {
        return new self(self::CODE_UPLOAD_FAILED, $reason);
    }

    /**
     * 创建查询失败异常
     *
     * @param string $reason 失败原因
     * @param \Throwable|null $previous 前一个异常
     * @return self
     */
    public static function queryFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(self::CODE_QUERY_FAILED, $reason, $previous);
    }

    /**
     * 创建保存失败异常
     *
     * @param string $reason 失败原因
     * @return self
     */
    public static function saveFailed(string $reason): self
    {
        return new self(self::CODE_SAVE_FAILED, $reason);
    }

    /**
     * 创建删除失败异常
     *
     * @param string $reason 失败原因
     * @return self
     */
    public static function deleteFailed(string $reason): self
    {
        return new self(self::CODE_DELETE_FAILED, $reason);
    }

    /**
     * 记录异常日志
     *
     * @param string $context 上下文信息
     * @return void
     */
    public function log(string $context = ''): void
    {
        $logMessage = sprintf(
            "[DataTable Exception] %s - Code: %d, Message: %s, File: %s, Line: %d",
            $context ?: 'Unknown',
            $this->getCode(),
            $this->getMessage(),
            $this->getFile(),
            $this->getLine()
        );

        if ($this->getPrevious()) {
            $logMessage .= sprintf(
                ", Previous: %s",
                $this->getPrevious()->getMessage()
            );
        }

        w_log_error($logMessage);

        // 在开发环境下记录堆栈跟踪
        if (defined('DEV') && DEV) {
            w_log_error("Stack trace:\n" . $this->getTraceAsString());
        }
    }
}

