<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleManager\Controller\Backend;

use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Console\Module\Upgrade;
use Weline\Framework\System\File\Io\File;

class Module extends \Weline\Framework\App\Controller\BackendController
{
    public function postStatus(): string
    {
        $module = $this->request->getParam('module');
        $status = $this->request->getParam('status') === 'true';
        # 更新数据库
        /**@var \Weline\ModuleManager\Model\Module $moduleModel */
        $moduleModel = ObjectManager::getInstance(\Weline\ModuleManager\Model\Module::class);
        $moduleModel->load('name', $module);
        $moduleModel->setStatus($status ? 1 : 0);
        try {
            $moduleModel->save();
            # 保存环境module
            $env     = Env::getInstance();
            $modules = $env->getModuleList();
            if ($module_data = $modules[$module] ?? []) {
                $module_data['status'] = (bool)$status;
                $modules[$module]      = $module_data;
                $file                  = (new File())->open(Env::path_MODULES_FILE, File::mode_w_add);
                $file->write('<?php return ' . w_var_export($modules, true) . ';');
                $file->close();
            }
            // 更新注册表
            try {
                /** @var \Weline\Framework\Registry\Service\RegistryUpdateService $registryService */
                $registryService = ObjectManager::getInstance(\Weline\Framework\Registry\Service\RegistryUpdateService::class);
                $registryService->updateAllRegistries(true); // 静默执行
            } catch (\Exception $e) {
                // 注册表更新失败不影响模块状态更新，只记录日志
                Env::log_warning('registry_update.log', __('模块状态变更后注册表更新失败: %{1}', [$e->getMessage()]));
            }
            
            return $this->fetchJson(['code' => 200, 'msg' => __('操作成功！'), 'data' => $status]);
        } catch (\Exception $exception) {
            return $this->fetchJson(['code' => 403, 'msg' => $exception->getMessage(), 'data' => $status]);
        }
    }
}
