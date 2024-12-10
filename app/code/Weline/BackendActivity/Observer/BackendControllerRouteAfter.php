<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2/4/2024 14:36:11
 */

namespace Weline\BackendActivity\Observer;

use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Log;

class BackendControllerRouteAfter implements ObserverInterface
{
    private Request $request;

    function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        if ($this->request->isBackend()) {
            $request_id = $this->request->getId();
            /** @var BackendActivityLog $backendActivityLogger */
            $backendActivityLogger = ObjectManager::getInstance(BackendActivityLog::class);
            $backendActivityLog    = $backendActivityLogger->load(BackendActivityLog::fields_request_id, $request_id);
            if ($backendActivityLog->getId()) {
                try {
                    /** @var DataObject $data */
//                    $data = $event->getData('data');
                    $result = $backendActivityLog
//                        ->setResponse($data->getData('result'))
                        ->setResponseCode(http_response_code())
                        ->setResponseTime(microtime(true) - START_TIME)
                        ->save();
                } catch (ModelException $e) {
                    ObjectManager::getInstance(MessageManager::class)->addError($e->getMessage());
                    ObjectManager::getInstance(Log::class)->error($e->getMessage());
                }
            }
        }
    }
}