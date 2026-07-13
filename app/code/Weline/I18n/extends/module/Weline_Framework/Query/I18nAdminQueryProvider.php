<?php

declare(strict_types=1);

namespace Weline\I18n\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 后台 I18n 操作的 bin-query 适配层。
 *
 * 页面动作统一通过这个 provider 进入现有控制器逻辑，保留旧 HTTP
 * controller 入口作为无脚本/兼容入口，但浏览器业务请求不再直连它们。
 */
final class I18nAdminQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SessionFactory $sessionFactory,
        private readonly Request $request
    ) {
    }

    public function getProviderName(): string
    {
        return 'i18n_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        if ($operation !== 'action') {
            throw new \InvalidArgumentException((string)__('I18n 后台查询器不支持的操作：%{1}', $operation));
        }

        $this->assertBackendSession();

        $action = trim((string)($params['action'] ?? ''));
        if ($action === '') {
            throw new \InvalidArgumentException((string)__('I18n 后台操作不能为空'));
        }

        $payload = $params['payload'] ?? [];
        if (!is_array($payload)) {
            throw new \InvalidArgumentException((string)__('I18n 后台操作参数必须是对象'));
        }

        [$controllerClass, $method, $requestMethod, $innerAction] = $this->resolveAction($action);
        if ($innerAction !== null) {
            $payload['action'] = $innerAction;
        }

        return $this->invokeController($controllerClass, $method, $payload, $requestMethod);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'i18n_admin',
            'name' => __('I18n 后台管理查询器'),
            'description' => __('通过 bin-query 异步执行国家、区域、词典和翻译管理动作。'),
            'module' => 'Weline_I18n',
            'operations' => [
                [
                    'name' => 'action',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'auth' => 'backend',
                    'params' => [
                        ['name' => 'action', 'type' => 'string', 'required' => true, 'max_length' => 80],
                        ['name' => 'payload', 'type' => 'map', 'required' => false, 'max_items' => 300],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Execute an authenticated I18n backend action',
                ],
            ],
        ];
    }

    private function assertBackendSession(): void
    {
        $session = $this->sessionFactory->createBackendSession();
        // query-bin 握手请求会跳过全局 eager session start；业务 provider
        // 在读取后台登录态前必须显式启动当前请求携带的已有会话。
        $session->start();
        if (!$session->isLoggedIn() || (int)($session->getUserId() ?? 0) <= 0) {
            throw new \RuntimeException((string)__('请先登录后台'));
        }
    }

    /**
     * @return array{0:class-string,1:string,2:string,3:?string}
     */
    private function resolveAction(string $action): array
    {
        $countryActions = [
            'country-install' => ['postInstall', 'POST', null],
            'country-activate' => ['postActive', 'POST', null],
            'country-disable' => ['postDisable', 'POST', null],
            'country-uninstall' => ['postUninstall', 'POST', null],
            'country-batch-install' => ['batchInstall', 'POST', null],
            'country-batch-activate' => ['batchActive', 'POST', null],
            'country-batch-disable' => ['batchDisable', 'POST', null],
            'country-batch-uninstall' => ['batchUninstall', 'POST', null],
            'country-sync' => ['getUpdate', 'GET', null],
        ];
        if (isset($countryActions[$action])) {
            return [
                \Weline\I18n\Controller\Backend\Countries::class,
                ...$countryActions[$action],
            ];
        }

        $localeActions = [
            'locale-install' => ['postInstall', 'POST', null],
            'locale-activate' => ['postActive', 'POST', null],
            'locale-deactivate' => ['postDisable', 'POST', null],
            'locale-uninstall' => ['postUninstall', 'POST', null],
            'locale-sync' => ['getUpdate', 'GET', null],
        ];
        if (isset($localeActions[$action])) {
            return [
                \Weline\I18n\Controller\Backend\Countries\Locales::class,
                ...$localeActions[$action],
            ];
        }

        $localizationActions = [
            'localization-install' => ['postInstall', 'POST', null],
            'localization-activate' => ['postActivate', 'POST', null],
            'localization-deactivate' => ['postDeactivate', 'POST', null],
            'localization-uninstall' => ['postUninstall', 'POST', null],
            'localization-sync' => ['postSyncNames', 'POST', null],
            'localization-cleanup' => ['postCleanupLocales', 'POST', null],
            'localization-batch-install' => ['postBatchAction', 'POST', 'install'],
            'localization-batch-activate' => ['postBatchAction', 'POST', 'activate'],
            'localization-batch-deactivate' => ['postBatchAction', 'POST', 'deactivate'],
            'localization-batch-uninstall' => ['postBatchAction', 'POST', 'uninstall'],
        ];
        if (isset($localizationActions[$action])) {
            return [
                \Weline\I18n\Controller\Backend\Localization::class,
                ...$localizationActions[$action],
            ];
        }

        $wordActions = [
            'word-collect' => ['collect', 'GET', null],
            'word-translate' => ['translate', 'POST', null],
            'word-restore' => ['postRestore', 'POST', null],
            'word-push' => ['push', 'POST', null],
            'word-enable' => ['enable', 'POST', null],
            'word-disable' => ['disable', 'POST', null],
        ];
        if (isset($wordActions[$action])) {
            return [
                \Weline\I18n\Controller\Backend\Countries\Locale\Words::class,
                ...$wordActions[$action],
            ];
        }

        $dictionaryActions = [
            'dictionary-delete' => ['getDelete', 'GET', null],
            'dictionary-import' => ['postImportCsvContent', 'POST', null],
            'dictionary-clear-locale' => ['postClearLocale', 'POST', null],
            'dictionary-clear-all' => ['postClearAll', 'POST', null],
            'dictionary-add' => ['postAdd', 'POST', null],
            'dictionary-edit' => ['postEdit', 'POST', null],
            'dictionary-quick-save' => ['postQuickSave', 'POST', null],
            'dictionary-collect' => ['postCollectWords', 'POST', null],
            'dictionary-auto-register-enable' => ['postEnableAutoRegister', 'POST', null],
            'dictionary-auto-register-disable' => ['postDisableAutoRegister', 'POST', null],
            'dictionary-translation-mode' => ['postSetTranslationMode', 'POST', null],
            'dictionary-check-auto-register' => ['getCheckAutoRegister', 'GET', null],
            'dictionary-current-translation-mode' => ['getCurrentTranslationMode', 'GET', null],
            'dictionary-quick-data' => ['getQuickTranslationData', 'GET', null],
        ];
        if (isset($dictionaryActions[$action])) {
            return [
                \Weline\I18n\Controller\Backend\Dictionary::class,
                ...$dictionaryActions[$action],
            ];
        }

        $aiActions = [
            'ai-save' => ['postSave', 'POST', null],
            'ai-enqueue' => ['postEnqueue', 'POST', null],
            'ai-enqueue-all' => ['postEnqueue', 'POST', null],
            'ai-export-modules' => ['postExportModules', 'POST', null],
        ];
        if (isset($aiActions[$action])) {
            return [
                \Weline\I18n\Controller\Backend\AiTranslation::class,
                ...$aiActions[$action],
            ];
        }

        if ($action === 'taglib-local-save') {
            return [
                \Weline\I18n\Controller\Backend\Taglib\Local::class,
                'post',
                'POST',
                null,
            ];
        }

        throw new \InvalidArgumentException((string)__('不支持的 I18n 后台操作：%{1}', $action));
    }

    private function invokeController(string $controllerClass, string $method, array $payload, string $requestMethod): array
    {
        foreach ($payload as $key => $value) {
            $key = (string)$key;
            $this->request->setPost($key, $value);
            $this->request->setGet($key, $value);
        }
        $this->request->setServer('REQUEST_METHOD', $requestMethod);
        $this->request->setServer('HTTP_ACCEPT', 'application/json');
        $this->request->setServer('HTTP_X_REQUESTED_WITH', 'XMLHttpRequest');

        $actionRequest = $this->createActionRequest($payload, $requestMethod);
        $controller = $this->instantiateControllerWithoutInit($controllerClass, $actionRequest);
        try {
            $response = $controller->{$method}();
        } catch (\Weline\Framework\Http\ResponseTerminateException $termination) {
            // 部分旧 I18n 动作通过 fetchJson() 以 200 ResponseTerminateException
            // 返回 JSON。bin-query 需要吸收这个内部终止信号，而不是把它当成 500。
            if ($termination->getStatusCode() < 200 || $termination->getStatusCode() >= 300) {
                throw $termination;
            }

            $response = $termination->getBody();
        }
        if (is_array($response)) {
            return $this->normalizeResponse($response);
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                return $this->normalizeResponse($decoded);
            }
        }

        return [
            'success' => true,
            'message' => (string)__('操作成功'),
            'data' => [],
        ];
    }

    /**
     * 后台 Controller 的 __init() 是页面路由生命周期的一部分，会校验
     * /jR.../backend/... 页面地址并准备模板。bin-query 只需要动作依赖，
     * 不能触发这套页面初始化，否则会把 query-bin 地址误判为 404。
     */
    private function instantiateControllerWithoutInit(string $controllerClass, Request $actionRequest): object
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

                throw new \RuntimeException((string)__('无法构造 I18n 后台操作依赖：%{1}', $parameter->getName()));
            }
        }

        $controller = $reflection->newInstanceArgs($arguments);
        $requestProperty = new \ReflectionProperty(\Weline\Framework\Controller\Core::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $actionRequest);

        return $controller;
    }

    private function createActionRequest(array $payload, string $requestMethod): Request
    {
        $request = new Request();
        $request->setServer('REQUEST_METHOD', $requestMethod);
        $request->setServer('HTTP_ACCEPT', 'application/json');
        $request->setServer('HTTP_X_REQUESTED_WITH', 'XMLHttpRequest');
        $request->setServer('CONTENT_TYPE', 'application/x-www-form-urlencoded');
        $request->setServer('WELINE_AREA', 'backend');
        $request->setServer('WELINE_IS_BACKEND', '1');

        foreach ($payload as $key => $value) {
            $key = (string)$key;
            $request->setPost($key, $value);
            $request->setGet($key, $value);
        }

        $request->setResponse($this->request->getResponse());

        return $request;
    }

    private function normalizeResponse(array $response): array
    {
        if (array_key_exists('success', $response)) {
            return $response;
        }

        $code = (int)($response['code'] ?? 200);
        $data = $response['data'] ?? [];
        $normalized = [
            'success' => $code < 400,
            'message' => (string)($response['message'] ?? $response['msg'] ?? ''),
            'data' => $data,
        ];

        if (is_array($data)) {
            $normalized = array_merge($normalized, $data);
        }

        return $normalized;
    }
}
