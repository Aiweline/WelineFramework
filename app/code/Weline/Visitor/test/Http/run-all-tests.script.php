<?php
/**
 * 运行所有HTTP测试的脚本
 * 
 * 可以通过浏览器访问此文件来运行所有HTTP测试
 * 访问: /visitor/test/http/run-all-tests.script
 */

namespace Weline\Visitor\Test\Http;

use Weline\Framework\App\Controller\FrontendRestController;

class RunAllTestsScript extends FrontendRestController
{
    public function getIndex()
    {
        $baseUrl = $this->request->getBaseUrl();
        
        $tests = [
            '像素API测试' => [
                'url' => $baseUrl . '/visitor/test/http/pixel-api-http-test/run-all',
                'description' => '测试像素数据收集API的所有功能'
            ],
            '分析API测试' => [
                'url' => $baseUrl . '/visitor/test/http/analytics-api-http-test/run-all',
                'description' => '测试像素数据分析API的所有功能'
            ],
            '单独测试' => [
                'plain-data' => [
                    'url' => $baseUrl . '/visitor/test/http/pixel-api-http-test/plain-data',
                    'description' => '测试接收明文像素数据'
                ],
                'encrypted-data' => [
                    'url' => $baseUrl . '/visitor/test/http/pixel-api-http-test/encrypted-data',
                    'description' => '测试接收加密像素数据'
                ],
                'data-validation' => [
                    'url' => $baseUrl . '/visitor/test/http/pixel-api-http-test/data-validation',
                    'description' => '测试数据验证和清理'
                ],
                'website-id' => [
                    'url' => $baseUrl . '/visitor/test/http/pixel-api-http-test/website-id',
                    'description' => '测试站点ID识别'
                ],
                'ab-test-data' => [
                    'url' => $baseUrl . '/visitor/test/http/pixel-api-http-test/ab-test-data',
                    'description' => '测试A/B测试数据保存'
                ],
                'business-value' => [
                    'url' => $baseUrl . '/visitor/test/http/analytics-api-http-test/business-value',
                    'description' => '测试商业价值分析'
                ],
                'dashboard' => [
                    'url' => $baseUrl . '/visitor/test/http/analytics-api-http-test/dashboard',
                    'description' => '测试实时大屏数据'
                ],
                'ab-test' => [
                    'url' => $baseUrl . '/visitor/test/http/analytics-api-http-test/ab-test?testId=test_001',
                    'description' => '测试A/B测试数据分析'
                ]
            ]
        ];

        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor模块HTTP测试套件</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f7fa;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        .test-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .test-section h2 {
            color: #34495e;
            margin-top: 0;
        }
        .test-item {
            margin: 15px 0;
            padding: 15px;
            background: #f8f9fa;
            border-left: 4px solid #3498db;
            border-radius: 4px;
        }
        .test-item h3 {
            margin: 0 0 10px 0;
            color: #2c3e50;
        }
        .test-item p {
            margin: 5px 0;
            color: #7f8c8d;
        }
        .test-link {
            display: inline-block;
            margin-top: 10px;
            padding: 10px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.3s;
        }
        .test-link:hover {
            background: #2980b9;
        }
        .test-link.secondary {
            background: #95a5a6;
        }
        .test-link.secondary:hover {
            background: #7f8c8d;
        }
        .description {
            color: #7f8c8d;
            font-size: 14px;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <h1>Visitor模块HTTP测试套件</h1>
    
    <div class="test-section">
        <h2>完整测试套件</h2>';

        foreach ($tests['像素API测试'] as $key => $value) {
            if ($key === 'url') {
                $html .= '<div class="test-item">
                    <h3>像素API完整测试</h3>
                    <p class="description">' . $tests['像素API测试']['description'] . '</p>
                    <a href="' . $value . '" class="test-link" target="_blank">运行测试</a>
                </div>';
            }
        }

        foreach ($tests['分析API测试'] as $key => $value) {
            if ($key === 'url') {
                $html .= '<div class="test-item">
                    <h3>分析API完整测试</h3>
                    <p class="description">' . $tests['分析API测试']['description'] . '</p>
                    <a href="' . $value . '" class="test-link" target="_blank">运行测试</a>
                </div>';
            }
        }

        $html .= '</div>
    
    <div class="test-section">
        <h2>单独测试</h2>';

        foreach ($tests['单独测试'] as $name => $test) {
            $html .= '<div class="test-item">
                <h3>' . ucfirst(str_replace('-', ' ', $name)) . '</h3>
                <p class="description">' . $test['description'] . '</p>
                <a href="' . $test['url'] . '" class="test-link secondary" target="_blank">运行测试</a>
            </div>';
        }

        $html .= '</div>
</body>
</html>';

        return $html;
    }
}

