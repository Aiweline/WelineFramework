<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;

/**
 * Invoke backend controllers from QueryProvider admin bridges without running
 * BackendController::__init() (area-key / template page lifecycle).
 *
 * Also absorbs ResponseTerminateException with 2xx so fetchJson()-style actions
 * return their body instead of bubbling as Internal server error.
 */
final class AdminControllerBridge
{
    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $bodyParams
     * @param list<string> $methodCandidates
     */
    public static function invoke(
        string $controllerClass,
        array $methodCandidates,
        array $queryParams,
        array $bodyParams,
        string $httpMethod,
        string $rawBody = '',
    ): mixed {
        $actionRequest = self::createActionRequest($queryParams, $bodyParams, $httpMethod, $rawBody);
        $controller = self::instantiateWithoutInit($controllerClass, $actionRequest);

        foreach (array_unique($methodCandidates) as $candidate) {
            if (!method_exists($controller, $candidate)) {
                continue;
            }
            try {
                $response = $controller->{$candidate}();
            } catch (ResponseTerminateException $termination) {
                $status = $termination->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw $termination;
                }
                $response = $termination->getBody();
            }

            if (is_string($response)) {
                $decoded = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            return $response;
        }

        return [
            'success' => false,
            'message' => 'Action missing on ' . $controllerClass . ': ' . implode('|', $methodCandidates),
        ];
    }

    private static function backendSyntheticUri(): string
    {
        $prefix = \trim((string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
        if ($prefix === '') {
            return '/backend/bridge';
        }

        return '/' . $prefix . '/backend/bridge';
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $bodyParams
     */
    private static function createActionRequest(
        array $queryParams,
        array $bodyParams,
        string $httpMethod,
        string $rawBody,
    ): Request {
        $request = new Request();
        $method = strtoupper($httpMethod) ?: 'POST';
        $uri = self::backendSyntheticUri();
        $server = [
            'REQUEST_METHOD' => $method,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'WELINE_AREA' => 'backend',
            'WELINE_IS_BACKEND' => '1',
            'REQUEST_URI' => $uri,
            'WELINE_ORIGIN_REQUEST_URI' => $uri,
            'REQUEST_SCHEME' => 'http',
        ];
        if (\class_exists(\Weline\Framework\Runtime\RequestContext::class, false)) {
            $requestId = \Weline\Framework\Runtime\RequestContext::getRequestId();
            if ($requestId === null || $requestId === '') {
                $requestId = 'admin-bridge-' . spl_object_id($request);
                try {
                    \Weline\Framework\Runtime\RequestContext::setId($requestId);
                } catch (\Throwable) {
                }
            }
        } else {
            $requestId = 'admin-bridge-' . spl_object_id($request);
        }
        $server['__SERVERBAG_REQUEST_ID__'] = $requestId;
        $serverBag = new \Weline\Framework\Http\Request\ServerBag();
        $serverBag->initFromArray($server);
        $serverProperty = new \ReflectionProperty(\Weline\Framework\Http\Request\RequestAbstract::class, 'serverBag');
        $serverProperty->setAccessible(true);
        $serverProperty->setValue($request, $serverBag);

        foreach ($queryParams as $key => $value) {
            $request->setGet((string)$key, $value);
        }
        foreach ($bodyParams as $key => $value) {
            $request->setPost((string)$key, $value);
        }
        $merged = array_merge($queryParams, $bodyParams);
        $request->setData('params', $merged);
        try {
            $request->getParameterBag()->setBody($bodyParams);
            $request->getParameterBag()->setRawBody($rawBody);
        } catch (\Throwable) {
        }

        try {
            $shared = ObjectManager::getInstance(Request::class);
            $request->setResponse($shared->getResponse());
        } catch (\Throwable) {
        }

        return $request;
    }

    private static function instantiateWithoutInit(string $controllerClass, Request $actionRequest): object
    {
        $reflection = new \ReflectionClass($controllerClass);
        $constructor = $reflection->getConstructor();
        $arguments = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $arguments[] = ObjectManager::getInstance($type->getName());
                    continue;
                }
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new \RuntimeException('Cannot construct admin bridge dependency: ' . $parameter->getName());
            }
        }

        $controller = $reflection->newInstanceArgs($arguments);
        $requestProperty = new \ReflectionProperty(\Weline\Framework\Controller\Core::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $actionRequest);

        if ($controller instanceof \Weline\Framework\App\Controller\BackendController) {
            $sessionProperty = new \ReflectionProperty(
                \Weline\Framework\App\Controller\BackendController::class,
                'session'
            );
            $sessionProperty->setAccessible(true);
            $session = \Weline\Framework\Session\SessionFactory::getInstance()->createBackendSession();
            try {
                $session->start(null);
            } catch (\Throwable) {
            }
            $sessionProperty->setValue($controller, $session);
        }

        return $controller;
    }
}
