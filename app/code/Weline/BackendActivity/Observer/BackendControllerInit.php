<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：29/3/2024 13:46:48
 */

namespace Weline\BackendActivity\Observer;

use Weline\Acl\Model\Acl;
use Weline\Backend\Session\BackendSession;
use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Log;

class BackendControllerInit implements ObserverInterface
{
    private Request $request;
    private BackendSession $backendSession;
    private Acl $acl;

    function __construct(Request $request, BackendSession $backendSession, Acl $acl)
    {
        $this->request        = $request;
        $this->backendSession = $backendSession;
        $this->acl            = $acl;
    }

    function execute(Event $event): void
    {
        # ACL访问列表信息
        $acl = $this->acl->where(Acl::fields_CLASS, $this->request->getRouterData('class/name'))
            ->where(Acl::fields_METHOD, $this->request->getMethod())
            ->where(Acl::fields_ROUTE, $this->request->getRouteUrlPath())
            ->find()
            ->fetch();
        if (!$acl->getId()) {
            $name = '';
        } else {
            $name = $acl->getSourceName();
        }
        /** @var BackendActivityLog $activityLogger */
        $activityLogger = ObjectManager::getInstance(BackendActivityLog::class);
        try {
            $activityLogger->setName($name)
                ->setUserId($this->backendSession->getLoginUserID()??0)
                ->setAclId(intval($acl->getAclId()))
                ->setPath($this->request->getRouteUrlPath())
                ->setModule($this->request->getData('router/module'))
                ->setHost($this->request->getBaseHost())
                ->setUrl($this->request->getServer('REQUEST_URI'))
                ->setRequestMethod($this->request->getMethod())
                ->setRequestParams($this->request->getParams())
                ->setRequestData($this->request->getData())
                ->setIp($this->request->getUserIpAddress())
                ->setUserAgent($this->request->getServer('HTTP_USER_AGENT'))
                ->setRequestId($this->request->getId())
                ->save();
        } catch (\Exception $e) {
            ObjectManager::getInstance(Log::class)
                ->warning('File:'.(str_replace(BP, '', $e->getFile())).',Line:'.$e->getLine().','.PHP_EOL.'Error:'.PHP_EOL.$this->request->getId().':'.$e->getCode().':'.PHP_EOL.$e->getMessage());
        }
    }
}