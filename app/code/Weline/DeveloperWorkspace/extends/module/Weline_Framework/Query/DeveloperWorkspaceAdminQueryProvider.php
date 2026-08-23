<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\DeveloperWorkspace\Api\Rest\V1\Db;
use Weline\DeveloperWorkspace\Api\Rest\V1\Document as ApiDocument;
use Weline\DeveloperWorkspace\Api\Rest\V1\Routes;
use Weline\DeveloperWorkspace\Api\Rest\V1\Seo\Crawl;
use Weline\DeveloperWorkspace\Api\Rest\V1\Trace;
use Weline\DeveloperWorkspace\Controller\Admin\Document as AdminDocumentController;
use Weline\DeveloperWorkspace\Controller\Admin\Document\Catalog as AdminCatalogController;
use Weline\DeveloperWorkspace\Observer\DevToolPanelObserver;
use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\Http\Request;

class DeveloperWorkspaceAdminQueryProvider implements QueryProviderInterface
{
    /** @var array<string,array{method:string,class:class-string,action:string}> */
    private const PANEL_ROUTES = [
        'document/modules' => ['method' => 'GET', 'class' => ApiDocument::class, 'action' => 'getModules'],
        'document/search' => ['method' => 'GET', 'class' => ApiDocument::class, 'action' => 'getSearch'],
        'document/detail' => ['method' => 'GET', 'class' => ApiDocument::class, 'action' => 'getDetail'],
        'document/catalogs' => ['method' => 'GET', 'class' => ApiDocument::class, 'action' => 'getCatalogs'],
        'routes' => ['method' => 'GET', 'class' => Routes::class, 'action' => 'getIndex'],
        'routes/search' => ['method' => 'GET', 'class' => Routes::class, 'action' => 'getSearch'],
        'trace' => ['method' => 'GET', 'class' => Trace::class, 'action' => 'getIndex'],
        'db/explain' => ['method' => 'POST', 'class' => Db::class, 'action' => 'postExplain'],
        'seo/crawl/start' => ['method' => 'POST', 'class' => Crawl::class, 'action' => 'postStart'],
        'seo/crawl/result' => ['method' => 'GET', 'class' => Crawl::class, 'action' => 'getResult'],
    ];

