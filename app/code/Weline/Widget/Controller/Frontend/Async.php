<?php

declare(strict_types=1);

namespace Weline\Widget\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\WidgetData;
use Weline\Widget\Taglib\Widget;

/**
 * 异步部件的统一 SSR 片段端点。
 *
 * 首屏只输出框架生成的空 SSR 标记（{@see Widget::renderAsyncStub()}），
 * 浏览器延迟到 idle/进视口再从这里取回同一套部件模板的服务端渲染结果，
 * 所以部件结构只维护一份，不存在客户端模板。
 *
 * 只放行「元数据 @widget.async=true 且 @widget.async_module 非空」的部件；
 * 其它 code 一律 204，不做任意模板渲染。
 *
 * URL: /widget/frontend/async?type=<type>&code=<code>&v=<发布代次>
 * @Extra type=fpc enabled=false
 */
class Async extends FrontendController
{
    public function index(): Response
    {
        $type = \trim((string)$this->request->getParam('type', ''));
        $code = \trim((string)$this->request->getParam('code', ''));
        if ($type === '' || $code === '') {
            return $this->respondFragment('', 0);
        }

        /** @var WidgetData $widgetData */
        $widgetData = ObjectManager::getInstance(WidgetData::class);
        $widget = $widgetData->getWidget($type, $code);
        if (!$widget || !Widget::isAsyncWidget($widget)) {
            return $this->respondFragment('', 0);
        }

        $params = $this->filterParams($widget, $this->readParams());
        $html = '';
        try {
            $html = Widget::renderAsyncFragment($type, $code, $params);
        } catch (\Throwable $e) {
            w_log_error('异步部件片段渲染失败: ' . $e->getMessage(), [], 'WidgetAsyncFragment');
            $html = '';
        }

        return $this->respondFragment($html, (int)($widget['async_cache'] ?? 0), $type, $code);
    }

    /**
     * 只接受部件自己声明的参数键（类型按 @param 声明强制），其余一律丢弃。
     *
     * @return array<string, mixed>
     */
    private function filterParams(array $widget, array $params): array
    {
        if ($params === []) {
            return [];
        }

        $schema = \is_array($widget['params'] ?? null) ? $widget['params'] : [];
        $filtered = [];
        foreach ($schema as $name => $definition) {
            if (!\array_key_exists($name, $params) || !\is_array($definition)) {
                continue;
            }
            $filtered[$name] = $this->coerceParam($params[$name], (string)($definition['type'] ?? 'string'));
        }

        return $filtered;
    }

    private function coerceParam(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool', 'boolean' => \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'on', 'yes'], true),
            'int', 'integer' => (int)$value,
            'float', 'number' => (float)$value,
            'array', 'list' => \is_array($value) ? $value : [],
            default => \is_scalar($value) ? (string)$value : '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function readParams(): array
    {
        $raw = $this->request->getParam('params', null);
        if (\is_array($raw)) {
            return $raw;
        }
        if (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);

            return \is_array($decoded) ? $decoded : [];
        }

        $body = (string)$this->request->getBody();
        if ($body === '') {
            return [];
        }
        $decoded = \json_decode($body, true);
        if (\is_array($decoded) && \is_array($decoded['params'] ?? null)) {
            return $decoded['params'];
        }

        return [];
    }

    private function respondFragment(string $html, int $ttl, string $type = '', string $code = ''): Response
    {
        $payload = [
            'html' => $html,
            'type' => $type,
            'code' => $code,
            'ttl' => \max(0, $ttl),
            'v' => \trim((string)$this->request->getParam('v', '')),
        ];

        if ($html === '') {
            $response = Response::text(
                (string)\json_encode(['html' => ''], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                204,
                'application/json; charset=utf-8',
            );
            $response->setHeader('Cache-Control', 'no-store, max-age=0');

            return $response;
        }

        $response = Response::text(
            (string)\json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            200,
            'application/json; charset=utf-8',
        );
        // 异步片段无登录态：浏览器 / CDN / 边缘都按 ttl 缓存，避免每次访问都回源。
        $cache = $ttl > 0
            ? 'public, max-age=' . $ttl . ', s-maxage=' . $ttl . ', stale-while-revalidate=' . $ttl
            : 'no-store, max-age=0';
        $response->setHeader('Cache-Control', $cache);
        $response->setHeader('X-Weline-Widget-Async', '1');
        if ($ttl > 0) {
            $response->setHeader('X-Weline-Widget-Async-Ttl', (string)$ttl);
        }

        return $response;
    }
}
