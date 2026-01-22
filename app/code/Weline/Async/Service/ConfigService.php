<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Service;

use Weline\Async\Model\SyncHost;
use Weline\Async\Model\SyncMapping;
use Weline\Framework\Manager\ObjectManager;

/**
 * 配置服务
 * 
 * 负责读取/保存配置、配置验证、生成watcher配置JSON
 * 
 * @package Weline_Async
 */
class ConfigService
{
    /**
     * 生成watcher配置JSON
     * 
     * @param SyncMapping $mapping 目录映射
     * @return array watcher配置
     */
    public function generateWatcherConfig(SyncMapping $mapping): array
    {
        $host = $mapping->getHost();
        if (!$host) {
            return [];
        }

        // 处理密钥：如果有密钥内容，写入临时文件
        $keyPath = '';
        $keyContent = $host->getDecryptedKeyContent();
        if (!empty($keyContent)) {
            // 创建临时密钥文件
            $keyDir = BP . DS . 'var' . DS . 'async' . DS . 'keys';
            if (!is_dir($keyDir)) {
                mkdir($keyDir, 0700, true);
            }
            $keyFile = $keyDir . DS . 'host_' . $host->getId() . '_key_' . time() . '.pem';
            file_put_contents($keyFile, $keyContent);
            chmod($keyFile, 0600); // 设置权限为仅所有者可读写
            $keyPath = $keyFile;
        } elseif ($host->getData(SyncHost::fields_KEY_PATH) && file_exists($host->getData(SyncHost::fields_KEY_PATH))) {
            // 兼容旧数据：如果只有路径，使用路径
            $keyPath = $host->getData(SyncHost::fields_KEY_PATH);
        }

        // 获取远程路径数组
        $remotePaths = $mapping->getRemotePathsArray();
        // 兼容旧数据：如果没有 remote_paths，使用 remote_path
        if (empty($remotePaths)) {
            $remotePath = $mapping->getData(SyncMapping::fields_REMOTE_PATH);
            if (!empty($remotePath)) {
                $remotePaths = [$remotePath];
            }
        }

        // 基础映射配置
        $mappingConfig = [
            'mapping_id' => $mapping->getId(),
            'host' => [
                'host' => $host->getData(SyncHost::fields_HOST),
                'port' => $host->getData(SyncHost::fields_PORT) ?: 22,
                'user' => $host->getData(SyncHost::fields_USER),
                'password' => $host->getDecryptedPassword(),
                'key_path' => $keyPath,
            ],
            'mapping' => [
                'local_path' => $mapping->getData(SyncMapping::fields_LOCAL_PATH),
                'remote_path' => !empty($remotePaths) ? $remotePaths[0] : '', // 兼容旧代码，使用第一个路径
                'remote_paths' => $remotePaths, // 多个远程路径
                'include_paths' => $mapping->getIncludePathsArray(),
                'exclude_patterns' => $mapping->getExcludePatternsArray(),
            ],
        ];

        // 如果本地路径在框架根目录（BP）内，自动排除 var 和 vendor 目录，避免自同步日志/依赖
        $localPath = $mappingConfig['mapping']['local_path'] ?? '';
        if (!empty($localPath)) {
            $realLocal = realpath($localPath) ?: $localPath;
            $realBp = realpath(BP) ?: BP;
            if ($realBp !== '' && str_starts_with($realLocal, $realBp)) {
                $exclude = $mappingConfig['mapping']['exclude_patterns'] ?? [];
                // 统一使用相对目录名作为排除模式
                $extraExcludes = ['var', 'vendor'];
                foreach ($extraExcludes as $pattern) {
                    if (!in_array($pattern, $exclude, true) && !in_array($pattern . '/', $exclude, true)) {
                        $exclude[] = $pattern;
                    }
                }
                $mappingConfig['mapping']['exclude_patterns'] = $exclude;
            }
        }

        return $mappingConfig;
    }

    /**
     * 获取所有启用的映射配置
     * 
     * @return array 所有启用的映射配置数组
     */
    public function getAllEnabledMappingsConfig(): array
    {
        /** @var SyncMapping $mappingModel */
        $mappingModel = ObjectManager::getInstance(SyncMapping::class);
        
        $mappings = $mappingModel->clear()
            ->where(SyncMapping::fields_STATUS, 1)
            ->select()
            ->fetch()
            ->getItems();
        
        $configs = [];
        foreach ($mappings as $mapping) {
            $configs[] = $this->generateWatcherConfig($mapping);
        }
        
        return $configs;
    }

    /**
     * 保存watcher配置到文件
     * 
     * @param int $mappingId 映射ID
     * @return string 配置文件路径
     */
    public function saveWatcherConfigToFile(int $mappingId): string
    {
        /** @var SyncMapping $mappingModel */
        $mappingModel = ObjectManager::getInstance(SyncMapping::class);
        $mapping = $mappingModel->load($mappingId);
        
        if (!$mapping->getId()) {
            throw new \RuntimeException("映射配置不存在: {$mappingId}");
        }
        
        $config = $this->generateWatcherConfig($mapping);
        $configDir = BP . DS . 'var' . DS . 'async' . DS . 'config';
        
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        $configFile = $configDir . DS . "mapping_{$mappingId}.json";
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $configFile;
    }

