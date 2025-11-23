<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Visitor\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\PixelEncryptionService;

/**
 * 部署模式切换到prod后的观察者
 * 
 * 监听 Weline_Framework_Deploy_Mode_Set::prod_after 事件
 * 在切换到prod模式时，自动生成基于Deploy版本号的加密token
 */
class DeployModeSetProdAfter implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        try {
            $data = $event->getData();
            $mode = $data->getData('mode');
            
            // 只处理prod模式
            if ($mode !== 'prod') {
                return;
            }
            
            // 获取Deploy模块的版本号
            $deployVersion = $data->getData('deploy_version');
            if (!$deployVersion) {
                // 如果事件数据中没有版本号，尝试从register.php读取
                $deployVersion = $this->getDeployModuleVersion();
            }
            
            if (!$deployVersion) {
                // 如果无法获取Deploy版本号，使用默认版本号
                $deployVersion = '1.0.0';
            }
            
            // 生成完整版本号：{基础版本号}-{日期}
            $date = date('Ymd');
            $fullVersion = $deployVersion . '-' . $date;
            
            /** @var PixelEncryptionService $encryptionService */
            $encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
            
            // 检查该版本号的token是否已存在
            $existingToken = $encryptionService->getTokenByVersion($fullVersion);
            if ($existingToken && $existingToken->getTokenId()) {
                // Token已存在，跳过生成
                $printer = $data->getData('printer');
                if ($printer) {
                    $printer->note('像素加密token已存在（版本：' . $fullVersion . '）');
                }
                return;
            }
            
            // 生成新token（内部会自动标记90天前的旧token为已删除）
            $token = $encryptionService->generateTokenForVersion($fullVersion);
            
            // 保存版本号到配置中，用于静态文件版本号
            Env::getInstance()->setConfig('pixel_version', $fullVersion);
            
            $printer = $data->getData('printer');
            if ($printer) {
                $printer->success('像素加密token已生成（版本：' . $fullVersion . '）');
            }
        } catch (\Exception $e) {
            // 静默失败，不影响部署流程
            $data = $event->getData();
            $printer = $data->getData('printer');
            if ($printer) {
                $printer->warning('生成像素加密token时出错：' . $e->getMessage());
            }
            error_log('DeployModeSetProdAfter Observer Error: ' . $e->getMessage());
        }
    }

    /**
     * 获取Deploy模块的版本号
     * 
     * 从Weline_Deploy模块的register.php文件中读取版本号
     * 
     * @return string|null
     */
    private function getDeployModuleVersion(): ?string
    {
        try {
            $deployRegisterFile = BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Deploy' . DS . 'register.php';
            if (!file_exists($deployRegisterFile)) {
                return null;
            }
            
            // 读取register.php文件内容
            $content = file_get_contents($deployRegisterFile);
            
            // 使用正则表达式提取版本号
            // Register::register(..., '版本号', ...)
            if (preg_match("/Register::register\s*\([^,]+,\s*[^,]+,\s*[^,]+,\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                return $matches[1] ?? null;
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}