    public function getProviderName(): string
    {
        return 'developer_workspace';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'adminRequest' => $this->adminRequest($params),
            'docsRequest' => $this->docsRequest($params),
            'panelRequest' => $this->panelRequest($params),
            'documentAdminView' => $this->documentAdminView($params),
            'documentAdminDelete' => $this->documentAdminDelete($params),
            'catalogAdminView' => $this->catalogAdminView($params),
            'catalogAdminSave' => $this->catalogAdminSave($params),
            'catalogAdminDelete' => $this->catalogAdminDelete($params),
            'catalogAdminReorder' => $this->catalogAdminReorder($params),
            'documentConfigSave' => $this->documentConfigSave($params),
            'documentTranslationScan' => $this->documentTranslationAction('postTranslationScan'),
            'documentTranslationTest' => $this->documentTranslationAction('postTranslationTest'),
            'documentTranslationRun' => $this->documentTranslationAction('postTranslationRun'),
            'documentTranslationRetry' => $this->documentTranslationAction('postTranslationRetry'),
            default => throw new \InvalidArgumentException('Unsupported operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'developer_workspace',
            'name' => 'Weline_DeveloperWorkspace admin bridge',
            'module' => 'Weline_DeveloperWorkspace',
            'operations' => [
                [
                    'name' => 'adminRequest',
                    'description' => 'Legacy controller bridge',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true],
                        ['name' => 'method', 'type' => 'string', 'required' => false],
                        ['name' => 'headers', 'type' => 'array', 'required' => false],
                        ['name' => 'body', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'docsRequest',
                    'description' => 'Public read-only documentation endpoint bridge',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true],
                        ['name' => 'method', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'panelRequest',
                    'description' => 'DEV/token-authorized developer panel endpoint bridge',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true],
                        ['name' => 'method', 'type' => 'string', 'required' => false],
                        ['name' => 'headers', 'type' => 'array', 'required' => false],
                        ['name' => 'body', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'documentAdminView',
                    'description' => 'Read a backend developer document detail',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'id', 'type' => 'int', 'required' => true],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'documentAdminDelete',
                    'description' => 'Delete a backend developer document',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'catalogAdminView',
                    'description' => 'Read a backend developer document catalog',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'catalogAdminSave',
                    'description' => 'Create or update a backend developer document catalog',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'id', 'type' => 'int', 'required' => false],
                        ['name' => 'pid', 'type' => 'int', 'required' => false],
                        ['name' => 'name', 'type' => 'string', 'required' => true],
                        ['name' => 'description', 'type' => 'string', 'required' => false],
                        ['name' => 'is_active', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'catalogAdminDelete',
                    'description' => 'Delete a backend developer document catalog',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'catalogAdminReorder',
                    'description' => 'Move a backend developer document catalog',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'id', 'type' => 'int', 'required' => true],
                        ['name' => 'pid', 'type' => 'int', 'required' => true],
                        ['name' => 'level', 'type' => 'int', 'required' => true],
                        ['name' => 'position', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'documentConfigSave',
                    'description' => 'Save developer document translation configuration',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'enabled', 'type' => 'int', 'required' => false],
                        ['name' => 'target_locales', 'type' => 'array', 'required' => false],
                        ['name' => 'ai_enabled', 'type' => 'array', 'required' => false],
                        ['name' => 'scope_documents', 'type' => 'int', 'required' => false],
                        ['name' => 'scope_api_documents', 'type' => 'int', 'required' => false],
                        ['name' => 'scope_catalogs', 'type' => 'int', 'required' => false],
                        ['name' => 'model_code', 'type' => 'string', 'required' => false],
                        ['name' => 'batch_size', 'type' => 'int', 'required' => false],
                        ['name' => 'max_retries', 'type' => 'int', 'required' => false],
                        ['name' => 'fallback_policy', 'type' => 'string', 'required' => false],
                        ['name' => 'daily_token_limit', 'type' => 'int', 'required' => false],
                        ['name' => 'monthly_token_limit', 'type' => 'int', 'required' => false],
                        ['name' => 'max_document_tokens', 'type' => 'int', 'required' => false],
                        ['name' => 'show_translation_status', 'type' => 'int', 'required' => false],
                    ],
                ],
                ...$this->translationActionDescriptors(),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function translationActionDescriptors(): array
    {
        $descriptions = [
            'documentTranslationScan' => 'Scan the developer document translation adapter',
            'documentTranslationTest' => 'Test the developer document translation adapter',
            'documentTranslationRun' => 'Enqueue and process developer document translations',
            'documentTranslationRetry' => 'Reset failed developer document translation tasks',
        ];
        $operations = [];
        foreach ($descriptions as $name => $description) {
            $operations[] = [
                'name' => $name,
                'description' => $description,
                'frontend' => true,
                'auth' => 'backend',
                'backend' => true,
                'backend_acl' => ['kind' => 'self'],
                'mode' => 'write',
                'params' => [],
            ];
        }
        return $operations;
    }

    /** @param array<string,mixed> $params */
    private function documentAdminView(array $params): mixed
    {
        return $this->invokeAdminController(
            AdminDocumentController::class,
            'getView',
            'GET',
            [
                'id' => max(0, (int)($params['id'] ?? 0)),
                'locale' => trim((string)($params['locale'] ?? '')),
            ]
        );
    }

    /** @param array<string,mixed> $params */
    private function documentAdminDelete(array $params): mixed
    {
        return $this->invokeAdminController(
            AdminDocumentController::class,
            'postDelete',
            'POST',
            ['id' => max(0, (int)($params['id'] ?? 0))]
        );
    }

