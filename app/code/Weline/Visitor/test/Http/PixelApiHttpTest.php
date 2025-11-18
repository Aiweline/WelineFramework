<?php

/**
 * 像素API HTTP集成测试
 * 
 * 测试像素数据收集API的HTTP请求和响应
 * 可以通过浏览器或HTTP客户端直接访问进行测试
 */

namespace Weline\Visitor\Test\Http;

use Weline\Framework\App\Controller\FrontendRestController;

class PixelApiHttpTest extends FrontendRestController
{
    /**
     * 测试：接收明文像素数据
     * 
     * 访问: /visitor/test/http/pixel-api-http-test/plain-data
     */
    public function getPlainData()
    {
        $testData = [
            'url' => 'https://example.com/test',
            'eventName' => 'click',
            'websiteId' => 1,
            'userId' => 123,
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'ip' => '192.168.1.1',
            'testId' => 'test_001',
            'variant' => 'A',
            'value' => 100,
            'currency' => 'RMB',
            'userLang' => 'zh-CN'
        ];

        // 模拟POST请求
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $testData;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        
        // 调用像素API
        /** @var \Weline\Visitor\Api\Rest\V1\Pixel $pixelApi */
        $pixelApi = w_obj(\Weline\Visitor\Api\Rest\V1\Pixel::class);
        
        try {
            $result = $pixelApi->postIndex();
            $response = json_decode($result, true);
            
            return $this->success('测试完成', [
                'test_name' => '接收明文像素数据',
                'request_data' => $testData,
                'response' => $response,
                'status' => $response['code'] === 200 ? '成功' : '失败',
                'pixel_id' => $response['data']['pixel_id'] ?? null,
                'pixel_additional_id' => $response['data']['pixel_additional_id'] ?? null,
                'ab_test' => $response['data']['ab_test'] ?? null
            ]);
        } catch (\Exception $e) {
            return $this->error('测试失败: ' . $e->getMessage(), [
                'test_name' => '接收明文像素数据',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 测试：接收加密像素数据
     * 
     * 访问: /visitor/test/http/pixel-api-http-test/encrypted-data
     */
    public function getEncryptedData()
    {
        $originalData = [
            'url' => 'https://example.com/test',
            'eventName' => 'click',
            'websiteId' => 1,
            'userId' => 123,
            'testId' => 'test_002',
            'variant' => 'B'
        ];

        try {
            // 加密数据
            /** @var \Weline\Visitor\Service\PixelEncryptionService $encryptionService */
            $encryptionService = w_obj(\Weline\Visitor\Service\PixelEncryptionService::class);
            $encrypted = $encryptionService->encrypt($originalData);
            
            // 获取版本号
            $token = $encryptionService->getCurrentVersionToken();
            $version = $token ? $token->getVersion() : '1.0.0-20250101';

            // 模拟POST请求
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'encrypted' => $encrypted,
                'version' => $version
            ];
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            
            // 调用像素API
            /** @var \Weline\Visitor\Api\Rest\V1\Pixel $pixelApi */
            $pixelApi = w_obj(\Weline\Visitor\Api\Rest\V1\Pixel::class);
            $result = $pixelApi->postIndex();
            $response = json_decode($result, true);
            
            return $this->success('测试完成', [
                'test_name' => '接收加密像素数据',
                'original_data' => $originalData,
                'encrypted_data' => substr($encrypted, 0, 50) . '...',
                'version' => $version,
                'response' => $response,
                'status' => $response['code'] === 200 ? '成功' : '失败',
                'pixel_id' => $response['data']['pixel_id'] ?? null
            ]);
        } catch (\Exception $e) {
            return $this->error('测试失败: ' . $e->getMessage(), [
                'test_name' => '接收加密像素数据',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 测试：数据验证和清理
     * 
     * 访问: /visitor/test/http/pixel-api-http-test/data-validation
     */
    public function getDataValidation()
    {
        $testCases = [
            [
                'name' => '无效IP地址',
                'data' => [
                    'url' => 'https://example.com/test',
                    'eventName' => 'click',
                    'ip' => 'invalid-ip-address',
                    'websiteId' => 1
                ]
            ],
            [
                'name' => '无效URL',
                'data' => [
                    'url' => 'not-a-valid-url',
                    'eventName' => 'click',
                    'websiteId' => 1
                ]
            ],
            [
                'name' => '超长字符串',
                'data' => [
                    'url' => 'https://example.com/test',
                    'eventName' => 'click',
                    'module' => str_repeat('a', 300),
                    'websiteId' => 1
                ]
            ],
            [
                'name' => '负数值',
                'data' => [
                    'url' => 'https://example.com/test',
                    'eventName' => 'click',
                    'value' => -100,
                    'websiteId' => 1
                ]
            ]
        ];

        $results = [];
        
        foreach ($testCases as $testCase) {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = $testCase['data'];
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            
            try {
                /** @var \Weline\Visitor\Api\Rest\V1\Pixel $pixelApi */
                $pixelApi = w_obj(\Weline\Visitor\Api\Rest\V1\Pixel::class);
                $result = $pixelApi->postIndex();
                $response = json_decode($result, true);
                
                $results[] = [
                    'test_case' => $testCase['name'],
                    'input_data' => $testCase['data'],
                    'status' => $response['code'] === 200 ? '成功（已清理）' : '失败',
                    'response_code' => $response['code'],
                    'message' => $response['msg'] ?? ''
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'test_case' => $testCase['name'],
                    'status' => '异常',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $this->success('数据验证测试完成', [
            'test_name' => '数据验证和清理',
            'results' => $results
        ]);
    }

    /**
     * 测试：站点ID识别
     * 
     * 访问: /visitor/test/http/pixel-api-http-test/website-id
     */
    public function getWebsiteId()
    {
        $testCases = [
            [
                'name' => '从请求数据获取',
                'data' => [
                    'url' => 'https://example.com/test',
                    'eventName' => 'click',
                    'websiteId' => 999
                ],
                'server_var' => null
            ],
            [
                'name' => '从siteId获取',
                'data' => [
                    'url' => 'https://example.com/test',
                    'eventName' => 'click',
                    'siteId' => 998
                ],
                'server_var' => null
            ],
            [
                'name' => '从SERVER变量获取',
                'data' => [
                    'url' => 'https://example.com/test',
                    'eventName' => 'click'
                ],
                'server_var' => ['WELINE_WEBSITE_ID' => '997']
            ]
        ];

        $results = [];
        
        foreach ($testCases as $testCase) {
            // 设置SERVER变量
            if ($testCase['server_var']) {
                foreach ($testCase['server_var'] as $key => $value) {
                    $_SERVER[$key] = $value;
                }
            } else {
                unset($_SERVER['WELINE_WEBSITE_ID']);
            }
            
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = $testCase['data'];
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            
            try {
                /** @var \Weline\Visitor\Api\Rest\V1\Pixel $pixelApi */
                $pixelApi = w_obj(\Weline\Visitor\Api\Rest\V1\Pixel::class);
                $result = $pixelApi->postIndex();
                $response = json_decode($result, true);
                
                if ($response['code'] === 200 && isset($response['data']['pixel_id'])) {
                    // 验证站点ID
                    $pixelId = $response['data']['pixel_id'];
                    /** @var \Weline\Visitor\Model\Pixel $pixel */
                    $pixel = w_obj(\Weline\Visitor\Model\Pixel::class);
                    $pixel->load($pixelId);
                    
                    $expectedWebsiteId = $testCase['data']['websiteId'] 
                        ?? $testCase['data']['siteId'] 
                        ?? ($testCase['server_var']['WELINE_WEBSITE_ID'] ?? 0);
                    
                    $results[] = [
                        'test_case' => $testCase['name'],
                        'expected_website_id' => (int)$expectedWebsiteId,
                        'actual_website_id' => $pixel->getWebsiteId(),
                        'status' => $pixel->getWebsiteId() == $expectedWebsiteId ? '成功' : '失败',
                        'pixel_id' => $pixelId
                    ];
                } else {
                    $results[] = [
                        'test_case' => $testCase['name'],
                        'status' => '失败',
                        'error' => $response['msg'] ?? '未知错误'
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'test_case' => $testCase['name'],
                    'status' => '异常',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $this->success('站点ID识别测试完成', [
            'test_name' => '站点ID识别',
            'results' => $results
        ]);
    }

    /**
     * 测试：A/B测试数据保存
     * 
     * 访问: /visitor/test/http/pixel-api-http-test/ab-test-data
     */
    public function getAbTestData()
    {
        $testData = [
            'url' => 'https://example.com/test',
            'eventName' => 'click',
            'websiteId' => 1,
            'testId' => 'ab_test_001',
            'variant' => 'A',
            'test_id' => 'ab_test_001', // 测试兼容性
            'testVariant' => 'A' // 测试兼容性
        ];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $testData;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        
        try {
            /** @var \Weline\Visitor\Api\Rest\V1\Pixel $pixelApi */
            $pixelApi = w_obj(\Weline\Visitor\Api\Rest\V1\Pixel::class);
            $result = $pixelApi->postIndex();
            $response = json_decode($result, true);
            
            if ($response['code'] === 200 && isset($response['data']['pixel_id'])) {
                $pixelId = $response['data']['pixel_id'];
                
                // 验证附加数据
                $additional = \Weline\Visitor\Model\PixelAdditional::getByPixelId($pixelId);
                $eventData = $additional ? json_decode($additional->getTotalEventData(), true) : null;
                
                return $this->success('A/B测试数据保存测试完成', [
                    'test_name' => 'A/B测试数据保存',
                    'request_data' => $testData,
                    'response' => $response,
                    'pixel_id' => $pixelId,
                    'ab_test_in_response' => $response['data']['ab_test'] ?? null,
                    'ab_test_in_additional' => $eventData ? [
                        'testId' => $eventData['testId'] ?? null,
                        'variant' => $eventData['variant'] ?? null
                    ] : null,
                    'status' => '成功'
                ]);
            } else {
                return $this->error('测试失败', [
                    'response' => $response
                ]);
            }
        } catch (\Exception $e) {
            return $this->error('测试失败: ' . $e->getMessage(), [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 运行所有测试
     * 
     * 访问: /visitor/test/http/pixel-api-http-test/run-all
     */
    public function getRunAll()
    {
        $tests = [
            'plain-data' => '接收明文像素数据',
            'encrypted-data' => '接收加密像素数据',
            'data-validation' => '数据验证和清理',
            'website-id' => '站点ID识别',
            'ab-test-data' => 'A/B测试数据保存'
        ];

        $results = [];
        
        foreach ($tests as $method => $name) {
            try {
                $methodName = 'get' . str_replace('-', '', ucwords($method, '-'));
                $result = $this->$methodName();
                $resultData = json_decode($result, true);
                
                $results[] = [
                    'test' => $name,
                    'method' => $method,
                    'status' => $resultData['code'] === 200 ? '成功' : '失败',
                    'code' => $resultData['code'] ?? 0,
                    'message' => $resultData['msg'] ?? ''
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'test' => $name,
                    'method' => $method,
                    'status' => '异常',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $this->success('所有测试完成', [
            'test_name' => '完整测试套件',
            'total_tests' => count($tests),
            'results' => $results,
            'summary' => [
                'success' => count(array_filter($results, fn($r) => $r['status'] === '成功')),
                'failed' => count(array_filter($results, fn($r) => $r['status'] === '失败')),
                'error' => count(array_filter($results, fn($r) => $r['status'] === '异常'))
            ]
        ]);
    }
}

