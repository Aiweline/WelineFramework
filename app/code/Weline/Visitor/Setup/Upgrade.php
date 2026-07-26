<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Visitor\Setup;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Visitor\Service\PixelChannelRuleSeedService;
use Weline\Visitor\Service\PixelEncryptionService;
use Weline\Visitor\Service\VisitorDashboardPageInstaller;

/**
 * Visitor模块升级脚本
 *
 * 负责在模块升级时管理像素加密token
 */
class Upgrade implements UpgradeInterface
{
    /**
     * 执行升级
     *
     * @param Setup $setup
     * @param Context $context
     * @return void
     * @throws \Exception
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 检查是否在生产模式
        $deployMode = Env::system('deploy');
        if ($deployMode === 'prod') {
            // 生成版本号并创建token
            $this->generateVersionToken($context);
        }

        ObjectManager::getInstance(VisitorDashboardPageInstaller::class)->ensurePages();
        $this->backfillPixelCreatedAt();
        $this->seedPixelChannelRules();
    }

    /**
     * B08：升级时幂等种子 pixel_channel rule（来自 PixelSource）。
     */
    private function seedPixelChannelRules(): void
    {
        try {
            ObjectManager::getInstance(PixelChannelRuleSeedService::class)->seed(false);
        } catch (\Throwable $e) {
            w_log_warning('PixelChannel rule seed skipped on upgrade: ' . $e->getMessage());
        }
    }

    /**
     * 历史像素记录未写入 created_at 时，用 create_time 回填，避免看板按时间过滤恒为 0。
     */
    private function backfillPixelCreatedAt(): void
    {
        try {
            $pixel = ObjectManager::getInstance(\Weline\Visitor\Model\Pixel::class);
            $table = (string)$pixel->getTable();
            $prefix = (string)$pixel->getConnection()->getConfigProvider()->getPrefix();
            if ($prefix !== '' && !str_starts_with($table, $prefix) && !str_contains($table, '"') && !str_contains($table, '`')) {
                $table = $prefix . $table;
            }
            $pdo = $pixel->getConnection()->getConnector()->getLink();
            $pdo->exec(
                "UPDATE {$table} SET created_at = create_time"
                . " WHERE (created_at IS NULL OR created_at = '')"
                . " AND create_time IS NOT NULL AND create_time <> ''"
            );
        } catch (\Throwable $e) {
            // 回填失败不阻断升级；看板已 COALESCE(create_time) 兜底。
            w_log_warning('Pixel created_at backfill skipped: ' . $e->getMessage());
        }
    }

    /**
     * 生成版本号token
     *
     * @param Context $context
     * @return void
     * @throws \Exception
     */
    private function generateVersionToken(Context $context): void
    {
        // 获取Deploy模块的版本号
        $deployVersion = $this->getDeployModuleVersion();
        if (!$deployVersion) {
            // 如果无法获取Deploy版本号，使用当前日期作为版本号
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
            return;
        }

        // 生成新token（内部会自动标记90天前的旧token为已删除）
        $encryptionService->generateTokenForVersion($fullVersion);
    }

    /**
     * 获取Deploy模块的版本号
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
