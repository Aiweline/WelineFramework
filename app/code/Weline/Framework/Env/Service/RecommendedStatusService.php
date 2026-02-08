<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Service;

use Weline\Framework\App\Env;

/**
 * 推荐项安装状态持久化服务
 *
 * 记录推荐扩展/函数/依赖项的安装尝试结果，避免重复自动重试。
 * 状态文件：{var_dir}/env/recommended_status.json
 *
 * 状态值：
 *  - installed  : 安装成功
 *  - failed     : 安装失败（不再自动重试，可后台手动重试）
 *  - skipped    : 平台不适用，静默跳过
 *  - pending    : 尚未尝试
 */
class RecommendedStatusService
{
    /** @var string 状态文件路径 */
    private string $statusFile;

    /** @var array|null 缓存的状态数据 */
    private ?array $statusData = null;

    // 状态常量
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_PENDING   = 'pending';

    public function __construct()
    {
        $varDir = Env::VAR_DIR;
        $envDir = $varDir . 'env' . DIRECTORY_SEPARATOR;
        if (!\is_dir($envDir)) {
            @\mkdir($envDir, 0755, true);
        }
        $this->statusFile = $envDir . 'recommended_status.json';
    }

    /**
     * 获取某项的状态
     *
     * @param string $type 类型：extension / function / item
     * @param string $name 名称
     * @return string 状态值（STATUS_* 常量）
     */
    public function getStatus(string $type, string $name): string
    {
        $data = $this->loadData();
        return $data[$type][$name]['status'] ?? self::STATUS_PENDING;
    }

    /**
     * 是否已经尝试过（installed 或 failed）
     */
    public function hasAttempted(string $type, string $name): bool
    {
        $status = $this->getStatus($type, $name);
        return \in_array($status, [self::STATUS_INSTALLED, self::STATUS_FAILED, self::STATUS_SKIPPED], true);
    }

    /**
     * 记录安装成功
     */
    public function markInstalled(string $type, string $name, string $message = ''): void
    {
        $this->setStatus($type, $name, self::STATUS_INSTALLED, $message);
    }

    /**
     * 记录安装失败
     */
    public function markFailed(string $type, string $name, string $message = ''): void
    {
        $this->setStatus($type, $name, self::STATUS_FAILED, $message);
    }

    /**
     * 记录平台跳过
     */
    public function markSkipped(string $type, string $name, string $message = ''): void
    {
        $this->setStatus($type, $name, self::STATUS_SKIPPED, $message);
    }

    /**
     * 重置某项状态（允许重试）
     */
    public function resetStatus(string $type, string $name): void
    {
        $data = $this->loadData();
        unset($data[$type][$name]);
        $this->saveData($data);
    }

    /**
     * 重置所有状态（允许全部重试）
     */
    public function resetAll(): void
    {
        $this->saveData([
            'extensions' => [],
            'functions'  => [],
            'items'      => [],
            'meta'       => [
                'reset_at' => \date('Y-m-d H:i:s'),
                'platform' => PHP_OS_FAMILY,
            ],
        ]);
    }

    /**
     * 获取所有状态数据（供后台页面展示）
     */
    public function getAllStatuses(): array
    {
        return $this->loadData();
    }

    /**
     * 获取指定类型的所有状态
     *
     * @return array [name => ['status'=>..., 'message'=>..., 'tried_at'=>..., 'platform'=>...]]
     */
    public function getStatusesByType(string $type): array
    {
        $data = $this->loadData();
        return $data[$type] ?? [];
    }

    /**
     * 设置状态
     */
    private function setStatus(string $type, string $name, string $status, string $message): void
    {
        $data = $this->loadData();
        $data[$type][$name] = [
            'status'   => $status,
            'message'  => $message,
            'tried_at' => \date('Y-m-d H:i:s'),
            'platform' => PHP_OS_FAMILY,
            'php'      => PHP_VERSION,
        ];
        $this->saveData($data);
    }

    /**
     * 加载状态数据
     */
    private function loadData(): array
    {
        if ($this->statusData !== null) {
            return $this->statusData;
        }

        if (!\is_file($this->statusFile)) {
            $this->statusData = [
                'extensions' => [],
                'functions'  => [],
                'items'      => [],
                'meta'       => [],
            ];
            return $this->statusData;
        }

        $content = @\file_get_contents($this->statusFile);
        if ($content === false) {
            $this->statusData = ['extensions' => [], 'functions' => [], 'items' => [], 'meta' => []];
            return $this->statusData;
        }

        $decoded = @\json_decode($content, true);
        $this->statusData = \is_array($decoded) ? $decoded : ['extensions' => [], 'functions' => [], 'items' => [], 'meta' => []];
        return $this->statusData;
    }

    /**
     * 保存状态数据
     */
    private function saveData(array $data): void
    {
        $this->statusData = $data;
        $data['meta']['updated_at'] = \date('Y-m-d H:i:s');
        $data['meta']['platform']   = PHP_OS_FAMILY;
        $data['meta']['php']        = PHP_VERSION;

        @\file_put_contents(
            $this->statusFile,
            \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
