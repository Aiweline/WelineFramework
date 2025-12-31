<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Service;

use Weline\AutoLeadAgent\Model\AgentConfig;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;

/**
 * 配置服务类
 * 
 * 负责配置的读写操作
 */
class ConfigService
{
    /**
     * 获取配置值
     * 
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @param string $scope 作用域
     * @return mixed
     */
    public function getConfig(string $key, mixed $default = null, string $scope = AgentConfig::SCOPE_DEFAULT): mixed
    {
        try {
            /** @var AgentConfig $configModel */
            $configModel = ObjectManager::getInstance(AgentConfig::class);
            
            $configModel->clear()
                ->where(AgentConfig::fields_CONFIG_KEY, $key)
                ->where(AgentConfig::fields_SCOPE, $scope)
                ->find()
                ->fetch();

            if ($configModel->getId()) {
                $value = $configModel->getData(AgentConfig::fields_CONFIG_VALUE);
                
                // 对于 default_target_sites，确保始终返回数组
                if ($key === AgentConfig::CONFIG_DEFAULT_TARGET_SITES) {
                    // 如果已经是数组，直接返回
                    if (is_array($value)) {
                        return array_values(array_filter(array_map('trim', $value)));
                    }
                    
                    // 如果是数字类型（错误的数据），返回默认值
                    if (is_numeric($value)) {
                        $defaults = AgentConfig::getDefaultConfigs();
                        return $defaults[AgentConfig::CONFIG_DEFAULT_TARGET_SITES] ?? [];
                    }
                    
                    // 如果是字符串
                    if (is_string($value)) {
                        // 先检查是否是 JSON 数组格式（兼容历史数据）
                        $trimmed = trim($value);
                        if (preg_match('/^\[.*\]$/s', $trimmed)) {
                            $decoded = json_decode($trimmed, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                return array_values(array_filter(array_map('trim', $decoded)));
                            }
                        }
                        // 如果不是 JSON 格式，当作每行一个的文本处理
                        $sites = array_filter(array_map('trim', explode("\n", $value)));
                        return array_values($sites);
                    }
                    
                    // 如果是布尔值或其他类型，返回默认值
                    $defaults = AgentConfig::getDefaultConfigs();
                    return $defaults[AgentConfig::CONFIG_DEFAULT_TARGET_SITES] ?? [];
                }
                
                // 对于 hf_model_cache_size，确保返回整数
                if ($key === AgentConfig::CONFIG_HF_MODEL_CACHE_SIZE) {
                    if (is_numeric($value)) {
                        $intValue = (int)$value;
                        if ($intValue > 0) {
                            return $intValue;
                        }
                    }
                    // 如果不是数字，尝试转换为整数
                    $intValue = (int)$value;
                    if ($intValue > 0) {
                        return $intValue;
                    }
                    // 如果转换失败，返回默认值
                    return 10240;
                }
                
                // 尝试 JSON 解码
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
                
                // 处理布尔值
                if ($value === 'true' || $value === '1') {
                    return true;
                }
                if ($value === 'false' || $value === '0') {
                    return false;
                }
                
                // 处理数字
                if (is_numeric($value)) {
                    return strpos($value, '.') !== false ? (float)$value : (int)$value;
                }
                
                return $value;
            }

            // 如果没有找到配置，返回默认值或系统默认配置
            if ($default === null) {
                $defaults = AgentConfig::getDefaultConfigs();
                if (isset($defaults[$key])) {
                    $defaultValue = $defaults[$key];
                    // 如果是 JSON 字符串，解码返回
                    if (is_string($defaultValue)) {
                        $decoded = json_decode($defaultValue, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            return $decoded;
                        }
                    }
                    return $defaultValue;
                }
            }

            return $default;

        } catch (\Exception $e) {
            // 出错时返回默认值
            return $default;
        }
    }

