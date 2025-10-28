<?php

/**
 * 配置保存功能测试脚本
 * 用于验证 SystemConfig 的保存和读取功能是否正常工作
 */

require __DIR__ . '/../../../../bootstrap.php';

use Weline\SystemConfig\Model\SystemConfig;
use Weline\Framework\Manager\ObjectManager;

echo "开始测试配置保存功能...\n\n";

try {
    $systemConfig = ObjectManager::getInstance(SystemConfig::class);
    
    // 测试数据
    $testConfig = [
        'mode' => 'detect',
        'level' => 'standard',
        'enable_strict_check' => true,
        'default_model' => 'test_model_code'
    ];
    
    echo "1. 测试保存脱敏适配器配置...\n";
    $json = json_encode($testConfig, JSON_UNESCAPED_UNICODE);
    echo "   JSON: " . $json . "\n";
    
    $result = $systemConfig->setConfig(
        'desensitization_adapter_params',
        $json,
        'GuoLaiRen_Desensitization',
        'backend'
    );
    
    if ($result) {
        echo "   ✓ 配置保存成功\n\n";
    } else {
        echo "   ✗ 配置保存失败\n\n";
        exit(1);
    }
    
    echo "2. 测试读取配置...\n";
    $saved = $systemConfig->getConfig(
        'desensitization_adapter_params',
        'GuoLaiRen_Desensitization',
        'backend'
    );
    
    echo "   读取的值: " . $saved . "\n";
    
    if ($saved === $json) {
        echo "   ✓ 配置读取成功，值与保存的一致\n\n";
    } else {
        echo "   ✗ 配置值不一致\n\n";
        echo "   期望: " . $json . "\n";
        echo "   实际: " . $saved . "\n\n";
        exit(1);
    }
    
    echo "3. 测试解析JSON...\n";
    $savedData = json_decode($saved, true);
    if ($savedData === $testConfig) {
        echo "   ✓ JSON解析成功，数据结构正确\n\n";
    } else {
        echo "   ✗ JSON解析失败或数据不一致\n\n";
        exit(1);
    }
    
    echo "4. 测试保存重写适配器配置...\n";
    $rewriteConfig = [
        'style' => 'natural',
        'preserve_format' => true,
        'enhance_readability' => true
    ];
    
    $rewriteJson = json_encode($rewriteConfig, JSON_UNESCAPED_UNICODE);
    $result = $systemConfig->setConfig(
        'rewrite_adapter_params',
        $rewriteJson,
        'GuoLaiRen_Desensitization',
        'backend'
    );
    
    if ($result) {
        echo "   ✓ 重写适配器配置保存成功\n\n";
    } else {
        echo "   ✗ 重写适配器配置保存失败\n\n";
        exit(1);
    }
    
    echo "5. 验证重写适配器配置...\n";
    $savedRewrite = $systemConfig->getConfig(
        'rewrite_adapter_params',
        'GuoLaiRen_Desensitization',
        'backend'
    );
    
    $savedRewriteData = json_decode($savedRewrite, true);
    if ($savedRewriteData === $rewriteConfig) {
        echo "   ✓ 重写适配器配置验证成功\n\n";
    } else {
        echo "   ✗ 重写适配器配置验证失败\n\n";
        exit(1);
    }
    
    echo "═══════════════════════════════════════\n";
    echo "✓ 所有测试通过！配置保存功能正常工作\n";
    echo "═══════════════════════════════════════\n";
    
} catch (Exception $e) {
    echo "\n✗ 测试失败: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

