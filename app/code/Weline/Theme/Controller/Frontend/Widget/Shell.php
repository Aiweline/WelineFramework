<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\Widget;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\ThemeRenderableResolver;

/**
 * 店面部件「空 SSR」片段：只回中性壳，不查也不带任何个人购物车数据。
 *
 * 迷你购物车不允许随页面立即下发抽屉 HTML：页面只出挂载点，
 * 壳由本端点按版本号取一次，浏览器本地缓存后不再重复请求；
 * 商品行/金额由 mini-cart-icon.js 走 Weline.Api 取数据自行渲染。
 *
 * 响应体是 JSON（{html: 片段}）：PC 前台的 HTML 文档响应会被 CSP meta / 开发面板等
 * 文档级注入装饰，片段必须走非文档正文，避免把 head 级内容塞进抽屉。
 *
 * URL: /theme/frontend/widget/shell?code=mini-cart-icon&v=<版本>
 * @Extra type=fpc enabled=false
 */
class Shell extends FrontendController
{
    /** 允许异步取壳的部件：code => [module, widget_type]。 */
    private const ALLOWED_WIDGETS = [
        'mini-cart-icon' => ['Weline_Theme', 'header'],
    ];

    public function index(): Response
    {
        // 全局区异步片段水合：按片段 id 取回标记。开关关掉的片段不会返回内容。
        $fragmentId = \trim((string)$this->request->getParam('fragment', ''));
        if ($fragmentId !== '') {
            // 占位里可能带着「上一跳布局实体里该部件的配置」令牌（编辑器保存的那份）；
            // 验签通过才用，验不过就按未携带处理（绝不信任客户端直接送上来的配置）。
            $overrides = \Weline\Theme\Region\FragmentRegionProvider::verifyConfigToken(
                \trim((string)$this->request->getParam('c', '')),
                \trim((string)$this->request->getParam('s', '')),
            ) ?? [];

            // 片段内容随「已发布配置」变化；登录态无关的片段按 TTL 缓存（浏览器 + CDN）。
            // TTL 取片段 @global-region.cache，缺省用部件 async_cache；为 0 时保持 no-store。
            $ttl = \Weline\Theme\Region\GlobalRegion::fragmentCacheTtl($fragmentId);
            $cacheControl = $ttl > 0
                ? 'public, max-age=' . $ttl . ', s-maxage=' . $ttl . ', stale-while-revalidate=' . $ttl
                : 'no-store, max-age=0';

            return $this->respond(
                \Weline\Theme\Region\GlobalRegion::renderFragment($fragmentId, $overrides),
                $cacheControl,
            );
        }

        $code = \trim((string)$this->request->getParam('code', ''));
        if ($code === '' || !isset(self::ALLOWED_WIDGETS[$code])) {
            return $this->respond('');
        }

        [$module, $type] = self::ALLOWED_WIDGETS[$code];
        $theme = null;
        try {
            $theme = ThemeData::getCurrentTheme();
        } catch (\Throwable) {
            $theme = null;
        }

        try {
            $definition = ObjectManager::getInstance(ThemePlaceableRegistry::class)
                ->find($module, $type, $code, $theme, 'frontend');
            if ($definition === null) {
                return $this->respond('');
            }
            $renderable = ObjectManager::getInstance(ThemeRenderableResolver::class)->resolve($definition, []);
            $templatePath = (string)($renderable->templatePath ?? '');
            if ($templatePath === '') {
                return $this->respond('');
            }
            // 定义里是逻辑模板路径（Weline_Theme::…）：按部件同一解析链落成物理文件再渲染。
            if (!\is_file($templatePath)) {
                $resolved = ObjectManager::getInstance(\Weline\Framework\View\Template::class)
                    ->convertFetchFileName($templatePath);
                $templatePath = (string)($resolved[1] ?? '');
            }
            if (!\is_file($templatePath)) {
                return $this->respond('');
            }
            $html = ObjectManager::getInstance(RuntimeTemplateMaterializer::class)->renderFile(
                $templatePath,
                ['shell_mode' => true, 'preview_mode' => false],
            );
        } catch (\Throwable) {
            return $this->respond('');
        }

        return $this->respond((string)$html);
    }

    /**
     * 壳内容中性（无个人数据），URL 带版本号即可长期缓存；失败一律 no-store，不回旧壳。
     */
    private function respond(string $html, string $cacheControl = ''): Response
    {
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
            (string)\json_encode(['html' => $html], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            200,
            'application/json; charset=utf-8',
        );
        $version = \trim((string)$this->request->getParam('v', ''));
        $response->setHeader(
            'Cache-Control',
            $cacheControl !== ''
                ? $cacheControl
                : ($version !== ''
                    ? 'public, max-age=31536000, immutable'
                    : 'public, max-age=600, stale-while-revalidate=86400'),
        );
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Vary', 'Accept-Language');

        return $response;
    }
}
