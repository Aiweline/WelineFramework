<?php
declare(strict_types=1);

namespace Weline\BackendActivity\Observer;

use Weline\Acl\Model\Acl;
use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\BackendActivity\Service\BusinessContextService;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Log;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

class BackendControllerInit implements ObserverInterface
{
    private Request $request;
    private AuthenticatedSessionInterface $backendSession;
    private Acl $acl;

    public function __construct(Request $request, Acl $acl)
    {
        $this->request = $request;
        $this->backendSession = SessionFactory::getInstance()->createBackendSession();
        $this->acl = $acl;
    }

    public function execute(Event &$event): void
    {
        if ($this->shouldSkipActivityLog()) {
            RequestContext::set('backend_activity.skip_log', true);
            RequestContext::remove('backend_activity.log_model');
            RequestContext::remove('backend_activity.deferred_payload');
            RequestContext::remove(BusinessContextService::CONTEXT_KEY);
            return;
        }

        RequestContext::remove('backend_activity.skip_log');
        RequestContext::remove('backend_activity.log_model');
        RequestContext::remove('backend_activity.deferred_payload');
        RequestContext::remove(BusinessContextService::CONTEXT_KEY);

        if ($this->shouldDeferReadActivityLog()) {
            RequestContext::set('backend_activity.deferred_payload', $this->buildDeferredActivityPayload());
            return;
        }

        $acl = $this->acl->where(Acl::schema_fields_CLASS, $this->request->getRouterData('class/name'))
            ->where(Acl::schema_fields_METHOD, $this->request->getMethod())
            ->where(Acl::schema_fields_ROUTE, $this->request->getRouteUrlPath())
            ->find()
            ->fetch();

        if (!$acl->getId()) {
            $name = __('Unnamed Access');
        } else {
            $name = $acl->getSourceName();
            if (empty($name)) {
                $name = __('Unnamed Access');
            }
        }

        /** @var BackendActivityLog $activityLogger */
        $activityLogger = ObjectManager::getInstance(BackendActivityLog::class);
        try {
            $activityLogger->setName($name)
                ->setUserId($this->backendSession->getUserId() ?? 0)
                ->setAclId((int)$acl->getAclId())
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
            if ($activityLogger->getId()) {
                RequestContext::set('backend_activity.log_model', $activityLogger);
            }
        } catch (\Exception $e) {
            ObjectManager::getInstance(Log::class)
                ->warning('File:' . (str_replace(BP, '', $e->getFile())) . ',Line:' . $e->getLine() . ',' . PHP_EOL . 'Error:' . PHP_EOL . $this->request->getId() . ':' . $e->getCode() . ':' . PHP_EOL . $e->getMessage());
        }
    }

    private function shouldSkipActivityLog(): bool
    {
        if ((\defined('ENV_TEST') && ENV_TEST === true) || \defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__')) {
            return true;
        }

        if ($this->isInternalWarmupRequest()) {
            return true;
        }

        $module = (string)($this->request->getData('router/module') ?? '');
        $requestId = (string)($this->request->getId() ?? '');
        if ($module === '' || $requestId === '') {
            return true;
        }

        $userId = (int)($this->backendSession->getUserId() ?? 0);
        if ($userId > 0) {
            return false;
        }

        if (strtoupper($this->request->getMethod()) !== 'GET') {
            return false;
        }

        return trim((string)$this->request->getRouteUrlPath(), '/') === 'admin/login';
    }

    private function shouldDeferReadActivityLog(): bool
    {
        if (!\class_exists(PostResponseTaskQueue::class) || !\class_exists(Runtime::class) || !Runtime::isPersistent()) {
            return false;
        }

        $rawFlag = Env::get('wls.backend_activity.defer_read_log', '1');
        if (\in_array(\strtolower(\trim((string)$rawFlag)), ['0', 'false', 'no', 'off', 'sync'], true)) {
            return false;
        }

        if (!\in_array(\strtoupper($this->request->getMethod()), ['GET', 'HEAD'], true)) {
            return false;
        }

        if (\method_exists($this->request, 'isAjax') && $this->request->isAjax()) {
            return false;
        }

        if (\method_exists($this->request, 'isIframe') && $this->request->isIframe()) {
            return false;
        }

        return (int)($this->backendSession->getUserId() ?? 0) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDeferredActivityPayload(): array
    {
        return [
            'request_id' => (string)$this->request->getId(),
            'class_name' => (string)$this->request->getRouterData('class/name'),
            'method' => (string)$this->request->getMethod(),
            'path' => (string)$this->request->getRouteUrlPath(),
            'module' => (string)$this->request->getData('router/module'),
            'host' => (string)$this->request->getBaseHost(),
            'url' => (string)$this->request->getServer('REQUEST_URI'),
            'params' => $this->request->getParams(),
            'data' => $this->request->getData(),
            'ip' => (string)$this->request->getUserIpAddress(),
            'user_agent' => (string)$this->request->getServer('HTTP_USER_AGENT'),
            'user_id' => (int)($this->backendSession->getUserId() ?? 0),
        ];
    }

    private function isInternalWarmupRequest(): bool
    {
        foreach ([
            'WLS_INTERNAL_WARMUP',
            'WLS_INTERNAL_DYNAMIC_WARMUP',
            'WLS_INTERNAL_BACKEND_WARMUP',
            'HTTP_WELINE_INTERNAL_WARMUP',
            'HTTP_X_WLS_DYNAMIC_WARMUP',
            'HTTP_X_WLS_INTERNAL_REQUEST',
        ] as $key) {
            if (trim((string)($this->request->getServer($key) ?? '')) !== '') {
                return true;
            }
        }

        foreach (['Weline-Internal-Warmup', 'X-WLS-Dynamic-Warmup', 'X-WLS-Internal-Request'] as $headerName) {
            $value = $this->request->getHeader($headerName);
            if (\is_scalar($value) && trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }
}
