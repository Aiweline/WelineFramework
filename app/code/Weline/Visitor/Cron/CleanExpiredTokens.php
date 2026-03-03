<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Visitor\Cron;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelEncryptionToken;

/**
 * 清理过期Token的定时任务
 * 
 * 定期清理已删除且超过90天的token记录
 */
class CleanExpiredTokens
{
    /**
     * 执行清理任务
     * 
     * @return void
     */
    public function execute(): void
    {
        try {
            /** @var PixelEncryptionToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(PixelEncryptionToken::class);
            
            // 计算90天前的日期
            $ninetyDaysAgo = date('Y-m-d H:i:s', strtotime('-90 days'));
            
            // 查找已删除且删除时间超过90天的token
            $expiredTokens = $tokenModel->reset()
                ->where(PixelEncryptionToken::fields_IS_DELETED, 1)
                ->where(PixelEncryptionToken::fields_DELETED_AT, $ninetyDaysAgo, '<=')
                ->select()
                ->fetchArray();
            
            $deletedCount = 0;
            foreach ($expiredTokens as $tokenData) {
                $token = clone $tokenModel;
                $token->setData($tokenData);
                $token->delete();
                $deletedCount++;
            }
            
            if ($deletedCount > 0) {
                w_log_info(sprintf(
                    '[CleanExpiredTokens] 已清理 %d 个过期的像素加密token',
                    $deletedCount
                ));
            }
            
        } catch (\Exception $e) {
            w_log_error('[CleanExpiredTokens] 清理过期token时出错：' . $e->getMessage());
        }
    }
}

