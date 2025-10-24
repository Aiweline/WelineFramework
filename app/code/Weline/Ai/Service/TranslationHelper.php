<?php
declare(strict_types=1);

namespace Weline\Ai\Service;

/**
 * AI模块翻译助手服务
 * 
 * 功能：
 * - 提供统一的翻译方法
 * - 支持控制器和模板中的翻译
 * - 集成Weline框架的翻译机制
 */
class TranslationHelper
{
    /**
     * 翻译文本
     * 
     * @param string $text 要翻译的文本
     * @return string 翻译后的文本
     */
    public static function translate(string $text): string
    {
        // 使用Weline框架的翻译机制
        return \Weline\Framework\Phrase\Parser::parse($text);
    }

    /**
     * 获取AI模块相关的翻译文本
     * 
     * @param string $key 翻译键
     * @return string 翻译后的文本
     */
    public static function getAiTranslation(string $key): string
    {
        $translations = [
            // 通用消息
            'success' => '成功',
            'error' => '错误',
            'warning' => '警告',
            'info' => '信息',
            'confirm' => '确认',
            'cancel' => '取消',
            'save' => '保存',
            'delete' => '删除',
            'edit' => '编辑',
            'add' => '添加',
            'search' => '搜索',
            'refresh' => '刷新',
            'loading' => '加载中...',
            'no_data' => '暂无数据',
            'operation_success' => '操作成功',
            'operation_failed' => '操作失败',
            
            // 模型管理相关
            'model_management' => '模型管理',
            'model_name' => '模型名称',
            'model_code' => '模型代码',
            'vendor' => '供应商',
            'version' => '版本',
            'status' => '状态',
            'active' => '激活',
            'inactive' => '未激活',
            'created_time' => '创建时间',
            'actions' => '操作',
            'model_not_found' => '模型不存在',
            'model_id_required' => '模型ID不能为空',
            'model_collect_success' => '模型收集成功',
            'model_collect_failed' => '模型收集失败',
            'model_status_update_success' => '状态更新成功',
            'model_status_update_failed' => '状态更新失败',
            'model_set_default_success' => '默认模型设置成功',
            'model_set_default_failed' => '默认模型设置失败',
            'model_test_success' => '连接测试成功',
            'model_test_failed' => '连接测试失败',
            
            // 适配器管理相关
            'adapter_management' => '适配器管理',
            'adapter_name' => '适配器名称',
            'adapter_code' => '适配器代码',
            'description' => '描述',
            'class_name' => '类名',
            'supported_models' => '支持的模型',
            'adapter_not_found' => '适配器不存在',
            'adapter_id_required' => '适配器ID不能为空',
            'adapter_code_required' => '适配器代码不能为空',
            'adapter_scan_success' => '适配器扫描成功',
            'adapter_scan_failed' => '适配器扫描失败',
            'adapter_status_update_success' => '状态更新成功',
            'adapter_status_update_failed' => '状态更新失败',
            'adapter_test_success' => '适配器测试成功',
            'adapter_test_failed' => '适配器测试失败',
            
            // 默认模型管理相关
            'default_model_management' => '默认模型管理',
            'service_type' => '服务类型',
            'model_code' => '模型代码',
            'priority' => '优先级',
            'service_type_required' => '服务类型不能为空',
            'model_code_required' => '模型代码不能为空',
            'default_model_set_success' => '默认模型设置成功',
            'default_model_set_failed' => '默认模型设置失败',
            'default_model_remove_success' => '默认模型配置移除成功',
            'default_model_remove_failed' => '默认模型配置移除失败',
            'default_model_init_success' => '默认配置初始化成功',
            'default_model_init_failed' => '初始化失败',
            'default_model_validate_success' => '所有默认模型配置都是有效的',
            'default_model_validate_failed' => '发现配置问题',
            'default_model_cache_clear_success' => '默认模型缓存清除成功',
            'default_model_cache_clear_failed' => '清除缓存失败',
            
            // API相关
            'prompt_required' => '提示词不能为空',
            'validation_failed' => '参数验证失败',
            'generation_success' => '生成成功',
            'generation_failed' => '生成失败',
            'scenario_code_required' => '场景代码不能为空',
            'adapter_not_found_or_inactive' => '适配器不存在或未激活',
            'locale_error' => '语言设置错误',
            'stats_error' => '统计信息获取失败',
            
            // 批量操作
            'batch_operation_success' => '批量操作成功',
            'batch_operation_failed' => '批量操作失败',
            'required_fields_missing' => '缺少必需字段',
            'batch_set_success' => '批量设置完成',
            'batch_set_success_count' => '成功',
            'batch_set_error_count' => '失败',
        ];

        return $translations[$key] ?? $key;
    }

    /**
     * 获取带翻译的消息
     * 
     * @param string $key 翻译键
     * @param array $params 参数
     * @return string 翻译后的消息
     */
    public static function getMessage(string $key, array $params = []): string
    {
        $message = self::getAiTranslation($key);
        
        // 替换参数
        foreach ($params as $param => $value) {
            $message = str_replace('{' . $param . '}', $value, $message);
        }
        
        return $message;
    }
}
