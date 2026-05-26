<?php
declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

/**
 * 主题查询器
 *
 * 提供 getActiveTheme、getConfigValue 等能力，供其他模块通过 w_query('theme', ...) 调用。
 */
class ThemeQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeContextService $themeContext,
    ) {
    }

    public function getProviderName(): string
    {
        return 'theme';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getActiveTheme' => $this->getActiveTheme($params),
            'getConfigValue' => $this->getConfigValue($params),
            'getTemplatePath' => $this->getTemplatePath($params),
            'scanThemeLayoutsByType' => $this->scanThemeLayoutsByType($params),
            'editorRequest' => $this->editorRequest($params),
            default => throw new \InvalidArgumentException(
                (string)__('Theme 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function getActiveTheme(array $params): ?array
    {
        $area = $this->normalizeQueryArea($params['area'] ?? null);
        $resolved = $this->themeContext->resolveTheme($area);
        if ($resolved === null || !$resolved->getId()) {
            return null;
        }

        $field = $this->themeContext->getActivationField($area);

        return [
            'id' => $resolved->getId(),
            'name' => $resolved->getData(WelineTheme::schema_fields_NAME),
            'module_name' => $resolved->getData(WelineTheme::schema_fields_MODULE_NAME),
            'path' => $resolved->getData(WelineTheme::schema_fields_PATH),
            'parent_id' => $resolved->getData(WelineTheme::schema_fields_PARENT_ID),
            'is_active' => (int)$resolved->getData($field) === 1,
            'config' => $resolved->getData(WelineTheme::schema_fields_CONFIG),
            'preview_image' => $resolved->getPreviewImage(),
            'frontend_preview_image' => $resolved->getFrontendPreviewImage(),
            'backend_preview_image' => $resolved->getBackendPreviewImage(),
        ];
    }

    private function getConfigValue(array $params): ?string
    {
        $layout = (string)($params['layout'] ?? '');
        $area = (string)($params['area'] ?? '');
        $locale = (string)($params['locale'] ?? '');
        $field = (string)($params['field'] ?? 'value');

        if ($layout === '') {
            return null;
        }

        return \Weline\Theme\Helper\ThemeConfigHelper::getConfigValue(
            $layout,
            $area !== '' ? $area : null,
            $locale !== '' ? $locale : null,
            $field
        );
    }

    private function getTemplatePath(array $params): string
    {
        $layout = (string)($params['layout'] ?? '');
        $area = (string)($params['area'] ?? '');
        $locale = (string)($params['locale'] ?? '');
        $defaultValue = (string)($params['default_value'] ?? 'default');

        if ($layout === '') {
            return '';
        }

        return \Weline\Theme\Helper\ThemeConfigHelper::getTemplatePath(
            $layout,
            $area !== '' ? $area : null,
            $locale !== '' ? $locale : null,
            $defaultValue
        );
    }

    /**
     * 扫描当前激活主题中指定类型的布局文件（含主题继承链）
     */
    private function scanThemeLayoutsByType(array $params): array
    {
        $layoutType = (string)($params['layout_type'] ?? '');
        $area = $this->normalizeQueryArea($params['area'] ?? 'frontend', true) ?? ThemeContextService::AREA_FRONTEND;
        if ($layoutType === '') {
            return [];
        }
        $resolved = $this->themeContext->resolveTheme($area);
        if ($resolved !== null && $resolved->getId()) {
            $theme = $resolved;
        } else {
            $theme = clone $this->welineTheme;
            $theme->clearData()->clearQuery();
            $theme->getActiveTheme($area);
        }
        if (!$theme->getId()) {
            return [];
        }
        return $this->doScanThemeLayouts($layoutType, $area, $theme);
    }

    private function editorRequest(array $params): mixed
    {
        $url = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'GET'))) ?: 'GET';
        $headers = is_array($params['headers'] ?? null) ? $params['headers'] : [];
        $body = array_key_exists('body', $params) && $params['body'] !== null ? (string)$params['body'] : '';

        if ($url === '') {
            return ['success' => false, 'message' => 'Missing editor request URL.'];
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            return ['success' => false, 'message' => 'Unsupported editor request method.'];
        }

        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        $url = $this->resolveEditorRequestUrl($url, $request);
        $this->assertAllowedEditorRequestUrl($url);

        $directResponse = $this->dispatchEditorRequestDirect($url, $method, $headers, $body);
        if ($directResponse !== null) {
            return $directResponse;
        }

        $curlHeaders = ['X-Requested-With: XMLHttpRequest'];
        $hasContentType = false;
        foreach ($headers as $name => $value) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $lowerName = strtolower($name);
            if (!in_array($lowerName, ['content-type', 'accept', 'x-requested-with'], true)) {
                continue;
            }
            if ($lowerName === 'content-type') {
                $hasContentType = true;
            }
            $curlHeaders[] = $name . ': ' . (string)$value;
        }
        if ($method === 'POST' && !$hasContentType) {
            $curlHeaders[] = 'Content-Type: application/json';
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $cookie = (string)$request->getServer('HTTP_COOKIE');
        if ($cookie !== '') {
            curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        }
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }

        $raw = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);

        if ($errno !== 0 || !is_string($raw)) {
            return ['success' => false, 'message' => $error !== '' ? $error : 'Editor request failed.'];
        }

        $responseBody = substr($raw, $headerSize);
        $decoded = json_decode($responseBody, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        if ($status < 200 || $status >= 300) {
            return [
                'success' => false,
                'message' => 'Editor request returned HTTP ' . $status,
                'content_type' => $contentType,
                'body' => mb_substr(trim($responseBody), 0, 500),
            ];
        }

        return $responseBody;
    }

    private function dispatchEditorRequestDirect(string $url, string $method, array $headers, string $body): mixed
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return null;
        }

        $path = strtolower($this->normalizeEditorRequestPath((string)$parts['path']));
        if (!str_starts_with($path, '/theme/backend/theme-editor/')
            && !str_starts_with($path, '/theme/backend/widget/paramrender/')
        ) {
            return null;
        }

        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
        }
        $bodyParams = $this->parseEditorRequestBody($body, $headers);
        $this->injectEditorRequestParams($queryParams, $bodyParams, $body);
        $themeEditor = null;

        try {
            $response = match ($path) {
                '/theme/backend/theme-editor/layout-config' => ($themeEditor ??= $this->createDirectThemeEditor())->getLayoutConfigPayload(),
                '/theme/backend/theme-editor/save-layout-config' => ($themeEditor ??= $this->createDirectThemeEditor())->saveLayoutConfigPayload(),
                '/theme/backend/theme-editor/compile-layout' => ($themeEditor ??= $this->createDirectThemeEditor())->getCompileLayoutPayload(),
                '/theme/backend/theme-editor/versions' => ($themeEditor ??= $this->createDirectThemeEditor())->getVersionsPayload(),
                '/theme/backend/theme-editor/save-version' => ($themeEditor ??= $this->createDirectThemeEditor())->saveVersionPayload(),
                '/theme/backend/theme-editor/switch-version' => ($themeEditor ??= $this->createDirectThemeEditor())->switchVersionPayload(),
                '/theme/backend/theme-editor/restore-original' => ($themeEditor ??= $this->createDirectThemeEditor())->restoreOriginalPayload(),
                '/theme/backend/theme-editor/publish-version' => ($themeEditor ??= $this->createDirectThemeEditor())->publishVersionPayload(),
                '/theme/backend/theme-editor/delete-version' => ($themeEditor ??= $this->createDirectThemeEditor())->deleteVersionPayload(),
                '/theme/backend/theme-editor/rename-version' => ($themeEditor ??= $this->createDirectThemeEditor())->renameVersionPayload(),
                '/theme/backend/widget/paramrender/form' => $this->createDirectParamRender()->postForm(),
                default => null,
            };
        } catch (\Throwable $e) {
            if (method_exists($e, 'getBody')) {
                $response = (string)$e->getBody();
            } else {
                throw $e;
            }
        }

        if ($response === null) {
            return null;
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $response;
    }

    private function createDirectThemeEditor(): \Weline\Theme\Controller\Backend\ThemeEditor
    {
        $controller = new \Weline\Theme\Controller\Backend\ThemeEditor(
            ObjectManager::getInstance(WelineTheme::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutVersionService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeCacheGenerator::class),
            ObjectManager::getInstance(\Weline\Theme\Service\WidgetPositionResolver::class),
            ObjectManager::getInstance(\Weline\Widget\Service\WidgetRegistry::class),
            ObjectManager::getInstance(\Weline\Theme\Model\ThemeLayout::class),
            ObjectManager::getInstance(\Weline\Meta\Model\Meta::class),
            ObjectManager::getInstance(\Weline\Theme\Service\PreviewTokenService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\EditorLockService::class)
        );
        $this->injectRequestIntoController($controller);
        return $controller;
    }

    private function createDirectParamRender(): \Weline\Theme\Controller\Backend\Widget\ParamRender
    {
        $controller = new \Weline\Theme\Controller\Backend\Widget\ParamRender();
        $this->injectRequestIntoController($controller);
        return $controller;
    }

    private function injectRequestIntoController(object $controller): void
    {
        $this->setControllerProperty($controller, 'request', ObjectManager::getInstance(\Weline\Framework\Http\Request::class));
        $this->setControllerProperty($controller, '_objectManager', ObjectManager::getInstance());
    }

    private function setControllerProperty(object $controller, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionObject($controller);
        while ($reflection !== false) {
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);
                $property->setAccessible(true);
                $property->setValue($controller, $value);
                return;
            }
            $reflection = $reflection->getParentClass();
        }
    }

    private function parseEditorRequestBody(string $body, array $headers): array
    {
        if ($body === '') {
            return [];
        }

        $contentType = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'content-type') {
                $contentType = strtolower((string)$value);
                break;
            }
        }

        if (str_contains($contentType, 'application/json') || str_starts_with(ltrim($body), '{')) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }

        $parsed = [];
        parse_str($body, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    private function injectEditorRequestParams(array $queryParams, array $bodyParams, string $rawBody): void
    {
        /** @var \Weline\Framework\Http\Request $request */
        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        foreach ($queryParams as $key => $value) {
            $request->setGet((string)$key, $value);
        }
        foreach ($bodyParams as $key => $value) {
            $request->setPost((string)$key, $value);
        }

        $merged = array_merge($queryParams, $bodyParams);
        $request->setData('params', $merged);
        $request->setData('body_params', $bodyParams !== [] ? $bodyParams : $rawBody);
        $request->setData('array_body_params', $bodyParams);
        $request->getParameterBag()->setBody($bodyParams);
        $request->getParameterBag()->setRawBody($rawBody);
    }

    private function resolveEditorRequestUrl(string $url, \Weline\Framework\Http\Request $request): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Invalid editor request URL.');
        }

        $https = strtolower((string)$request->getServer('HTTPS'));
        $forwardedProto = strtolower((string)$request->getServer('HTTP_X_FORWARDED_PROTO'));
        $scheme = $forwardedProto !== ''
            ? $forwardedProto
            : (($https !== '' && $https !== 'off') ? 'https' : 'http');

        if (($parts['scheme'] ?? '') === '' && ($parts['host'] ?? '') !== '') {
            return $scheme . ':' . $url;
        }

        if (($parts['scheme'] ?? '') !== '') {
            return $url;
        }

        $host = (string)($request->getServer('HTTP_HOST') ?: $request->getServer('SERVER_NAME') ?: '');
        if ($host === '') {
            throw new \InvalidArgumentException('Unable to resolve editor request host.');
        }

        return $scheme . '://' . $host . (str_starts_with($url, '/') ? $url : '/' . $url);
    }

    private function assertAllowedEditorRequestUrl(string $url): void
    {
        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            throw new \InvalidArgumentException('Invalid editor request URL.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Unsupported editor request scheme.');
        }

        $targetHost = strtolower((string)($parts['host'] ?? ''));
        if ($targetHost !== '') {
            $requestHost = strtolower((string)($request->getServer('HTTP_HOST') ?: $request->getServer('SERVER_NAME') ?: ''));
            $requestHostName = strtolower((string)(parse_url('//' . $requestHost, PHP_URL_HOST) ?: $requestHost));
            if ($requestHostName !== '' && $targetHost !== $requestHostName) {
                throw new \InvalidArgumentException('Editor request host is not allowed.');
            }
        }

        $normalizedPath = $this->normalizeEditorRequestPath((string)$parts['path']);
        foreach ([
            '/theme/backend/theme-editor/',
            '/theme/backend/widget/paramrender/form',
            '/weline/eav/api/options',
        ] as $allowedPrefix) {
            if ($normalizedPath === $allowedPrefix || str_starts_with($normalizedPath, $allowedPrefix)) {
                return;
            }
        }

        throw new \InvalidArgumentException('Editor request path is not allowed.');
    }

    private function normalizeEditorRequestPath(string $path): string
    {
        $lowerPath = strtolower($path);
        foreach (['/theme/backend/', '/weline/eav/'] as $marker) {
            $pos = strpos($lowerPath, $marker);
            if ($pos !== false) {
                return substr($path, $pos);
            }
        }

        return $path;
    }

    private function doScanThemeLayouts(string $layoutType, string $area, WelineTheme $theme): array
    {
        $layouts = [];
        $themePath = $theme->getPath();
        if ($themePath === '' || !is_dir($themePath)) {
            $parent = $theme->getParentTheme();
            return $parent ? $this->doScanThemeLayouts($layoutType, $area, $parent) : [];
        }
        $ds = \DIRECTORY_SEPARATOR;
        $layoutsDir = rtrim($themePath, $ds) . $ds . 'view' . $ds . 'theme' . $ds . $area . $ds . 'layouts' . $ds . $layoutType;
        if (!is_dir($layoutsDir)) {
            $parent = $theme->getParentTheme();
            return $parent ? $this->doScanThemeLayouts($layoutType, $area, $parent) : [];
        }
        $themeCode = $theme->getModuleName() ?: 'Weline_Theme';
        $files = glob($layoutsDir . $ds . '*.phtml') ?: [];
        foreach ($files as $file) {
            $fileName = basename($file, '.phtml');
            $layoutPath = $themeCode . '::theme/' . $area . '/layouts/' . $layoutType . '/' . $fileName;
            $meta = $this->parseLayoutMeta($file);
            $layouts[$fileName] = [
                'name' => $meta['name'] ?? ucfirst($fileName),
                'description' => $meta['description'] ?? '',
                'template' => $layoutPath,
                'preview_image' => $meta['preview_image'] ?? '',
                'config' => $meta['config'] ?? [],
            ];
        }
        return $layouts;
    }

    private function parseLayoutMeta(string $filePath): array
    {
        $meta = [];
        if (!is_file($filePath)) {
            return $meta;
        }
        $content = file_get_contents($filePath);
        if (preg_match('/@meta\.name\s*\{[^}]*name\s*=\s*"([^"]+)"/', $content, $m)) {
            $meta['name'] = $m[1];
        }
        if (preg_match('/@meta\.description\s*\{[^}]*description\s*=\s*"([^"]+)"/', $content, $m)) {
            $meta['description'] = $m[1];
        }
        if (preg_match('/@preview_image\s*\{[^}]*default\s*=\s*"([^"]+)"/', $content, $m)) {
            $meta['preview_image'] = $m[1];
        }
        return $meta;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'theme',
            'name' => __('主题查询'),
            'description' => __('提供当前主题、配置值、模板路径等查询能力'),
            'module' => 'Weline_Theme',
            'operations' => [
                [
                    'name' => 'getActiveTheme',
                    'description' => __('获取当前激活的主题信息'),
                    'params' => [
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('可选：frontend 或 backend')],
                    ],
                ],
                [
                    'name' => 'getConfigValue',
                    'description' => __('获取主题配置值'),
                    'params' => [
                        ['name' => 'layout', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'field', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getTemplatePath',
                    'description' => __('获取主题配置的模板路径'),
                    'params' => [
                        ['name' => 'layout', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'default_value', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'scanThemeLayoutsByType',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('扫描当前主题中指定类型的布局选项'),
                    'params' => [
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('默认 frontend')],
                    ],
                ],
                [
                    'name' => 'editorRequest',
                    'description' => __('Theme editor signed backend request bridge'),
                    'frontend' => true,
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true, 'max_length' => 2048],
                        ['name' => 'method', 'type' => 'string', 'required' => false, 'max_length' => 8],
                        ['name' => 'headers', 'type' => 'array', 'required' => false],
                        ['name' => 'body', 'type' => 'string', 'required' => false, 'nullable' => true, 'max_length' => 1048576],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param bool $defaultFrontend 当 area 为空时是否默认 frontend（getActiveTheme 为 false，scan 为 true）
     */
    private function normalizeQueryArea(mixed $area, bool $defaultFrontend = false): ?string
    {
        $raw = strtolower(trim((string)$area));
        if ($raw === '') {
            return $defaultFrontend ? ThemeContextService::AREA_FRONTEND : null;
        }

        return match ($raw) {
            ThemeContextService::AREA_FRONTEND => ThemeContextService::AREA_FRONTEND,
            ThemeContextService::AREA_BACKEND => ThemeContextService::AREA_BACKEND,
            default => null,
        };
    }
}
