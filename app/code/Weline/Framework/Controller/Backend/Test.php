<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\Service\TestUiSettings;

#[Acl(
    'Weline_Framework::test:view',
    '测试管理',
    'circle',
    '收集并运行各模块 E2E / 单元测试',
    'Weline_Backend::dev_workspace',
    accessMode: Acl::ACCESS_MODE_READ,
)]
final class Test extends BackendController
{
    public const ACL_VIEW = 'Weline_Framework::test:view';
    public const ACL_CATALOG_VIEW = 'Weline_Framework::test:catalog:view';
    public const ACL_E2E_VIEW = 'Weline_Framework::test:e2e:view';
    public const ACL_E2E_RUN = 'Weline_Framework::test:e2e:run';
    public const ACL_UNIT_VIEW = 'Weline_Framework::test:unit:view';
    public const ACL_UNIT_RUN = 'Weline_Framework::test:unit:run';
    public const ACL_HISTORY_VIEW = 'Weline_Framework::test:history:view';

    #[Acl(
        self::ACL_CATALOG_VIEW,
        '查看测试目录',
        'file',
        '查看各模块测试用例目录',
        self::ACL_VIEW,
        accessMode: Acl::ACCESS_MODE_READ,
    )]
    public function getIndex(): string
    {
        $this->assign('title', __('测试管理'));
        $this->assign('page', 'modules');
        $this->assignUiSettings();
        return $this->fetch('index');
    }

    #[Acl(
        self::ACL_E2E_VIEW,
        '查看 E2E 用例',
        'monitor',
        '查看模块 E2E 用例并准备运行',
        self::ACL_VIEW,
        accessMode: Acl::ACCESS_MODE_READ,
    )]
    public function getModule(): string
    {
        $module = trim((string)$this->request->getGet('module', ''));
        $this->assign('title', __('模块测试：%{1}', [$module !== '' ? $module : __('未指定')]));
        $this->assign('page', 'module');
        $this->assign('module_name', $module);
        $this->assignUiSettings();
        return $this->fetch('index');
    }

    #[Acl(
        self::ACL_HISTORY_VIEW,
        '查看测试运行历史',
        'history',
        '查看测试运行进度、日志与历史',
        self::ACL_VIEW,
        accessMode: Acl::ACCESS_MODE_READ,
    )]
    public function getRun(): string
    {
        $runId = (int)$this->request->getGet('run_id', 0);
        $this->assign('title', __('测试运行 #%{1}', [(string)$runId]));
        $this->assign('page', 'run');
        $this->assign('run_id', $runId);
        $this->assignUiSettings();
        return $this->fetch('index');
    }

    #[Acl(
        self::ACL_E2E_RUN,
        '运行 E2E 测试',
        'play',
        '异步运行模块 E2E 测试',
        self::ACL_E2E_VIEW,
        accessMode: Acl::ACCESS_MODE_EDIT,
    )]
    public function postRunE2e(): string
    {
        return '';
    }

    #[Acl(
        self::ACL_UNIT_VIEW,
        '查看单元测试用例',
        'code',
        '查看模块单元/集成测试用例',
        self::ACL_VIEW,
        accessMode: Acl::ACCESS_MODE_READ,
    )]
    public function getUnit(): string
    {
        return '';
    }

    #[Acl(
        self::ACL_UNIT_RUN,
        '运行单元测试',
        'play',
        '异步运行模块单元/集成测试',
        self::ACL_UNIT_VIEW,
        accessMode: Acl::ACCESS_MODE_EDIT,
    )]
    public function postRunUnit(): string
    {
        return '';
    }

    private function assignUiSettings(): void
    {
        /** @var TestUiSettings $settings */
        $settings = ObjectManager::getInstance(TestUiSettings::class);
        $this->assign('ui_enabled', $settings->isUiEnabled());
    }
}
