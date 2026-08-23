<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Query;

use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Theme\Controller\Backend\ThemeEditor;
use Weline\Theme\Extends\Module\Weline_Framework\Query\ThemeQueryProvider;

class ThemeEditorRequestParamsTest extends TestCore
{
    public function testNamedParamsBodyFieldSurvivesEditorRequestInjection(): void
    {
        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $parameterBag = $request->getParameterBag();
        $originalData = $request->getData();
        $originalQuery = $parameterBag->getQuery();
        $originalRequest = $parameterBag->getRequest();
        $originalBody = $parameterBag->getBody();

        $schema = json_encode([
            'background_image' => [
                'type' => 'image',
                'label' => '背景图片',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $bodyParams = [
            'layoutId' => '687',
            'params' => $schema,
            'config' => '{}',
        ];

        try {
            $request->unsetData();
            $parameterBag->replace();

            $provider = (new ReflectionClass(ThemeQueryProvider::class))->newInstanceWithoutConstructor();
            $inject = new ReflectionMethod(ThemeQueryProvider::class, 'injectEditorRequestParams');
            $inject->setAccessible(true);
            $inject->invoke($provider, ['locale' => 'zh_Hans_CN'], $bodyParams, http_build_query($bodyParams));

            self::assertSame($schema, $request->getPost('params'));
            self::assertSame('687', $request->getPost('layoutId'));
            self::assertSame('zh_Hans_CN', $request->getGet('locale'));
            self::assertSame($bodyParams, $request->getBodyParams());
            self::assertSame(
                array_merge(['locale' => 'zh_Hans_CN'], $bodyParams),
                $request->getData('__theme_editor_request_params'),
            );
        } finally {
            $request->unsetData();
            $request->setData($originalData);
            $parameterBag->replace($originalQuery, $originalRequest, $originalBody);
        }
    }

    public function testHeaderTypedContextFillsOnlyMissingInnerContext(): void
    {
        $provider = (new ReflectionClass(ThemeQueryProvider::class))->newInstanceWithoutConstructor();
        $attach = new ReflectionMethod(ThemeQueryProvider::class, 'attachFallbackEditorContext');
        $attach->setAccessible(true);
        $fromHeaders = new ReflectionMethod(ThemeQueryProvider::class, 'editorContextFromHeaders');
        $fromHeaders->setAccessible(true);
        $context = [
            'scope' => [
                'identity' => [
                    'scope_kind' => 'store',
                    'website_code' => 'default',
                    'store_code' => 'default',
                ],
            ],
            'resource_type' => 'layout',
        ];
        $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);
        $fallback = $fromHeaders->invoke(
            $provider,
            ['x-weline-editor-context' => $encodedContext],
        );
        self::assertSame($encodedContext, $fallback);

        [$query, $body] = $attach->invoke($provider, [], [], $fallback);
        self::assertSame($encodedContext, $query['editor_context']);
        self::assertSame([], $body);

        [$query, $body] = $attach->invoke(
            $provider,
            ['editor_context' => 'inner-query'],
            [],
            $fallback,
        );
        self::assertSame('inner-query', $query['editor_context']);
        self::assertSame([], $body);

        [$query, $body] = $attach->invoke(
            $provider,
            [],
            ['editor_context' => 'inner-body'],
            $fallback,
        );
        self::assertArrayNotHasKey('editor_context', $query);
        self::assertSame('inner-body', $body['editor_context']);
    }

    public function testSyntheticEditorParamsOverrideOuterWlsPacket(): void
    {
        $editorContext = json_encode([
            'scope' => [
                'identity' => [
                    'scope_kind' => 'store',
                    'website_code' => 'default',
                    'store_code' => 'default',
                ],
            ],
            'area' => 'frontend',
            'resource_type' => 'layout',
        ], JSON_THROW_ON_ERROR);
        $request = new class extends Request {
            public function getParams()
            {
                return [
                    'type' => 'call',
                    'provider' => 'theme',
                    'operation' => 'editorRequest',
                    'params' => ['url' => '/outer-query-bin-packet'],
                ];
            }

            public function getBodyParams(bool $array = false)
            {
                return json_encode([
                    'type' => 'call',
                    'provider' => 'theme',
                    'operation' => 'editorRequest',
                    'editor_area' => 'backend',
                ], JSON_THROW_ON_ERROR);
            }
        };
        // A legitimate field named "params" may overwrite Request's generic
        // params cache, while the dedicated marker retains the complete inner input.
        $request->setData('params', ['url' => '/inner-field-named-params']);
        $request->setData('__theme_editor_request_params', [
            'editor_context' => $editorContext,
            'theme_id' => '1',
            'editor_area' => 'frontend',
        ]);

        $controller = (new ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
        $requestProperty = new \ReflectionProperty(\Weline\Framework\Controller\Core::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);
        $payloadMethod = new ReflectionMethod(ThemeEditor::class, 'getEditorJsonPayload');
        $payloadMethod->setAccessible(true);
        $payload = $payloadMethod->invoke($controller);

        self::assertSame($editorContext, $payload['editor_context']);
        self::assertSame('1', $payload['theme_id']);
        self::assertSame('frontend', $payload['editor_area']);
        self::assertArrayNotHasKey('type', $payload);
        self::assertArrayNotHasKey('params', $payload);
    }

    public function testThemeTokenReadUsesTheSyntheticInnerEditorPayload(): void
    {
        $source = file_get_contents(BP . '/app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        self::assertIsString($source);
        $offset = strpos($source, 'public function getThemeTokens()');
        self::assertNotFalse($offset);
        $method = substr($source, $offset, 1400);

        self::assertStringContainsString('$input = $this->getEditorJsonPayload();', $method);
        self::assertStringContainsString('$this->validateLegacyEditorContext(', $method);
        self::assertStringNotContainsString('$this->request->getParams()', $method);
    }

    public function testRequestedAreaHonorsExplicitFrontendBeforeBackendFallback(): void
    {
        $controller = (new ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
        $normalize = new ReflectionMethod(ThemeEditor::class, 'normalizeRequestedArea');
        $normalize->setAccessible(true);

        self::assertSame('frontend', $normalize->invoke($controller, 'frontend', 'backend'));
        self::assertSame('backend', $normalize->invoke($controller, 'backend', 'frontend'));
    }

    public function testNavigationContextDoesNotMixPreviewAndLayoutTargetSemantics(): void
    {
        $controller = (new ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
        $selectInput = new ReflectionMethod(ThemeEditor::class, 'layoutIdentityInputForNavigationContext');
        $selectInput->setAccessible(true);
        $typedContext = ['target_type' => 'global', 'target_id' => 0];

        self::assertSame(
            ['editor_context' => $typedContext],
            $selectInput->invoke($controller, [
                'editor_context' => $typedContext,
                'editor_area' => 'frontend',
                'target_type' => 'layout',
                'target_value' => 'homepage',
            ])
        );
        self::assertSame([], $selectInput->invoke($controller, [
            'editor_area' => 'frontend',
            'target_type' => 'layout',
            'target_value' => 'homepage',
        ]));
    }

    public function testScopedEditorBridgeMapsReadEditAndPublishAclExactly(): void
    {
        $provider = (new ReflectionClass(ThemeQueryProvider::class))->newInstanceWithoutConstructor();
        $source = new ReflectionMethod(ThemeQueryProvider::class, 'scopedEditorRequestAclSourceId');
        $source->setAccessible(true);

        self::assertSame(
            'Weline_Theme::theme_visual_editor_scope_read',
            $source->invoke($provider, '/theme/backend/theme-editor/scoped-workspace', 'GET'),
        );
        self::assertSame(
            'Weline_Theme::theme_visual_editor_scope_edit',
            $source->invoke($provider, '/theme/backend/theme-editor/scoped-workspace', 'POST'),
        );
        self::assertSame(
            'Weline_Theme::theme_visual_editor_scope_publish',
            $source->invoke($provider, '/theme/backend/theme-editor/publish-scoped-workspace', 'POST'),
        );
        self::assertNull($source->invoke($provider, '/theme/backend/theme-editor/layout-preview', 'GET'));
    }

    public function testScopedEditorBridgeRejectsNonPostPublish(): void
    {
        $provider = (new ReflectionClass(ThemeQueryProvider::class))->newInstanceWithoutConstructor();
        $source = new ReflectionMethod(ThemeQueryProvider::class, 'scopedEditorRequestAclSourceId');
        $source->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_scope_publish_method_invalid');
        $source->invoke($provider, '/theme/backend/theme-editor/publish-scoped-workspace', 'GET');
    }
}
