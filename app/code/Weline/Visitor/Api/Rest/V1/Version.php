<?php

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\PixelEncryptionService;

/**
 * 版本号API
 * 提供获取当前像素加密版本号的接口
 */
class Version extends FrontendRestController
{
    /**
     * 获取当前版本号
     * 
     * @return string
     * @Document(summary='获取当前版本号', description='获取当前像素加密的版本号，用于前端加密像素数据', tags=['像素', '版本'], category='像素接口')
     */
    public function getCurrent(): string
    {
        try {
            /** @var PixelEncryptionService $encryptionService */
            $encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
            
            $token = $encryptionService->getCurrentVersionToken();
            
            if (!$token) {
                // 如果没有token，返回空版本号（开发模式）
                return $this->success(__('获取版本号成功'), [
                    'version' => null,
                    'hasToken' => false
                ]);
            }
            
            return $this->success(__('获取版本号成功'), [
                'version' => $token->getVersion(),
                'hasToken' => true
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('获取版本号失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 加密像素数据
     * 
     * 前端调用此接口加密像素数据，然后发送加密后的数据到像素API
     * 
     * @return string
     * @Document(summary='加密像素数据', description='使用当前版本号的token加密像素数据，返回加密后的数据', tags=['像素', '加密'], category='像素接口')
     */
    public function postEncrypt(): string
    {
        try {
            $post = $this->request->getBodyParams();
            
            if (empty($post['data'])) {
                return $this->error(__('缺少数据参数'));
            }
            
            $data = $post['data'];
            $version = $post['version'] ?? null;
            
            /** @var PixelEncryptionService $encryptionService */
            $encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
            
            // 加密数据
            $encrypted = $encryptionService->encrypt($data, $version);
            
            // 获取实际使用的版本号
            $actualVersion = $version;
            if (!$actualVersion) {
                $token = $encryptionService->getCurrentVersionToken();
                $actualVersion = $token ? $token->getVersion() : null;
            }
            
            return $this->success(__('加密成功'), [
                'encrypted' => $encrypted,
                'version' => $actualVersion
            ]);
            
        } catch (\Exception $e) {
            return $this->error(__('加密失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
}

