<?php
declare(strict_types=1);

namespace Weline\BackendActivity\Observer;

use Weline\Acl\Api\Authorization\AuthorizationServiceInterface;
use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\BackendActivity\Service\BusinessContextService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Log;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;

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

        try {
            $responseCode = http_response_code();
            $responseCode = ($responseCode === false) ? 200 : (int)$responseCode;
            $responseTime = microtime(true) - START_TIME;

            $deferredPayload = RequestContext::get('backend_activity.deferred_payload');
            if (\is_array($deferredPayload)) {
                $this->queueDeferredActivityLogCreate($deferredPayload, $responseCode, $responseTime);
                return;
            }

            $requestId = $this->request->getId();
            /** @var BackendActivityLog $backendActivityLogger */
            $backendActivityLogger = ObjectManager::getInstance(BackendActivityLog::class);
            $backendActivityLog = RequestContext::get('backend_activity.log_model');
            if (!$backendActivityLog instanceof BackendActivityLog || !$backendActivityLog->getId()) {
                $backendActivityLog = $backendActivityLogger->load(BackendActivityLog::schema_fields_request_id, $requestId);
            }
            if (!$backendActivityLog->getId()) {
                return;
            }

            $this->queueResponseMetricsUpdate($backendActivityLog, $responseCode, $responseTime);
        } catch (\Throwable $e) {
            ObjectManager::getInstance(MessageManager::class)->addError($e->getMessage());
            ObjectManager::getInstance(Log::class)->error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function queueDeferredActivityLogCreate(array $payload, int $responseCode, float $responseTime): void
    {
        $requestId = (string)($payload['request_id'] ?? '');
        if ($requestId === '') {
            return;
        }

        PostResponseTaskQueue::enqueue(
            'backend-activity-create:' . $requestId,
            static function () use ($payload, $responseCode, $responseTime): void {
                self::createDeferredActivityLog($payload, $responseCode, $responseTime);
            }
        );
    }

    private function queueResponseMetricsUpdate(BackendActivityLog $backendActivityLog, int $responseCode, float $responseTime): void
    {
        $id = (int)$backendActivityLog->getId();
        if ($id <= 0) {
            return;
        }

        $backendActivityLog->setResponseCode($responseCode);
        $backendActivityLog->setResponseTime($responseTime);

        if (\class_exists(PostResponseTaskQueue::class) && \class_exists(Runtime::class) && Runtime::isPersistent()) {
            PostResponseTaskQueue::enqueue(
                'backend-activity-response:' . $id,
                static function () use ($id, $responseCode, $responseTime): void {
                    self::updateResponseMetricsById($id, $responseCode, $responseTime);
                }
            );
            return;
        }

        self::updateResponseMetricsById($id, $responseCode, $responseTime);
    }

    private static function updateResponseMetricsById(int $id, int $responseCode, float $responseTime): void
    {
        if ($id <= 0) {
            return;
        }

        /** @var BackendActivityLog $backendActivityLog */
        $backendActivityLog = ObjectManager::getInstance(BackendActivityLog::class);
        $backendActivityLog->getQuery(false)
            ->where(BackendActivityLog::schema_fields_ID, $id)
            ->update([
                BackendActivityLog::schema_fields_response_code => $responseCode,
                BackendActivityLog::schema_fields_response_time => $responseTime,
            ], BackendActivityLog::schema_fields_ID)
            ->fetch();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function createDeferredActivityLog(array $payload, int $responseCode, float $responseTime): void
    {
        /** @var AuthorizationServiceInterface $authorizationService */
        $authorizationService = ObjectManager::getInstance(AuthorizationServiceInterface::class);
        $resource = $authorizationService->findRouteResource(
            (string)($payload['class_name'] ?? ''),
            (string)($payload['method'] ?? ''),
            (string)($payload['path'] ?? ''),
        );
        $name = $resource?->getSourceName();
        if (empty($name)) {
            $name = __('Unnamed Access');
        }

        /** @var BackendActivityLog $activityLogger */
        $activityLogger = ObjectManager::getInstance(BackendActivityLog::class);
        $activityLogger->setName($name)
            ->setUserId((int)($payload['user_id'] ?? 0))
            ->setAclId($resource?->getAclId() ?? 0)
            ->setPath((string)($payload['path'] ?? ''))
            ->setModule((string)($payload['module'] ?? ''))
            ->setHost((string)($payload['host'] ?? ''))
            ->setUrl((string)($payload['url'] ?? ''))
            ->setRequestMethod((string)($payload['method'] ?? ''))
            ->setRequestParams(\is_array($payload['params'] ?? null) ? $payload['params'] : [])
            ->setRequestData(\is_array($payload['data'] ?? null) ? $payload['data'] : [])
            ->setIp((string)($payload['ip'] ?? ''))
            ->setUserAgent((string)($payload['user_agent'] ?? ''))
            ->setRequestId((string)($payload['request_id'] ?? ''))
            ->setResponseCode($responseCode)
            ->setResponseTime($responseTime);

        $businessContext = $payload['business_context'] ?? null;
        if (is_array($businessContext)) {
            BusinessContextService::applyToLogModel($activityLogger, $businessContext);
        }

        $activityLogger->save();
    }
}
