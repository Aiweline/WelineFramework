<?php

declare(strict_types=1);

namespace Weline\Ai\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * Declarative browser extension bridge. Behaviour and styling are owned by the route bundle.
 */
final class ExtensionBridge implements TaglibInterface
{
    private const CAPABILITIES = [
        'translate' => [
            'scope' => 'capability:translate',
            'model' => 'model:browser-local',
        ],
    ];

    public static function name(): string
    {
        return 'ai:extension';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'capability' => true,
            'languages' => false,
            'id' => false,
        ];
    }

    private static function backendAuthUrl(): string
    {
        try {
            $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            return (string)$request->getUrlBuilder()->getBackendUrl('ai/backend/extensiongrants');
        } catch (\Throwable) {
            return '#';
        }
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, array $attributes): string {
            $capability = (string)($attributes['capability'] ?? '');
            if (!isset(self::CAPABILITIES[$capability])) {
                throw new \InvalidArgumentException(__('capability 不在白名单: %{1}', [$capability]));
            }

            $languages = (string)($attributes['languages'] ?? 'all');
            if ($languages !== 'all' && !preg_match('/^[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*(,[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*)*$/', $languages)) {
                throw new \InvalidArgumentException(__('languages 非法: %{1}', [$languages]));
            }

            $nonce = bin2hex(random_bytes(8));

            $phpOpen = '<' . '?php ';
            $phpClose = '?' . '>';
            $echoOpen = '<' . '?= ';
            $escapeNonce = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');

            return $phpOpen . '$Taglib__nonce = ' . var_export($nonce, true) . '; ' . $phpClose . "\n"
                . '<div class="w-ai-extension" data-w-component="ai-extension" '
                . 'data-w-ai-extension-nonce="' . $escapeNonce . '"></div>' . "\n"
                . '<script>(() => { const TYPE = \'weline-ai-ext\'; const NONCE = \'\' + ' . var_export($nonce, true) . '; window.postMessage({ type: TYPE, action: \'status\', nonce: NONCE, payload: {} }, window.location.origin); })();</script>' . "\n"
                . $echoOpen . '\\' . self::class . '::buildMarkup(['
                . "'capability' => '" . htmlspecialchars($capability, ENT_QUOTES, 'UTF-8') . "',"
                . "'languages' => '" . htmlspecialchars($languages, ENT_QUOTES, 'UTF-8') . "',"
                . "'id' => (string)(\$Taglib__id ?? 'w-ai-ext-' . substr(\$Taglib__nonce, 0, 8)),"
                . "'nonce' => \$Taglib__nonce,"
                . "'auth_url' => '" . htmlspecialchars(self::backendAuthUrl(), ENT_QUOTES, 'UTF-8') . "',"
                . ']) ' . $phpClose;
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function buildMarkup(array $attributes): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $capability = (string)($attributes['capability'] ?? '');
        if (!isset(self::CAPABILITIES[$capability])) {
            throw new \InvalidArgumentException(__('capability 不在白名单: %{1}', [$capability]));
        }

        $languages = (string)($attributes['languages'] ?? 'all');
        if ($languages !== 'all' && !preg_match('/^[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*(,[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*)*$/', $languages)) {
            throw new \InvalidArgumentException(__('languages 非法: %{1}', [$languages]));
        }

        $nonce = (string)($attributes['nonce'] ?? bin2hex(random_bytes(8)));
        $authUrl = (string)($attributes['auth_url'] ?? '#');
        $authUrlEscaped = $escape($authUrl);
        $id = trim((string)($attributes['id'] ?? ''));
        if ($id === '') {
            $id = 'w-ai-ext-' . substr($nonce, 0, 8);
        }

        $jsId = json_encode($id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsId === false) {
            $jsId = '""';
        }

        return <<<HTML
<div
    class="w-ai-extension"
    data-w-component="ai-extension"
    id="{$escape($id)}"
    data-w-ai-extension-capability="{$escape($capability)}"
    data-w-ai-extension-languages="{$escape($languages)}"
    data-w-ai-extension-nonce="{$escape($nonce)}"
>
    <div class="w-ai-extension__empty" data-w-ai-extension-empty>
        浏览器本地模型扩展未授权或已停机
        <a class="w-button" data-tone="neutral" href='{$authUrlEscaped}' data-w-ai-extension-go-auth>回后台授权</a>
    </div>
    <script>
    (() => {
        'use strict';
        const TYPE = 'weline-ai-ext';
        const CONTAINER_ID = {$jsId};
        const container = document.getElementById(CONTAINER_ID);
        const nonce = container?.dataset.wAiExtensionNonce ?? '';
        const empty = container?.querySelector('[data-w-ai-extension-empty]');
        if (!container || !nonce) {
            return;
        }
        window.dispatchEvent(new CustomEvent('weline-ai-ext-request', {
            detail: { type: TYPE, action: 'status', nonce, payload: {} },
        }));
        window.addEventListener('weline-ai-ext-reply', (event) => {
            const data = event.detail;
            if (!data || data.type !== TYPE || data.nonce !== nonce) {
                return;
            }
            const payload = data.payload ?? {};
            if (!payload.online || !payload.authorized) {
                return;
            }
            empty?.replaceChildren();
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'w-button';
            button.dataset.tone = 'primary';
            button.dataset.wAiExtensionRun = '';
            button.textContent = '翻译';
            empty?.append(button);
        });
    })();
    </script>
</div>
HTML;
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return htmlspecialchars(
            '<w:ai:extension capability="translate" languages="all" />',
            ENT_QUOTES,
        );
    }
}
