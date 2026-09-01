<?php

declare(strict_types=1);

namespace Weline\I18n\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Request;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\AdminControllerBridge;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\I18n\Service\I18nResourceChangePublisher;
use Weline\I18n\Service\TaglibLocalFormService;

/**
 * 后台 I18n 操作的 bin-query 适配层。
 *
 * 页面动作统一通过这个 provider 进入现有控制器逻辑，保留旧 HTTP
 * controller 入口作为无脚本/兼容入口，但浏览器业务请求不再直连它们。
 */
final class I18nAdminQueryProvider implements QueryProviderInterface
{
    private const ACTION_ACL_SOURCES = [
        'country-install' => 'Weline_I18n::i18n_countries',
        'country-activate' => 'Weline_I18n::i18n_countries',
        'country-disable' => 'Weline_I18n::i18n_countries',
        'country-uninstall' => 'Weline_I18n::i18n_countries',
        'country-batch-install' => 'Weline_I18n::i18n_countries',
        'country-batch-activate' => 'Weline_I18n::i18n_countries',
        'country-batch-disable' => 'Weline_I18n::i18n_countries',
        'country-batch-uninstall' => 'Weline_I18n::i18n_countries',
        'country-sync' => 'Weline_I18n::i18n_countries',
        'locale-install' => 'Weline_I18n::i18n_countries',
        'locale-activate' => 'Weline_I18n::i18n_countries',
        'locale-deactivate' => 'Weline_I18n::i18n_countries',
        'locale-uninstall' => 'Weline_I18n::i18n_countries',
        'locale-sync' => 'Weline_I18n::i18n_countries',
        'localization-install' => 'Weline_I18n::i18n_localization',
        'localization-activate' => 'Weline_I18n::i18n_localization',
        'localization-deactivate' => 'Weline_I18n::i18n_localization',
        'localization-uninstall' => 'Weline_I18n::i18n_localization',
        'localization-sync' => 'Weline_I18n::i18n_localization',
        'localization-cleanup' => 'Weline_I18n::i18n_localization',
        'localization-batch-install' => 'Weline_I18n::i18n_localization',
        'localization-batch-activate' => 'Weline_I18n::i18n_localization',
        'localization-batch-deactivate' => 'Weline_I18n::i18n_localization',
        'localization-batch-uninstall' => 'Weline_I18n::i18n_localization',
        'word-collect' => 'Weline_I18n::i18n_dictionaries',
        'word-translate' => 'Weline_I18n::i18n_dictionaries',
        'word-restore' => 'Weline_I18n::i18n_dictionaries',
        'word-push' => 'Weline_I18n::i18n_dictionaries',
        'word-enable' => 'Weline_I18n::i18n_dictionaries',
        'word-disable' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-delete' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-import' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-clear-locale' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-clear-all' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-add' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-edit' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-quick-save' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-collect' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-auto-register-enable' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-auto-register-disable' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-translation-mode' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-check-auto-register' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-current-translation-mode' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-quick-data' => 'Weline_I18n::i18n_dictionaries',
        'dictionary-batch-translate' => 'Weline_I18n::i18n_dictionaries',
        'ai-save' => 'Weline_I18n::i18n_ai_translation',
        'ai-enqueue' => 'Weline_I18n::i18n_ai_translation',
        'ai-enqueue-all' => 'Weline_I18n::i18n_ai_translation',
        'ai-export-modules' => 'Weline_I18n::i18n_ai_translation',
        'ai-module-matrix' => 'Weline_I18n::i18n_ai_translation',
        'ai-module-translate-writeback' => 'Weline_I18n::i18n_ai_translation',
        'taglib-local-load' => 'Weline_I18n::i18n_dictionaries',
        'taglib-local-save' => 'Weline_I18n::i18n_dictionaries',
        'taglib-local-ai' => 'Weline_I18n::i18n_dictionaries',
        'taglib-local-ai-bulk' => 'Weline_I18n::i18n_dictionaries',
    ];

