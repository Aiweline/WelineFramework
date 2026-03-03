<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/01/XX
 */

namespace Weline\Framework\Database\Service;

use Weline\Framework\App\Env;

/**
 * 驱动注册表服务
 * 
 * 功能：
 * - 管理驱动映射关系（驱动类型 => 类名）
 * - 读取/写入 driver.php 映射文件
 * - 提供缓存机制
 */
class DriverRegistry
{
    /**
     * 驱动映射文件路径
     */
    private const DRIVER_FILE = 'generated/database/driver.php';

    /**
     * 内存缓存
     * 
     * @var array|null
     */
    private ?array $cache = null;

    /**
     * 文件修改时间
     * 
     * @var int|null
     */
    private ?int $mtime = null;

    /**
     * 获取所有驱动映射
     * 
     * @param bool $forceReload 强制重新加载
     * @return array ['driver_type' => 'class_name', ...]
     */
    public function getAllDrivers(bool $forceReload = false): array
    {
        $driverFile = BP . DIRECTORY_SEPARATOR . self::DRIVER_FILE;
        
        // 检查文件是否存在
        if (!file_exists($driverFile)) {
            return [];
        }
        
        // 检查是否需要重新加载
        $currentMtime = filemtime($driverFile);
        if (!$forceReload && $this->cache !== null && $this->mtime === $currentMtime) {
            return $this->cache;
        }
        
        // 读取文件
        try {
            $drivers = include $driverFile;
            if (!is_array($drivers)) {
                $drivers = [];
            }
            
            $this->cache = $drivers;
            $this->mtime = $currentMtime;
            
            return $drivers;
        } catch (\Exception $e) {
            w_log_error("读取驱动映射文件失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取指定驱动的类名
     * 
     * @param string $driverType
     * @return string|null
     */
    public function getDriverClass(string $driverType): ?string
    {
        $drivers = $this->getAllDrivers();
        $driverType = strtolower($driverType);
        
        return $drivers[$driverType] ?? null;
    }

    /**
     * 更新驱动映射
     * 
     * @param array $drivers ['driver_type' => 'class_name', ...]
     * @return bool
     */
    public function updateDrivers(array $drivers): bool
    {
        // 确保目录存在
        $driverFile = BP . DIRECTORY_SEPARATOR . self::DRIVER_FILE;
        $driverDir = dirname($driverFile);
        
        if (!is_dir($driverDir)) {
            if (!mkdir($driverDir, 0755, true)) {
                w_log_error("创建驱动映射目录失败: {$driverDir}");
                return false;
            }
        }
        
        // 合并现有驱动（保留扩展驱动）
        $existingDrivers = $this->getAllDrivers();
        $mergedDrivers = array_merge($existingDrivers ?? [], $drivers);
        
        // 生成文件内容
        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * 数据库驱动映射文件\n";
        $content .= " * 此文件由系统自动生成，请勿手动修改\n";
        $content .= " * 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $content .= " */\n\n";
        $content .= "return [\n";
        
        foreach ($mergedDrivers as $driverType => $className) {
            $content .= "    '{$driverType}' => '{$className}',\n";
        }
        
        $content .= "];\n";
        
        // 写入文件
        try {
            $result = file_put_contents($driverFile, $content, LOCK_EX);
            
            if ($result === false) {
                w_log_error("写入驱动映射文件失败: {$driverFile}");
                return false;
            }
            
            // 更新缓存
            $this->cache = $mergedDrivers;
            $this->mtime = filemtime($driverFile);
            
            return true;
        } catch (\Exception $e) {
            w_log_error("写入驱动映射文件异常: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 清除缓存
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = null;
        $this->mtime = null;
    }
}

