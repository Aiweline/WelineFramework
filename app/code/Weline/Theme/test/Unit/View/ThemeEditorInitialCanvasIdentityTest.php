<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ThemeEditorInitialCanvasIdentityTest extends TestCase
{
    public function testFirstCanvasRequestKeepsTheSelectedOwnerResourceAndVersion(): void
    {
        $identity = [
            'scope_kind' => 'channel', 'website_id' => 3, 'website_code' => 'shop',
            'store_code' => 'wholesale', 'channel_code' => 'mobile',
            'store_mode' => 'b2b', 'context_version' => 'v1',
        ];
        $query = $this->renderInitialQuery([
            'theme_id' => 17, 'frontend_theme_id' => 17, 'backend_theme_id' => 21,
            'page_type' => 'homepage', 'layout_option' => 'wide',
            'selected_scope' => 'shop.wholesale.mobile', 'scope_identity' => $identity,
            'layout_identity' => ['scope' => 'shop.wholesale.mobile', 'target_type' => 'website', 'target_id' => 3],
            'preview_context' => ['scope' => 'default', 'status' => 'published', 'version_id' => 1116],
        ]);

        self::assertSame('shop.wholesale.mobile', $query['scope'] ?? null);
        self::assertSame('b2b', $query['store_mode'] ?? null);
        self::assertSame('1116', $query['version_id'] ?? null);
        self::assertSame('wide', $query['layout_option'] ?? null);
        self::assertSame('published', $query['status'] ?? null);
        self::assertSame('21', $query['backend_theme_id'] ?? null);
        $context = json_decode($query['editor_context'] ?? '', true);
        self::assertSame($identity, $context['scope']['identity'] ?? null);
        self::assertSame('homepage', $context['layout_type'] ?? null);
        self::assertSame('wide', $context['layout_option'] ?? null);
        self::assertSame('website', $context['target_type'] ?? null);
        self::assertSame(3, $context['target_id'] ?? null);
        self::assertSame('theme-editor', $query['shell'] ?? null);
    }

    public function testCrossHostStorefrontOriginMakesInitialCanvasAbsolute(): void
    {
        $identity = [
            'scope_kind' => 'website', 'website_id' => 544, 'website_code' => 'grocery',
            'store_code' => null, 'channel_code' => null,
            'store_mode' => null, 'context_version' => 'v1',
        ];
        $url = $this->renderInitialUrl([
            'theme_id' => 7, 'frontend_theme_id' => 7, 'backend_theme_id' => 7,
            'page_type' => 'homepage', 'layout_option' => 'default',
            'selected_scope' => 'grocery.default.default', 'scope_identity' => $identity,
            'storefront_origin' => 'https://grocery.test.weline.com',
            'storefront_paths_by_layout' => ['homepage' => '/'],
            'layout_identity' => ['scope' => 'grocery.default.default', 'target_type' => 'global', 'target_id' => 0],
            'preview_context' => ['scope' => 'grocery.default.default', 'status' => 'draft', 'version_id' => 0],
        ]);

        self::assertSame('https', parse_url($url, PHP_URL_SCHEME));
        self::assertSame('grocery.test.weline.com', parse_url($url, PHP_URL_HOST));
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('7', $query['theme_id'] ?? null);
        self::assertSame('1', $query['editor_mode'] ?? null);
        self::assertSame('theme-editor', $query['shell'] ?? null);
        $context = json_decode($query['editor_context'] ?? '', true);
        self::assertSame($identity, $context['scope']['identity'] ?? null);
    }

    public function testSameHostCanvasPrependsStorefrontMountPath(): void
    {
        $identity = [
            'scope_kind' => 'website', 'website_id' => 158, 'website_code' => 'daocharms',
            'store_code' => null, 'channel_code' => null,
            'store_mode' => null, 'context_version' => 'v1',
        ];
        $url = $this->renderInitialUrl([
            'theme_id' => 12, 'frontend_theme_id' => 12, 'backend_theme_id' => 12,
            'page_type' => 'homepage', 'layout_option' => 'default',
            'selected_scope' => 'daocharms.default.default', 'scope_identity' => $identity,
            'storefront_origin' => '',
            'storefront_mount_path' => '/~site/daocharms',
            'storefront_paths_by_layout' => ['homepage' => '/'],
            'layout_identity' => ['scope' => 'daocharms.default.default', 'target_type' => 'global', 'target_id' => 0],
            'preview_context' => ['scope' => 'daocharms.default.default', 'status' => 'draft', 'version_id' => 0],
        ]);

        self::assertSame('/~site/daocharms', parse_url($url, PHP_URL_PATH));
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('12', $query['theme_id'] ?? null);
        self::assertSame('theme-editor', $query['shell'] ?? null);
    }

    public function testBackendEditorAreaCanvasUsesAdminDashboardNotStorefront(): void
    {
        $identity = [
            'scope_kind' => 'website', 'website_id' => 544, 'website_code' => 'grocery',
            'store_code' => null, 'channel_code' => null,
            'store_mode' => null, 'context_version' => 'v1',
        ];
        $url = $this->renderInitialUrl([
            'theme_id' => 7, 'frontend_theme_id' => 7, 'backend_theme_id' => 7,
            'editor_area' => 'backend',
            'page_type' => 'homepage', 'layout_option' => 'default',
            'selected_scope' => 'grocery.default.default', 'scope_identity' => $identity,
            'storefront_origin' => 'https://grocery.test.weline.com',
            'backend_dashboard_url' => 'https://p05113ef3.test.weline.com:9555/admin/weline_dashboard/backend/dashboard',
            'storefront_paths_by_layout' => ['homepage' => '/'],
            'layout_identity' => ['scope' => 'grocery.default.default', 'target_type' => 'global', 'target_id' => 0],
            'preview_context' => ['scope' => 'grocery.default.default', 'status' => 'draft', 'version_id' => 0],
        ]);

        self::assertSame('p05113ef3.test.weline.com', parse_url($url, PHP_URL_HOST));
        self::assertStringContainsString('/weline_dashboard/backend/dashboard', (string)parse_url($url, PHP_URL_PATH));
        self::assertStringNotContainsString('grocery.test.weline.com', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('backend', $query['editor_area'] ?? null);
        self::assertSame('backend', $query['preview_area'] ?? null);
        self::assertSame('1', $query['editor_mode'] ?? null);
        self::assertSame('theme-editor', $query['shell'] ?? null);
        self::assertArrayNotHasKey('layout_option', $query);
        $context = json_decode($query['editor_context'] ?? '', true);
        self::assertSame('backend', $context['area'] ?? null);
        self::assertSame('dashboard', $context['layout_type'] ?? null);
        self::assertSame('default', $context['layout_option'] ?? null);
    }

    public function testLockedCanvasKeepsItsConcreteTargetFromTheFirstRequest(): void
    {
        $identity = [
            'scope_kind' => 'store', 'website_id' => 0, 'website_code' => 'default',
            'store_code' => 'retail', 'channel_code' => '__channel__',
            'store_mode' => 'normal', 'context_version' => 'v1',
        ];
        $query = $this->renderInitialQuery([
            'theme_id' => 17, 'page_type' => 'cms', 'layout_option' => 'landing',
            'selected_scope' => 'default.retail.__channel__', 'scope_identity' => $identity,
            'layout_identity' => ['scope' => 'default.retail.__channel__', 'target_type' => 'cms', 'target_id' => 84],
            'layout_editor_lock' => [
                'enabled' => true, 'scope' => 'default.retail.__channel__', 'store_mode' => 'normal',
                'website_id' => 0, 'website_code' => 'default', 'store_code' => 'retail',
                'target_type' => 'cms', 'target_id' => 84,
            ],
        ]);

        self::assertSame('default.retail.__channel__', $query['scope'] ?? null);
        self::assertSame('cms', $query['theme_layout_target_type'] ?? null);
        self::assertSame('84', $query['theme_layout_target_id'] ?? null);
        $context = json_decode($query['editor_context'] ?? '', true);
        self::assertSame($identity, $context['scope']['identity'] ?? null);
        self::assertSame('cms', $context['target_type'] ?? null);
        self::assertSame(84, $context['target_id'] ?? null);
        self::assertArrayNotHasKey('version_id', $query);
    }

    private function renderInitialQuery(array $data): array
    {
        $url = $this->renderInitialUrl($data);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return $query;
    }

    private function renderInitialUrl(array $data): string
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/index.phtml');
        $preamble = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                break;
            }
            if (is_array($token) && $token[0] === T_OPEN_TAG) {
                continue;
            }
            $preamble .= is_array($token) ? $token[1] : $token;
        }
        $view = new class($data) {
            public object $request;

            public function __construct(private array $data)
            {
                $this->request = new class {
                    public function getParam(string $name, mixed $default = null): mixed
                    {
                        return $default;
                    }

                    public function getServer(string $name, mixed $default = null): mixed
                    {
                        return match ($name) {
                            'HTTP_HOST' => 'p05113ef3.test.weline.com',
                            'REQUEST_SCHEME' => 'https',
                            'HTTPS' => 'on',
                            'SERVER_PORT' => '443',
                            default => $default,
                        };
                    }
                };
            }

            public function getData(string $name): mixed
            {
                return $this->data[$name] ?? ($name === 'website_default_locale' ? 'en_US' : null);
            }
        };
        $render = function () use ($preamble): string {
            return eval($preamble . "\n" . 'return $initialPreviewUrl;');
        };

        return (string)$render->call($view);
    }
}