    public function __construct(
        private readonly Request $request,
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly I18nResourceChangePublisher $resourceChanges,
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

        // Backend auth is enforced by FrontendQueryGateway (attestation + ACL)
        // before this provider runs; do not re-read session here — worker
        // query-bin requests may not have eager session state at this layer.

        $action = trim((string)($params['action'] ?? ''));
        if ($action === '') {
            throw new \InvalidArgumentException((string)__('I18n 后台操作不能为空'));
        }

        $payload = $params['payload'] ?? [];
        if (!is_array($payload)) {
            throw new \InvalidArgumentException((string)__('I18n 后台操作参数必须是对象'));
        }

        if ($action === 'taglib-local-load') {
            return $this->loadTaglibLocalForm($payload);
        }

        if ($action === 'taglib-local-ai') {
            return $this->aiTranslateTaglibLocalForm($payload);
        }

        if ($action === 'taglib-local-ai-bulk') {
            return $this->aiTranslateTaglibLocalFormBulk($payload);
        }

        [$controllerClass, $method, $requestMethod, $innerAction] = $this->resolveAction($action);
        if ($innerAction !== null) {
            $payload['action'] = $innerAction;
        }

        if (!$this->isResourceMutation($action)) {
            return $this->invokeController($controllerClass, $method, $payload, $requestMethod);
        }

        return $this->transactions->run(
            $this->resourceChanges->connection(),
            function () use ($controllerClass, $method, $payload, $requestMethod, $action): mixed {
                $result = $this->invokeController($controllerClass, $method, $payload, $requestMethod);
                if ($this->isSuccessfulResult($result)) {
                    $this->resourceChanges->publishAction($action, $payload);
                }
                return $result;
            },
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function loadTaglibLocalForm(array $payload): array
    {
        /** @var TaglibLocalFormService $service */
        $service = ObjectManager::getInstance(TaglibLocalFormService::class);
        $result = $service->buildFormPayload(
            trim((string)($payload['model'] ?? '')),
            trim((string)($payload['field'] ?? '')),
            trim((string)($payload['id'] ?? '')),
            trim((string)($payload['value'] ?? '')),
            trim((string)($payload['search'] ?? '')),
        );

        if (($result['success'] ?? false) !== true) {
            return $this->normalizeResponse([
                'success' => false,
                'message' => (string)($result['message'] ?? __('加载翻译表单失败')),
                'data' => [],
            ]);
        }

        return $this->normalizeResponse([
            'success' => true,
            'message' => (string)__('加载成功'),
            'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function aiTranslateTaglibLocalForm(array $payload): array
    {
        /** @var TaglibLocalFormService $service */
        $service = ObjectManager::getInstance(TaglibLocalFormService::class);
        $result = $service->aiTranslate(
            trim((string)($payload['model'] ?? '')),
            trim((string)($payload['field'] ?? '')),
            trim((string)($payload['id'] ?? '')),
            trim((string)($payload['value'] ?? '')),
            ($payload['retranslate_all'] ?? false) === true
                || ($payload['retranslate_all'] ?? '') === '1'
                || ($payload['retranslate'] ?? false) === true
                || ($payload['retranslate'] ?? '') === '1',
        );

        if (($result['success'] ?? false) !== true) {
            return $this->normalizeResponse([
                'success' => false,
                'message' => (string)($result['message'] ?? __('AI翻译调用失败')),
                'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            ]);
        }

        return $this->normalizeResponse([
            'success' => true,
            'message' => (string)($result['message'] ?? __('AI 翻译完成')),
            'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function aiTranslateTaglibLocalFormBulk(array $payload): array
    {
        $fields = $payload['fields'] ?? [];
        if (!is_array($fields)) {
            throw new \InvalidArgumentException((string)__('I18n 后台操作参数必须是对象'));
        }

        /** @var TaglibLocalFormService $service */
        $service = ObjectManager::getInstance(TaglibLocalFormService::class);
        $result = $service->aiTranslateFieldsBulk(
            trim((string)($payload['model'] ?? '')),
            trim((string)($payload['id'] ?? '')),
            $fields,
            ($payload['retranslate_all'] ?? false) === true
                || ($payload['retranslate_all'] ?? '') === '1'
                || ($payload['retranslate'] ?? false) === true
                || ($payload['retranslate'] ?? '') === '1',
        );

        if (($result['success'] ?? false) !== true) {
            return $this->normalizeResponse([
                'success' => false,
                'message' => (string)($result['message'] ?? __('AI翻译调用失败')),
                'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            ]);
        }

        return $this->normalizeResponse([
            'success' => true,
            'message' => (string)($result['message'] ?? __('AI 批量翻译完成')),
            'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
        ]);
    }

    private function isResourceMutation(string $action): bool
    {
        return in_array($action, [
            'dictionary-add',
            'dictionary-edit',
            'dictionary-quick-save',
        ], true);
    }

    private function isSuccessfulResult(mixed $result): bool
    {
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }
        return !is_array($result)
            || (($result['success'] ?? true) !== false && (int)($result['code'] ?? 200) < 400);
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
                    'backend_acl' => [
                        'kind' => 'param_map',
                        'param' => 'action',
                        'map' => self::ACTION_ACL_SOURCES,
                    ],
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
            'dictionary-batch-translate' => ['postBatchTranslate', 'POST', null],
            'dictionary-module-catalog' => ['getModuleCatalog', 'GET', null],
            'dictionary-module-translate' => ['postModuleTranslate', 'POST', null],
            'dictionary-module-export-csv' => ['postModuleExportCsv', 'POST', null],
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
            'ai-module-matrix' => ['getModuleLocaleMatrix', 'GET', null],
            'ai-module-translate-writeback' => ['postModuleTranslateAndWriteback', 'POST', null],
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
        $payload = $this->normalizeScalarPayloadKeys($this->normalizeActionPayload($payload));
        $response = AdminControllerBridge::invoke(
            $controllerClass,
            [$method],
            $payload,
            $payload,
            $requestMethod,
        );

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
     * @deprecated Use AdminControllerBridge via invokeController().
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
        $payload = $this->normalizeActionPayload($payload);
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

    /**
     * bin-query payload 常以扁平 bracket key 传输（description[locale][field]），
     * 需还原为 PHP 表单数组后再交给控制器。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeActionPayload(array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        $needsNormalization = false;
        foreach (array_keys($payload) as $key) {
            if (str_contains((string)$key, '[')) {
                $needsNormalization = true;
                break;
            }
        }
        if (!$needsNormalization) {
            return $payload;
        }

        $normalized = [];
        parse_str(http_build_query($payload, '', '&', PHP_QUERY_RFC3986), $normalized);

        return is_array($normalized) ? $normalized : $payload;
    }

    /**
     * bin-query/JS 可能把 URL 与 hidden 字段重复提交为数组，需还原成标量。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeScalarPayloadKeys(array $payload): array
    {
        foreach (['model', 'field', 'id', 'value', 'isIframe', 'action'] as $key) {
            if (!array_key_exists($key, $payload) || !is_array($payload[$key])) {
                continue;
            }

            $resolved = '';
            foreach ($payload[$key] as $candidate) {
                if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                    $resolved = trim((string)$candidate);
                }
            }
            $payload[$key] = $resolved;
        }

        return $payload;
    }
}
