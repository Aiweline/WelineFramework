<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Extends\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Extends\Model\ExtendsRule;
use Weline\Extends\Service\CircularDependencyDetector;

/**
 * 扩展管理控制器
 */
class ExtendsController extends BackendController
{
    /**
     * 扩展列表页面
     */
    public function index()
    {
        /** @var ExtendsRule $extendsRule */
        $extendsRule = ObjectManager::getInstance(ExtendsRule::class);
        $allExtends = $extendsRule->getAllExtends();

        $this->assign('extends', $allExtends);
        $this->assign('title', __('扩展管理'));
        return $this->fetch();
    }

    /**
     * 模块扩展详情
     */
    public function detail()
    {
        $moduleName = $this->request->getParam('module');
        if (empty($moduleName)) {
            $this->getMessageManager()->addWarning(__('请指定模块名'));
            $this->redirect('*/index');
            return;
        }

        /** @var ExtendsRule $extendsRule */
        $extendsRule = ObjectManager::getInstance(ExtendsRule::class);
        $moduleExtends = $extendsRule->getModuleExtends($moduleName);
        $extendedBy = $extendsRule->getExtendedBy($moduleName);

        $this->assign('module', $moduleName);
        $this->assign('extends', $moduleExtends);
        $this->assign('extended_by', $extendedBy);
        $this->assign('title', __('模块扩展详情') . ': ' . $moduleName);
        return $this->fetch();
    }

    /**
     * 循环依赖检测
     */
    public function checkCircular()
    {
        try {
            /** @var CircularDependencyDetector $detector */
            $detector = ObjectManager::getInstance(CircularDependencyDetector::class);
            $cycles = $detector->detectAll();

            if (empty($cycles)) {
                $this->getMessageManager()->addSuccess(__('未检测到循环依赖'));
            } else {
                $this->getMessageManager()->addError(__('检测到循环依赖，请查看详情'));
                $this->assign('cycles', $cycles);
            }
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        $this->assign('title', __('循环依赖检测'));
        return $this->fetch();
    }

    /**
     * 刷新扩展注册表
     */
    public function refresh()
    {
        try {
            /** @var \Weline\Framework\Extends\ExtendsRegistry $registry */
            $registry = ObjectManager::getInstance(\Weline\Framework\Extends\ExtendsRegistry::class);
            $result = $registry->refresh();

            if ($result) {
                $this->getMessageManager()->addSuccess(__('扩展注册表刷新成功'));
            } else {
                $this->getMessageManager()->addError(__('扩展注册表刷新失败'));
            }
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('刷新失败: %1', $e->getMessage()));
        }

        $this->redirect('*/index');
    }
}