    /**
     * 验证配置
     * 
     * @param array $hostData 主机数据
     * @param array $mappingData 映射数据
     * @return array 验证结果 ['valid' => bool, 'errors' => []]
     */
    public function validateConfig(array $hostData, array $mappingData): array
    {
        $errors = [];
        
        // 验证主机配置
        if (empty($hostData['host'])) {
            $errors[] = '主机地址不能为空';
        }
        if (empty($hostData['user'])) {
            $errors[] = 'SSH用户名不能为空';
        }
        if (empty($hostData['password']) && empty($hostData['key_path'])) {
            $errors[] = 'SSH密码或密钥至少需要提供一个';
        }
        
        // 验证映射配置
        if (empty($mappingData['local_path'])) {
            $errors[] = '本地路径不能为空';
        } elseif (!is_dir($mappingData['local_path'])) {
            $errors[] = '本地路径不存在或不是目录';
        }
        if (empty($mappingData['remote_path'])) {
            $errors[] = '远程路径不能为空';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * 读取项目根目录的 weline-async.json 配置
     * 
     * @return array|null 配置数组，如果文件不存在返回null
     */
    public function getProjectConfig(): ?array
    {
        $configFile = BP . DS . 'weline-async.json';
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        $content = file_get_contents($configFile);
        $config = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("weline-async.json 配置文件格式错误: " . json_last_error_msg());
        }
        
        return $config;
    }

    /**
     * 检查项目配置文件是否存在
     * 
     * @return bool
     */
    public function hasProjectConfig(): bool
    {
        $configFile = BP . DS . 'weline-async.json';
        return file_exists($configFile);
    }

    /**
     * 生成项目配置的watcher配置
     * 
     * @return array watcher配置
     */
    public function generateProjectWatcherConfig(): array
    {
        $config = $this->getProjectConfig();
        if (!$config) {
            return [];
        }

        // 验证配置格式
        if (empty($config['host']) || empty($config['mapping'])) {
            throw new \RuntimeException("weline-async.json 配置格式错误: 缺少 host 或 mapping 字段");
        }

        // 如果没有指定 local_path，默认使用项目根目录
        $localPath = $config['mapping']['local_path'] ?? BP;
        if (empty($localPath) || $localPath === '.') {
            $localPath = BP;
        }

        // 处理密钥：如果有密钥内容，写入临时文件
        $keyPath = '';
        $keyContent = $config['host']['key_content'] ?? '';
        if (!empty($keyContent)) {
            // 创建临时密钥文件
            $keyDir = BP . DS . 'var' . DS . 'async' . DS . 'keys';
            if (!is_dir($keyDir)) {
                mkdir($keyDir, 0700, true);
            }
            $keyFile = $keyDir . DS . 'project_key_' . time() . '.pem';
            file_put_contents($keyFile, $keyContent);
            chmod($keyFile, 0600); // 设置权限为仅所有者可读写
            $keyPath = $keyFile;
        } elseif (!empty($config['host']['key_path']) && file_exists($config['host']['key_path'])) {
            // 兼容旧配置：如果只有路径，使用路径
            $keyPath = $config['host']['key_path'];
        }

        // 获取远程路径数组
        $remotePaths = [];
        if (!empty($config['mapping']['remote_paths'])) {
            $remotePaths = is_array($config['mapping']['remote_paths']) 
                ? $config['mapping']['remote_paths'] 
                : json_decode($config['mapping']['remote_paths'], true);
        } elseif (!empty($config['mapping']['remote_path'])) {
            // 兼容旧配置：如果只有 remote_path，转换为 remote_paths
            $remotePaths = [$config['mapping']['remote_path']];
        }
        
        // 如果都没有，使用本地路径作为远程路径（相同路径映射）
        if (empty($remotePaths)) {
            $remotePaths = [$localPath];
        }

        // 基础映射配置
        $mapping = [
            'mapping_id' => 'project', // 使用特殊标识
            'host' => [
                'host' => $config['host']['host'] ?? '',
                'port' => $config['host']['port'] ?? 22,
                'user' => $config['host']['user'] ?? '',
                'password' => $config['host']['password'] ?? '',
                'key_path' => $keyPath,
            ],
            'mapping' => [
                'local_path' => $localPath,
                'remote_path' => !empty($remotePaths) ? $remotePaths[0] : '', // 兼容旧代码，使用第一个路径
                'remote_paths' => $remotePaths, // 多个远程路径
                'include_paths' => $config['mapping']['include_paths'] ?? [],
                'exclude_patterns' => $config['mapping']['exclude_patterns'] ?? [],
            ],
        ];

        // 如果项目映射的 local_path 在框架根目录（BP）内，自动排除 var 和 vendor 目录，避免自同步日志/依赖
        $realLocal = realpath($mapping['mapping']['local_path']) ?: $mapping['mapping']['local_path'];
        $realBp = realpath(BP) ?: BP;
        if ($realBp !== '' && str_starts_with($realLocal, $realBp)) {
            $exclude = $mapping['mapping']['exclude_patterns'] ?? [];
            $extraExcludes = ['var', 'vendor'];
            foreach ($extraExcludes as $pattern) {
                if (!in_array($pattern, $exclude, true) && !in_array($pattern . '/', $exclude, true)) {
                    $exclude[] = $pattern;
                }
            }
            $mapping['mapping']['exclude_patterns'] = $exclude;
        }

        return $mapping;
    }

    /**
     * 保存项目配置的watcher配置到文件
     * 
     * @return string 配置文件路径
     */
    public function saveProjectWatcherConfigToFile(): string
    {
        $config = $this->generateProjectWatcherConfig();
        if (empty($config)) {
            throw new \RuntimeException("项目配置文件不存在或格式错误");
        }

        $configDir = BP . DS . 'var' . DS . 'async' . DS . 'config';
        
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        $configFile = $configDir . DS . "mapping_project.json";
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $configFile;
    }
}
