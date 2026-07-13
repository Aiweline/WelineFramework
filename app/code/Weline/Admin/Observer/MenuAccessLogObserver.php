<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Observer;

use Weline\Acl\Api\Resource\MenuResourceServiceInterface;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class MenuAccessLogObserver implements ObserverInterface
{
    private Request $request;
    private AuthenticatedSessionInterface $backendSession;
    private MenuResourceServiceInterface $menuResourceService;

    public function __construct(
        Request $request,
        MenuResourceServiceInterface $menuResourceService
    ) {
        $this->request = $request;
        $this->backendSession = SessionFactory::getInstance()->createBackendSession();
        $this->menuResourceService = $menuResourceService;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // WLS 下 Observer 可能复用旧实例，这里强制切到当前请求和会话上下文。
        $this->request = ObjectManager::getInstance(Request::class);
        $this->backendSession = SessionFactory::getInstance()->createBackendSession();

        // 只处理后台请求
        if (!$this->request->isBackend()) {
            return;
        }

        // 绕过ajax请求
        if ($this->request->isAjax()) {
            return;
        }

        // 绕过iframe请求
        if ($this->request->isIframe()) {
            return;
        }

        // 获取当前登录用户
        $userId = $this->backendSession->getUserId();
        if (!$userId) {
            return;
        }

        // 获取当前路由路径
        $route = $this->request->getRouteUrlPath();
        $method = $this->request->getMethod();

        $sourceId = $this->menuResourceService->findEnabledBackendMenuSource($route, $method);
        if ($sourceId !== null) {
            /** @var MenuAccessLog $menuAccessLog */
            $menuAccessLog = ObjectManager::getInstance(MenuAccessLog::class);
            
            // 防止短时间内重复记录（同一用户、同一菜单在30秒内的重复访问不记录）
            $sessionKey = 'menu_access_log_' . $userId . '_' . $sourceId;
            /** @var AuthenticatedSessionInterface $backendSession */
            $backendSession = SessionFactory::getInstance()->createBackendSession();
            $lastAccessTime = $backendSession->getData($sessionKey);
            $currentTime = time();
            
            // 如果上次访问时间在30秒内，跳过记录
            if ($lastAccessTime && ($currentTime - $lastAccessTime) < 30) {
                return;
            }
            
            // 更新最后访问时间
            $backendSession->setData($sessionKey, $currentTime);
            
            try {
                // 清除常用菜单缓存，强制下次查询时重新计算
                $cache = w_cache('default');
                // 清除最近访问和访问最多的缓存
                $cache->delete('frequent_menus_' . $userId . '_20_7');
                $cache->delete('recent_menus_' . $userId . '_20_7');
                
                $menuAccessLog->clearData()
                    ->setUserId($userId)
                    ->setSourceId($sourceId)
                    ->setRoute($route)
                    ->setMethod($method)
                    ->setAccessTime($currentTime)
                    ->save();
            } catch (\Exception $e) {
                // 记录访问失败不应该影响正常流程，静默处理
            }
        }
    }
}