    /**
     * 设置配置值
     * 
     * @param string $key 配置键
     * @param mixed $value 配置值
     * @param string $scope 作用域
     * @return bool
     * @throws Exception
     */
    public function setConfig(string $key, mixed $value, string $scope = AgentConfig::SCOPE_DEFAULT): bool
    {
        try {
            /** @var AgentConfig $configModel */
            $configModel = ObjectManager::getInstance(AgentConfig::class);
            
            // 查找是否已存在
            $configModel->clear()
                ->where(AgentConfig::fields_CONFIG_KEY, $key)
                ->where(AgentConfig::fields_SCOPE, $scope)
                ->find()
                ->fetch();

            // 处理值的序列化
            $storedValue = $this->serializeValue($value, $key);

            if ($configModel->getId()) {
                // 更新现有配置
                $configModel->setData(AgentConfig::fields_CONFIG_VALUE, $storedValue)
                    ->save();
            } else {
                // 创建新配置
                $configModel->clear()
                    ->setData(AgentConfig::fields_CONFIG_KEY, $key)
                    ->setData(AgentConfig::fields_CONFIG_VALUE, $storedValue)
                    ->setData(AgentConfig::fields_SCOPE, $scope)
                    ->save();
            }

            return true;

        } catch (\Exception $e) {
            throw new Exception(__('配置保存失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 批量设置配置
     * 
     * @param array $configs 配置数组 ['key' => 'value', ...]
     * @param string $scope 作用域
     * @return bool
     * @throws Exception
     */
    public function setConfigs(array $configs, string $scope = AgentConfig::SCOPE_DEFAULT): bool
    {
        try {
            foreach ($configs as $key => $value) {
                $this->setConfig($key, $value, $scope);
            }
            return true;
        } catch (\Exception $e) {
            throw new Exception(__('批量保存配置失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 获取所有配置
     * 
     * @param string $scope 作用域
     * @return array
     */
    public function getAllConfigs(string $scope = AgentConfig::SCOPE_DEFAULT): array
    {
        $result = [];
        $defaults = AgentConfig::getDefaultConfigs();

        // 先填充默认值
        foreach ($defaults as $key => $defaultValue) {
            $result[$key] = $this->getConfig($key, $defaultValue, $scope);
        }

        return $result;
    }

    /**
     * 获取默认配置
     * 
     * @return array
     */
    public function getDefaultConfigs(): array
    {
        $defaults = AgentConfig::getDefaultConfigs();
        $result = [];
        
        foreach ($defaults as $key => $value) {
            // 处理 JSON 字符串
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $result[$key] = $decoded;
                    continue;
                }
            }
            $result[$key] = $value;
        }
        
        return $result;
    }

    /**
     * 重置为默认配置
     * 
     * @param string $scope 作用域
     * @return bool
     * @throws Exception
     */
    public function resetToDefaults(string $scope = AgentConfig::SCOPE_DEFAULT): bool
    {
        try {
            /** @var AgentConfig $configModel */
            $configModel = ObjectManager::getInstance(AgentConfig::class);
            
            // 删除指定作用域的所有配置
            $configModel->clear()
                ->where(AgentConfig::fields_SCOPE, $scope)
                ->delete();

            return true;

        } catch (\Exception $e) {
            throw new Exception(__('重置配置失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 删除配置
     * 
     * @param string $key 配置键
     * @param string $scope 作用域
     * @return bool
     */
    public function deleteConfig(string $key, string $scope = AgentConfig::SCOPE_DEFAULT): bool
    {
        try {
            /** @var AgentConfig $configModel */
            $configModel = ObjectManager::getInstance(AgentConfig::class);
            
            $configModel->clear()
                ->where(AgentConfig::fields_CONFIG_KEY, $key)
                ->where(AgentConfig::fields_SCOPE, $scope)
                ->delete();

            return true;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 序列化值用于存储
     * 
     * @param mixed $value
     * @param string $key 配置键（用于特殊处理）
     * @return string
     */
    private function serializeValue(mixed $value, string $key = ''): string
    {
        // 对于 default_target_sites，保存为每行一个的文本格式，而不是 JSON 数组
        if ($key === AgentConfig::CONFIG_DEFAULT_TARGET_SITES && is_array($value)) {
            return implode("\n", array_filter(array_map('trim', $value)));
        }
        
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        return (string)$value;
    }

    /**
     * 获取关键词策略选项
     * 
     * @return array
     */
    public function getKeywordStrategyOptions(): array
    {
        return AgentConfig::getKeywordStrategyOptions();
    }

    /**
     * 验证配置值
     * 
     * @param string $key 配置键
     * @param mixed $value 配置值
     * @return bool
     * @throws Exception
     */
    public function validateConfig(string $key, mixed $value): bool
    {
        switch ($key) {
            case AgentConfig::CONFIG_AGENT_INTERVAL:
                if (!is_numeric($value) || $value < 1 || $value > 3600) {
                    throw new Exception(__('Agent执行间隔必须在1-3600秒之间'));
                }
                break;

            case AgentConfig::CONFIG_SCORE_THRESHOLD:
                if (!is_numeric($value) || $value < 0 || $value > 100) {
                    throw new Exception(__('评分阈值必须在0-100之间'));
                }
                break;

            case AgentConfig::CONFIG_KEYWORD_STRATEGY:
                $validStrategies = [
                    AgentConfig::KEYWORD_STRATEGY_AUTO,
                    AgentConfig::KEYWORD_STRATEGY_MANUAL,
                    AgentConfig::KEYWORD_STRATEGY_HYBRID,
                ];
                if (!in_array($value, $validStrategies)) {
                    throw new Exception(__('无效的关键词策略'));
                }
                break;

            case AgentConfig::CONFIG_API_RATE_LIMIT:
                if (!is_numeric($value) || $value < 1 || $value > 1000) {
                    throw new Exception(__('API调用频率必须在1-1000次/分钟之间'));
                }
                break;

            case AgentConfig::CONFIG_MAX_CONCURRENT_TASKS:
                if (!is_numeric($value) || $value < 1 || $value > 10) {
                    throw new Exception(__('最大并发任务数必须在1-10之间'));
                }
                break;

            case AgentConfig::CONFIG_WASM_INFERENCE_TIMEOUT:
                if (!is_numeric($value) || $value < 1000 || $value > 120000) {
                    throw new Exception(__('推理超时时间必须在1000-120000毫秒之间'));
                }
                break;
        }

        return true;
    }
}

