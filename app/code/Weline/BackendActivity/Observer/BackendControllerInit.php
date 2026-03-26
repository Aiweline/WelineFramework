<?php
declare(strict_types=1);

namespace Weline\BackendActivity\Observer;

use Weline\Acl\Model\Acl;
use Weline\BackendActivity\Model\BackendActivityLog;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Log;
use Weline\Framework\Runtime\RequestContext;
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
            return;
        }

        RequestContext::remove('backend_activity.skip_log');

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
}
