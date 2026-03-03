<?php

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelAdditional;
use Weline\Visitor\Service\PixelEncryptionService;

class Pixel extends FrontendRestController
{
    public function __construct(
        private \Weline\Visitor\Model\Pixel $pixel,
        private PixelAdditional $pixelAdditional
    ) {
    }

    /**
     * 接收像素数据
     * 
     * 接收前端发送的像素数据，支持明文和加密两种格式
     * 
     * @return string
     * @Document(summary='接收像素数据', description='接收前端发送的像素数据，支持明文和加密两种格式。自动识别站点ID并保存到数据库。', tags=['像素', '数据收集'], category='像素接口')
     * @example
     * Method: POST
     * Path: /visitor/rest/v1/pixel
     * Header:
     * - Content-Type: application/json
     * Body (明文):
     * {
     *   "url": "https://example.com/page",
     *   "eventName": "click",
     *   "websiteId": 1,
     *   "userId": 123,
     *   "userAgent": "Mozilla/5.0...",
     *   "ip": "192.168.1.1",
     *   "testId": "button_color_test",
     *   "variant": "A"
     * }
     * Body (加密):
     * {
     *   "encrypted": "base64_encrypted_data",
     *   "version": "1.0.0-20250101"
     * }
     * Response:
     * {
     *   "code": 200,
     *   "msg": "请求成功！",
     *   "data": {
     *     "pixel_id": 12345,
     *     "pixel_additional_id": 67890,
     *     "ab_test": {
     *       "testId": "button_color_test",
     *       "variant": "A"
     *     }
     *   }
     * }
     * @example-end
     */
    public function postIndex()
    {
        $post = $this->request->getBodyParams();
        
        // 检查是否是加密数据
        if (isset($post['encrypted']) && isset($post['version'])) {
            // 解密数据
            try {
                /** @var PixelEncryptionService $encryptionService */
                $encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
                $post = $encryptionService->decrypt($post['encrypted'], $post['version']);
                
                // 确保解密后的数据是数组
                if (!is_array($post)) {
                    return $this->error('解密后的数据格式错误');
                }
            } catch (\Exception $e) {
                return $this->error('解密失败：' . $e->getMessage());
            }
        }
        
        # source转化
        $post['source'] = $post['source'] ?? 'direct';
        # 获取客户端IP（如果数据中没有IP，使用请求的IP）
        $ip = $post['ip'] ?? $this->request->clientIP();
        
        # 验证和清理IP地址
        if (!empty($ip) && !filter_var($ip, FILTER_VALIDATE_IP)) {
            // 如果IP格式无效，使用请求的IP
            $ip = $this->request->clientIP();
        }
        
        # 获取站点ID（优先从请求数据获取，其次从SERVER变量获取，最后默认为0）
        $websiteId = 0;
        if (isset($post['websiteId']) && $post['websiteId'] !== '') {
            $websiteId = (int)$post['websiteId'];
        } elseif (isset($post['siteId']) && $post['siteId'] !== '') {
            $websiteId = (int)$post['siteId'];
        } elseif (isset($_SERVER['WELINE_WEBSITE_ID']) && $_SERVER['WELINE_WEBSITE_ID'] !== '') {
            $websiteId = (int)$_SERVER['WELINE_WEBSITE_ID'];
        }
        
        # 验证必要字段
        if (empty($post['eventName']) && empty($post['event'])) {
            $post['eventName'] = 'click'; // 默认事件名
        }
        
        # 清理和验证URL
        $url = $post['url'] ?? '';
        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            // 如果URL格式无效，尝试修复或使用空字符串
            if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
                $url = ''; // 无效的URL，使用空字符串
            }
        }
        
        # 清理和验证其他字段（限制长度，防止数据库溢出）
        $module = substr($post['module'] ?? '', 0, 255); // 限制长度
        $name = substr($post['name'] ?? '', 0, 255); // 限制长度
        $event = substr($post['eventName'] ?? 'click', 0, 255); // 限制长度
        $lang = substr($post['userLang'] ?? '', 0, 255); // 限制长度
        $currency = substr($post['currency'] ?? '', 0, 255); // 限制长度
        $referer = substr($post['referer'] ?? '', 0, 255); // 限制长度
        $userAgent = substr($post['userAgent'] ?? '', 0, 255); // 限制长度
        
        $data = [
            'url' => $url,
            'module' => $module,
            'name' => $name,
            'event' => $event,
            'value' => max(0, (int)($post['value'] ?? 0)), // 确保非负
            'lang' => $lang,
            'currency' => $currency,
            'website_id' => max(0, $websiteId), // 确保非负
            'referer' => $referer,
            'user_id' => max(0, (int)($post['userId'] ?? 0)), // 确保非负
            'user_agent' => $userAgent,
            'ip' => $ip,
            'browser_info' => json_encode([
                'additionalInfo' => is_array($post['additionalInfo'] ?? null) ? $post['additionalInfo'] : [],
                'screen' => is_array($post['screen'] ?? null) ? $post['screen'] : []
            ], JSON_UNESCAPED_UNICODE),
        ];
        
        try {
            $this->pixel->save($data);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
        
        $pixel_id = $this->pixel->getId();
        $additionalData = $post; // 初始化additionalData，用于响应
        
        if ($pixel_id) {
            try {
                // 清理和验证A/B测试数据
                if (isset($post['testId']) || isset($post['variant']) || isset($post['test_id']) || isset($post['testVariant'])) {
                    // 统一字段名：使用testId和variant
                    if (isset($post['test_id']) && !isset($additionalData['testId'])) {
                        $additionalData['testId'] = substr($post['test_id'], 0, 255); // 限制长度
                    } elseif (isset($post['testId'])) {
                        $additionalData['testId'] = substr($post['testId'], 0, 255); // 限制长度
                    }
                    
                    if (isset($post['testVariant']) && !isset($additionalData['variant'])) {
                        $additionalData['variant'] = substr($post['testVariant'], 0, 10); // 限制长度（通常A或B）
                    } elseif (isset($post['variant'])) {
                        $additionalData['variant'] = substr($post['variant'], 0, 10); // 限制长度
                    }
                }
                
                $this->pixelAdditional->setPixelId($pixel_id)
                    ->setTotalEventData(json_encode($additionalData, JSON_UNESCAPED_UNICODE))
                    ->save();
            } catch (Exception $e) {
                // 记录错误但不影响主流程（附加数据保存失败不应该影响主数据保存）
                w_log_error('Pixel Additional Save Error: ' . $e->getMessage());
                // 可以选择返回错误或继续执行（这里选择继续执行，因为主数据已保存）
                // return $this->error('保存附加数据失败：' . $e->getMessage());
            }
        }
        
        // 构建响应数据
        $responseData = [
            'pixel_id' => $pixel_id,
            'pixel_additional_id' => $this->pixelAdditional->getId() ?: null,
        ];
        
        // 如果包含A/B测试数据，添加到响应中
        if (isset($additionalData['testId']) || isset($additionalData['variant'])) {
            $responseData['ab_test'] = [
                'testId' => $additionalData['testId'] ?? null,
                'variant' => $additionalData['variant'] ?? null
            ];
        }
        
        return $this->fetch($responseData);
    }
}