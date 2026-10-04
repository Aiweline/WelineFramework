<?php
declare(strict_types=1);
namespace Weline\Frontend\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Frontend\Service\StaticErrorRuntimeTransport as Transport;

final class StaticErrorRuntimeTransport implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $response = $event->getData('response');
        if (!$response instanceof Response || $response->getStatusCode() !== 404 || RequestContext::isBackendArea()) {
            return;
        }
        $body = $response->getBody();
        if (!str_contains($body, 'weline-frontend-runtime-config')) {
            return;
        }
        $request = ObjectManager::getInstance(Request::class);
        $builder = $request->getUrlBuilder();
        $response->setBody(Transport::rebind(
            $body,
            rtrim($request->getBaseHost(), '/'),
            $request->getPrePath(),
            rtrim($builder->getFrontendApiUrl('/', [], false), '/'),
            $builder->getFrontendApiUrl('framework/query-bin', [], false),
        ));
    }
}
