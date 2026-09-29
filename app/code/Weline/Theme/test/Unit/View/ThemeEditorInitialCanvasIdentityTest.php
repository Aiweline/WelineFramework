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
        $url = $render->call($view);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return $query;
    }
}