    /** @param array<string,mixed> $params */
    private function catalogAdminView(array $params): mixed
    {
        return $this->invokeAdminController(
            AdminCatalogController::class,
            'getView',
            'GET',
            ['id' => max(0, (int)($params['id'] ?? 0))]
        );
    }

    /** @param array<string,mixed> $params */
    private function catalogAdminSave(array $params): mixed
    {
        return $this->invokeAdminController(
            AdminCatalogController::class,
            'postPost',
            'POST',
            [
                'id' => max(0, (int)($params['id'] ?? 0)),
                'pid' => max(0, (int)($params['pid'] ?? 0)),
                'name' => trim((string)($params['name'] ?? '')),
                'description' => (string)($params['description'] ?? ''),
                'is_active' => !empty($params['is_active']) ? 1 : 0,
            ]
        );
    }

    /** @param array<string,mixed> $params */
    private function catalogAdminDelete(array $params): mixed
    {
        return $this->invokeAdminController(
            AdminCatalogController::class,
            'postDelete',
            'POST',
            ['id' => max(0, (int)($params['id'] ?? 0))]
        );
    }

    /** @param array<string,mixed> $params */
    private function catalogAdminReorder(array $params): mixed
    {
        return $this->invokeAdminController(
            AdminCatalogController::class,
            'postUpdateOrder',
            'POST',
            [
                'id' => max(0, (int)($params['id'] ?? 0)),
                'pid' => max(0, (int)($params['pid'] ?? 0)),
                'level' => max(1, (int)($params['level'] ?? 1)),
                'position' => max(1, (int)($params['position'] ?? 1)),
            ]
        );
    }

    /** @param array<string,mixed> $params */
    private function documentConfigSave(array $params): mixed
    {
        $targetLocales = array_values(array_filter(array_map(
            static fn(mixed $locale): string => trim((string)$locale),
            is_array($params['target_locales'] ?? null) ? $params['target_locales'] : []
        ), static fn(string $locale): bool => $locale !== ''));
        $aiEnabled = [];
        foreach (is_array($params['ai_enabled'] ?? null) ? $params['ai_enabled'] : [] as $locale => $enabled) {
            $locale = trim((string)$locale);
            if ($locale !== '') {
                $aiEnabled[$locale] = !empty($enabled) ? 1 : 0;
            }
        }
        return $this->invokeAdminController(
            AdminDocumentController::class,
            'postConfig',
            'POST',
            [
                'enabled' => !empty($params['enabled']) ? 1 : 0,
                'target_locales' => $targetLocales,
                'ai_enabled' => $aiEnabled,
                'scope_documents' => !empty($params['scope_documents']) ? 1 : 0,
                'scope_api_documents' => !empty($params['scope_api_documents']) ? 1 : 0,
                'scope_catalogs' => !empty($params['scope_catalogs']) ? 1 : 0,
                'model_code' => trim((string)($params['model_code'] ?? '')),
                'batch_size' => max(1, min(200, (int)($params['batch_size'] ?? 10))),
                'max_retries' => max(0, min(20, (int)($params['max_retries'] ?? 3))),
                'fallback_policy' => in_array(($params['fallback_policy'] ?? 'source'), ['source', 'empty'], true)
                    ? (string)$params['fallback_policy']
                    : 'source',
                'daily_token_limit' => max(0, (int)($params['daily_token_limit'] ?? 0)),
                'monthly_token_limit' => max(0, (int)($params['monthly_token_limit'] ?? 0)),
                'max_document_tokens' => max(1000, (int)($params['max_document_tokens'] ?? 12000)),
                'show_translation_status' => !empty($params['show_translation_status']) ? 1 : 0,
            ]
        );
    }

    private function documentTranslationAction(string $action): mixed
    {
        return $this->invokeAdminController(AdminDocumentController::class, $action, 'POST', []);
    }

