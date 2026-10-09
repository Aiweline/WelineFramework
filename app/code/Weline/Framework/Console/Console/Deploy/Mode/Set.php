<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Deploy\Mode;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\Console\Deploy\Upgrade;
use Weline\Framework\Deploy\DeployFpcInvalidation;
use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Console\Setup\Di\Compile;

class Set extends CommandAbstract
{
    /**
     * @var System
     */
    private System $system;

    public function __construct(
        System $system
    )
    {
        $this->system = $system;
    }

    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);
        $param = array_shift($args);
        $this->deploy($param);
    }

    public function tip(): string
    {
        return '部署模式设置。（dev:开发模式；prod:生产环境。）';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }

    /**
     * 直接删除各模块 view/tpl 编译目录（一次 rm -rf，不做 PHP 递归掏空）。
     * 仅 dev 路径使用；prod 走 staging 管道，结束后再清残留。
     */
    protected function cleanTplComDir()
    {
        $modules = Env::getInstance()->getModuleList();
        foreach ($modules as $module) {
            $tpl_dir = $module['base_path'] . DS . 'view' . DS . 'tpl';
            if (is_dir($tpl_dir)) {
                $this->system->exec('rm -rf ' . \escapeshellarg($tpl_dir));
            }
        }
    }

    /**
     * 直接删除 generated/complicate 整树（编译产物用删，不用 flush 逐文件掏空）。
     * 仅 dev 路径使用。
     */
    public function clearGeneratedComplicateDir()
    {
        $complicate = Env::path_COMPLICATE_GENERATED_DIR;
        if ($complicate === '' || !\is_dir($complicate)) {
            return;
        }
        $this->system->exec('rm -rf ' . \escapeshellarg($complicate));
    }

    /**
     * @param mixed $param
     * @return void
     * @throws \Weline\Framework\App\Exception
     */
    public function deploy(string $type): void
    {
// 如果当前是线上环境，应当提醒开发者切换到其他模式的风险
        if ($type !== 'prod' && (Env::system('deploy') === 'prod')) {
            $this->printer->setup(__('当前部署模式为prod(生产模式)，请谨慎操作！你确认要切换到 %{1} 模式么？', (string)$type));
            $input = $this->system->input();
            if (strtolower(chop($input)) !== 'y') {
                $this->printer->setup(__('已为您取消操作！'));
                return;
            }
        }
        // flush：只刷缓存池 / L1；prod 不删活树编译目录（staging 管道末尾 rename 切换）。
        $this->printer->note('刷新缓存池（flush）...');
        /**@var $cacheManagerConsole \Weline\Framework\Cache\Console\Cache\Clear */
        $cacheManagerConsole = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Clear::class);
        $cacheManagerConsole->execute([], ['skip_view_compile' => true]);
        switch ($type) {
            case 'prod':
                $this->deployProdViaStaging();
                break;
            case 'dev':
                $this->printer->note('正在删除模组模板编译目录...');
                $this->cleanTplComDir();
                $this->clearGeneratedComplicateDir();
                $this->printer->note('开发模式：模板编译目录已删除，将按需重编译。');
                break;
            default:
                $this->printer->error(' ╮(๑•́ ₃•̀๑)╭  ：错误的部署模式：' . $type);
                $this->printer->note('(￢_￢) ->：允许的部署模式：dev/prod');
                return;
        }
        if ($this->persistDeployMode($type)) {
            $this->printer->success('（●´∀｀）♪ 当前部署模式：' . $type);
        } else {
            $this->printer->error('╮(๑•́ ₃•̀๑)╭ 部署模式设置错误：' . $type);
        }
    }

    /**
     * Prod: open staging → write all artifacts there → rename-swap → purge prev.
     * Live pub/static / complicate / theme-layout stay readable until commitSwap.
     */
    private function deployProdViaStaging(): void
    {
        $session = DeployStagingSession::open();
        $this->printer->note(__(
            '部署 staging 已打开（stamp=%{stamp}，root=%{root}）',
            ['stamp' => $session->stamp(), 'root' => rtrim($session->root(), '/\\')]
        ));
        try {
            $this->printer->note('编译静态资源...');
            ObjectManager::getInstance(Compile::class)->execute();
            $this->printer->note('正在执行静态资源部署（写入 staging/static）...');
            /**@var $deploy_upgrade Upgrade */
            $deploy_upgrade = ObjectManager::getInstance(Upgrade::class);
            // Mode\Set 末尾走强失效（bump+purge）；Upgrade 内跳过日常 bump，避免双跑。
            $deploy_upgrade->execute([], [Upgrade::DATA_SKIP_INVALIDATION => true]);

            $this->printer->note('正在将 staging 切换为活树（rename）...');
            $session->commitSwap();
            // Clear staging env before deleting leftover staging dirs (avoid late writers).
            $session->deactivate();
            $session->purgePrevAndResidue();

            /** @var DeployFpcInvalidation $fpcInvalidation */
            $fpcInvalidation = ObjectManager::getInstance(DeployFpcInvalidation::class);
            $invalidation = $fpcInvalidation->afterModeSetProd();
            $this->printer->note(__(
                '部署呈现世代已失效（stamp=%{stamp}，purge_fpc_all=1）',
                ['stamp' => $invalidation['stamp']]
            ));

            // 派发事件，通知其他模块部署模式已切换到prod
            // 事件场 deploy_version = Deploy 模块版本（≠ current.json stamp；见 C-STAMP）
            /** @var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = new \Weline\Framework\DataObject\DataObject([
                'mode' => 'prod',
                'deploy_version' => $this->getDeployModuleVersion(),
                'deploy_stamp' => $invalidation['stamp'],
                'printer' => $this->printer
            ]);
            $eventManager->dispatch('Weline_Framework_Deploy_Mode_Set::prod_after', $eventData);
        } catch (\Throwable $error) {
            $session->abort();
            throw $error;
        }
    }

    /**
     * 持久化部署模式：权威键 system.deploy（与 App DEV/PROD、mode:show 同源）。
     * 同步顶层 deploy，兼容仍读 getConfig('deploy') 的历史读者。
     */
    public function persistDeployMode(string $type): bool
    {
        if ($type !== 'dev' && $type !== 'prod') {
            return false;
        }
        $env = Env::getInstance();
        $okSystem = $env->setConfig('system.deploy', $type);
        $okLegacy = $env->setConfig('deploy', $type);

        return $okSystem && $okLegacy;
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
