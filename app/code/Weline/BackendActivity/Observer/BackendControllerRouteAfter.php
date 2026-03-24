<?php
declare(strict_types=1);

namespace Weline\BackendActivity\Observer;

use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\Framework\Database\Exception\ModelException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Log;
use Weline\Framework\Runtime\RequestContext;

class BackendControllerRouteAfter implements ObserverInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function execute(Event &$event): void
    {
        if (!$this->request->isBackend() || RequestContext::get('backend_activity.skip_log', false)) {
            return;
        }

        $requestId = $this->request->getId();
        /** @var BackendActivityLog $backendActivityLogger */
        $backendActivityLogger = ObjectManager::getInstance(BackendActivityLog::class);
        $backendActivityLog = $backendActivityLogger->load(BackendActivityLog::schema_fields_request_id, $requestId);
        if (!$backendActivityLog->getId()) {
            return;
        }

        try {
            $responseCode = http_response_code();
            $responseCode = ($responseCode === false) ? 200 : (int)$responseCode;

            $backendActivityLog
                ->setResponseCode($responseCode)
                ->setResponseTime(microtime(true) - START_TIME)
                ->save();
        } catch (ModelException $e) {
            ObjectManager::getInstance(MessageManager::class)->addError($e->getMessage());
            ObjectManager::getInstance(Log::class)->error($e->getMessage());
        }
    }
}