    /**
     * @param class-string $controller
     * @param array<string,mixed> $params
     */
    private function invokeAdminController(string $controller, string $action, string $method, array $params): mixed
    {
        $queryParams = $method === 'GET' ? $params : [];
        $bodyParams = $method === 'POST' ? $params : [];

        return \Weline\Framework\Service\Query\AdminControllerBridge::invoke(
            $controller,
            [$action],
            $queryParams,
            $bodyParams,
            $method,
            $bodyParams === [] ? '' : http_build_query($bodyParams)
        );
    }

    /** @param array<string,mixed> $params */
    private function panelRequest(array $params): array
    {
        $url = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'GET'))) ?: 'GET';
        if ($url === '') {
            return $this->panelResponse(400, ['success' => false, 'message' => 'Missing URL']);
        }

        $parts = parse_url($url);
        $path = '/' . trim((string)($parts['path'] ?? ''), '/');
        $marker = '/dev/tool/rest/v1/';
        $position = stripos($path . '/', $marker);
        if ($position === false) {
            return $this->panelResponse(400, ['success' => false, 'message' => 'Unsupported panel path: ' . $path]);
        }
        $route = strtolower(trim(substr($path, $position + strlen($marker)), '/'));

        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
        }
        $rawBody = array_key_exists('body', $params) && $params['body'] !== null
            ? (string)$params['body']
            : '';
        $bodyParams = $this->decodePanelBody($rawBody);
        $access = new PanelAccessService();

        if ($route === 'panel/session') {
            if ($method !== 'POST') {
                return $this->panelResponse(405, ['success' => false, 'message' => 'Unsupported panel method']);
            }
            $token = trim((string)($bodyParams['token'] ?? ''));
            if (!$access->authenticateToken($token)) {
                return $this->panelResponse(403, ['success' => false, 'message' => 'Weline panel token is invalid']);
            }
            $request = ObjectManager::getInstance(Request::class);
            $access->issueSession($request->getResponse());

            return $this->panelResponse(200, [
                'success' => true,
                'message' => 'Weline panel is authorized',
                'ttl' => $access->sessionTtl(),
            ]);
        }

        $request = ObjectManager::getInstance(Request::class);
        if (!$access->canAccessApi($request)) {
            return $this->panelResponse(403, ['success' => false, 'message' => 'Weline panel is not allowed']);
        }

        if ($route === 'panel') {
            if ($method !== 'GET') {
                return $this->panelResponse(405, ['success' => false, 'message' => 'Unsupported panel method']);
            }

            return $this->panelResponse(
                200,
                (new DevToolPanelObserver($request))->renderPanel(),
                'text/html; charset=utf-8'
            );
        }

        $definition = self::PANEL_ROUTES[$route] ?? null;
        if ($definition === null) {
            return $this->panelResponse(400, ['success' => false, 'message' => 'Unsupported panel path: ' . $path]);
        }
        if ($method !== $definition['method']) {
            return $this->panelResponse(405, ['success' => false, 'message' => 'Unsupported panel method']);
        }

        $result = \Weline\Framework\Service\Query\AdminControllerBridge::invoke(
            $definition['class'],
            [$definition['action']],
            $queryParams,
            $bodyParams,
            $method,
            $rawBody
        );
        $status = 200;
        if (is_array($result)) {
            $status = (int)($result['code'] ?? 200);
        }

        return $this->panelResponse($status, $result);
    }

    /** @return array<string,mixed> */
    private function decodePanelBody(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        parse_str($rawBody, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    private function panelResponse(int $status, mixed $body, string $contentType = 'application/json; charset=utf-8'): array
    {
        if ($contentType === 'application/json; charset=utf-8') {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $body = $encoded === false ? '{}' : $encoded;
        } else {
            $body = (string)$body;
        }

        return [
            '__weline_panel_response' => true,
            'success' => $status >= 200 && $status < 400,
            'status' => $status,
            'content_type' => $contentType,
            'body' => $body,
        ];
    }

    /** @param array<string,mixed> $params */
    private function docsRequest(array $params): mixed
    {
        $url = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'GET'))) ?: 'GET';
        if ($url === '') {
            return ['success' => false, 'message' => 'Missing URL'];
        }
        if ($method !== 'GET') {
            return ['success' => false, 'message' => 'Documentation bridge only accepts GET'];
        }

        $parts = parse_url($url);
        $path = '/' . trim((string)($parts['path'] ?? ''), '/');
        if (!preg_match('#/(?:dev/tool/)?docs/(tree|documents|document|search)$#i', $path, $match)) {
            return ['success' => false, 'message' => 'Unsupported documentation path: ' . $path];
        }

        $action = strtolower((string)$match[1]);
        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
        }

        return \Weline\Framework\Service\Query\AdminControllerBridge::invoke(
            \Weline\DeveloperWorkspace\Controller\Docs::class,
            [$action],
            $queryParams,
            [],
            'GET'
        );
    }

    /** @param array<string,mixed> $params */
    private function adminRequest(array $params): mixed
    {
        $url = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'POST'))) ?: 'POST';
        $headers = is_array($params['headers'] ?? null) ? $params['headers'] : [];
        $body = array_key_exists('body', $params) && $params['body'] !== null ? (string)$params['body'] : '';
        if ($url === '') {
            return ['success' => false, 'message' => 'Missing URL'];
        }
        $parts = parse_url($url);
        $path = (string)($parts['path'] ?? '');
        $pathLower = strtolower($path);
        $markers = ['/developerworkspace/', '/developer-workspace/'];
        $normalized = $path;
        foreach ($markers as $marker) {
            $pos = strpos($pathLower, $marker);
            if ($pos !== false) {
                $normalized = substr($path, $pos);
                break;
            }
        }
        $area = 'Backend';
        $controllerSeg = 'Index';
        $actionSeg = 'index';
        if (preg_match('#^/[a-z0-9_-]+/(backend|admin|frontend)/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?$#i', $normalized, $mm)) {
            $area = ucfirst(strtolower($mm[1]));
            $controllerSeg = $mm[2];
            $actionSeg = $mm[3] ?? 'index';
        } elseif (preg_match('#^/[a-z0-9_-]+/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?$#i', $normalized, $mm)) {
            $controllerSeg = $mm[1];
            $actionSeg = $mm[2] ?? 'index';
        } else {
            return ['success' => false, 'message' => 'Unsupported admin path: ' . $normalized];
        }
        $controllerSeg = str_replace(['-', '_'], '', ucwords(str_replace(['-', '_'], ' ', $controllerSeg)));
        $actionSeg = str_replace('-', '', $actionSeg);
        $ns = 'Weline\DeveloperWorkspace\Controller';
        $class = $ns . '\\' . $area . '\\' . $controllerSeg;
        if (!class_exists($class)) {
            $classAlt = $ns . '\\' . $controllerSeg;
            if (class_exists($classAlt)) {
                $class = $classAlt;
            } else {
                return ['success' => false, 'message' => 'Controller missing: ' . $class];
            }
        }
        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
        }
        $bodyParams = [];
        if ($body !== '') {
            $ct = '';
            foreach ($headers as $name => $value) {
                if (strtolower((string)$name) === 'content-type') { $ct = strtolower((string)$value); break; }
            }
            if (str_contains($ct, 'application/json') || str_starts_with(ltrim($body), '{')) {
                $decoded = json_decode($body, true);
                $bodyParams = is_array($decoded) ? $decoded : [];
            } else {
                parse_str($body, $bodyParams);
                if (!is_array($bodyParams)) { $bodyParams = []; }
            }
        }
        $candidates = [$actionSeg, 'get' . ucfirst($actionSeg), 'post' . ucfirst($actionSeg)];
        if ($method === 'GET') {
            array_unshift($candidates, 'get' . ucfirst($actionSeg));
        } else {
            array_unshift($candidates, 'post' . ucfirst($actionSeg));
        }
        return \Weline\Framework\Service\Query\AdminControllerBridge::invoke(
            $class,
            $candidates,
            $queryParams,
            $bodyParams,
            $method,
            $body
        );
    }
}
