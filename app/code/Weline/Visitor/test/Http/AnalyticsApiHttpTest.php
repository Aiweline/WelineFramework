<?php

/**
 * 分析API HTTP集成测试
 * 
 * 测试像素数据分析API的HTTP请求和响应
 */

namespace Weline\Visitor\Test\Http;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Visitor\Model\Pixel;

class AnalyticsApiHttpTest extends FrontendRestController
{
    /**
     * 测试：商业价值分析
     * 
     * 访问: /visitor/test/http/analytics-api-http-test/business-value
     */
    public function getBusinessValue()
    {
        try {
            $websiteId = (int)($this->request->getParam('websiteId') ?? 1);
            $period = $this->request->getParam('period') ?? 'daily';
            
            // 调用分析API
            /** @var \Weline\Visitor\Api\Rest\V1\Analytics $analyticsApi */
            $analyticsApi = w_obj(\Weline\Visitor\Api\Rest\V1\Analytics::class);
            
            // 设置请求参数
            $_GET['websiteId'] = $websiteId;
            $_GET['period'] = $period;
            
            $result = $analyticsApi->getBusinessValue();
            $response = json_decode($result, true);
            
            return $this->success('商业价值分析测试完成', [
                'test_name' => '商业价值分析',
                'parameters' => [
                    'websiteId' => $websiteId,
                    'period' => $period
                ],
                'response' => $response,
                'status' => $response['code'] === 200 ? '成功' : '失败',
                'data_summary' => $response['code'] === 200 ? [
                    'total_value' => $response['data']['total_value'] ?? 0,
                    'total_events' => $response['data']['total_events'] ?? 0,
                    'data_points_count' => count($response['data']['data_points'] ?? [])
                ] : null
            ]);
        } catch (\Exception $e) {
            return $this->error('测试失败: ' . $e->getMessage(), [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 测试：实时大屏数据
     * 
     * 访问: /visitor/test/http/analytics-api-http-test/dashboard
     */
    public function getDashboard()
    {
        try {
            $websiteId = (int)($this->request->getParam('websiteId') ?? 1);
            $interval = $this->request->getParam('interval') ?? '10';
            $hours = (int)($this->request->getParam('hours') ?? 24);
            
            /** @var \Weline\Visitor\Api\Rest\V1\Analytics $analyticsApi */
            $analyticsApi = w_obj(\Weline\Visitor\Api\Rest\V1\Analytics::class);
            
            $_GET['websiteId'] = $websiteId;
            $_GET['interval'] = $interval;
            $_GET['hours'] = $hours;
            
            $result = $analyticsApi->getDashboard();
            $response = json_decode($result, true);
            
            return $this->success('实时大屏数据测试完成', [
                'test_name' => '实时大屏数据',
                'parameters' => [
                    'websiteId' => $websiteId,
                    'interval' => $interval,
                    'hours' => $hours
                ],
                'response' => $response,
                'status' => $response['code'] === 200 ? '成功' : '失败',
                'data_summary' => $response['code'] === 200 ? [
                    'current_period' => $response['data']['current_period'] ?? null,
                    'change_percentage' => $response['data']['change_percentage'] ?? 0,
                    'data_points_count' => count($response['data']['data_points'] ?? [])
                ] : null
            ]);
        } catch (\Exception $e) {
            return $this->error('测试失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试：A/B测试数据
     * 
     * 访问: /visitor/test/http/analytics-api-http-test/ab-test
     */
    public function getAbTest()
    {
        try {
            $websiteId = (int)($this->request->getParam('websiteId') ?? 1);
            $testId = $this->request->getParam('testId') ?? 'test_001';
            
            /** @var \Weline\Visitor\Api\Rest\V1\Analytics $analyticsApi */
            $analyticsApi = w_obj(\Weline\Visitor\Api\Rest\V1\Analytics::class);
            
            $_GET['websiteId'] = $websiteId;
            $_GET['testId'] = $testId;
            
            $result = $analyticsApi->getAbTest();
            $response = json_decode($result, true);
            
            return $this->success('A/B测试数据测试完成', [
                'test_name' => 'A/B测试数据',
                'parameters' => [
                    'websiteId' => $websiteId,
                    'testId' => $testId
                ],
                'response' => $response,
                'status' => $response['code'] === 200 ? '成功' : '失败',
                'data_summary' => $response['code'] === 200 ? [
                    'variants' => array_keys($response['data']['variants'] ?? []),
                    'winner' => $response['data']['winner'] ?? null,
                    'improvement' => $response['data']['improvement'] ?? 0
                ] : null
            ]);
        } catch (\Exception $e) {
            return $this->error('测试失败: ' . $e->getMessage());
        }
    }

    /**
     * 运行所有分析API测试
     * 
     * 访问: /visitor/test/http/analytics-api-http-test/run-all
     */
    public function getRunAll()
    {
        $tests = [
            'business-value' => '商业价值分析',
            'dashboard' => '实时大屏数据',
            'ab-test' => 'A/B测试数据'
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
                    'code' => $resultData['code'] ?? 0
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

        return $this->success('所有分析API测试完成', [
            'test_name' => '分析API完整测试套件',
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

