<?php

declare(strict_types=1);

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Visitor\Api\Rest\PanelProtectedTrait;
use Weline\Visitor\Api\Rest\VisitorPanelAnalyticsActionsTrait;

/**
 * 兼容入口：/api/visitor/rest/v1/panel/*
 *
 * 面板前端 canonical 路径是 /analytics/*（见 Analytics + VisitorPanelAnalyticsActionsTrait）。
 * 保留本控制器避免旧链接与已生成路由断裂。
 */
class Panel extends FrontendRestController
{
    use PanelProtectedTrait;
    use VisitorPanelAnalyticsActionsTrait;
}
