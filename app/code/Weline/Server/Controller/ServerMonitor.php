<?php
declare(strict_types=1);

namespace Weline\Server\Controller;

/**
 * 兼容旧路由：/server/server-monitor/*
 * 复用后台控制器实现，避免历史链接 404。
 */
class ServerMonitor extends \Weline\Server\Controller\Backend\ServerMonitor
{
}
