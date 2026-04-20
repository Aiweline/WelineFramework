<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 供 CLI 队列获取 AiSiteAgent 实例：必须用 new，禁止 ObjectManager::getInstance(AiSiteAgent::class)。
 *
 * ObjectManager 创建控制器后会调用 __init() → BaseController::__init → BackendController::loginCheck()。
 * 队列进程无后台浏览器 Session，会被当成未登录而 redirect（302 / RedirectException）。
 * new AiSiteAgent(...) 只走构造函数注入依赖，不会触发 __init，与「无 HTTP 请求」语义一致。
 */
final class AiSiteAgentForQueue
{
    public static function create(): AiSiteAgent
    {
        return new AiSiteAgent(ObjectManager::getInstance(AiSiteAgentSessionService::class));
    }
}
