<?php

declare(strict_types=1);

namespace Weline\Cdn\Controller\Backend;

/**
 * CDN 后台 OffCanvas / iframe 嵌入：去掉顶栏侧栏与页头大标题，避免抽屉内嵌套整套 Admin 壳。
 *
 * 对齐 Eav / Ai Provider：layoutType=default.blank（含 Theme head / Weline.UI）。
 */
trait CdnBackendIframeTrait
{
    protected function applyCdnIframeBlankLayout(): bool
    {
        if (!$this->isCdnIframeEmbedRequest()) {
            return false;
        }

        $this->layoutType = 'default.blank';
        $this->assign('layoutShowPageHeader', false);
        $this->assign('cdn_iframe_embed', true);

        $meta = $this->getTemplate()->getData('meta');
        $meta = \is_array($meta) ? $meta : [];
        $meta['showHeader'] = false;
        $meta['showSidebar'] = false;
        $meta['showFooter'] = false;
        $meta['showPageHeader'] = false;
        $this->assign('meta', $meta);

        return true;
    }

    /**
     * OffCanvas iframe / embed 判定（查询参数 + Sec-Fetch-Dest）。
     */
    protected function isCdnIframeEmbedRequest(): bool
    {
        if ($this->request->isIframe()) {
            return true;
        }

        $embed = (string)$this->request->getParam('embed');
        $isIframe = (string)$this->request->getParam('isIframe');
        if ($embed === '1' || $isIframe === 'true' || $isIframe === '1') {
            return true;
        }

        $fetchDest = strtolower(trim((string)($this->request->getHeader('Sec-Fetch-Dest') ?? '')));

        return $fetchDest === 'iframe';
    }

    /**
     * OffCanvas iframe 成功/失败桥接重定向。
     *
     * 必须走后台 Offcanvas 控制器路径（与 Websites Website 表单一致）：
     * `component/backend/offcanvas/getSuccess|getError`。
     * 旧路径 `component/offcanvas/success|error` 在后台 token 下会落到店面 404。
     *
     * @param array<string, scalar> $params
     */
    protected function redirectCdnOffcanvasResult(string $type, string $message, bool $reload = false): string
    {
        $path = $type === 'error'
            ? 'component/backend/offcanvas/getError'
            : 'component/backend/offcanvas/getSuccess';

        return (string)$this->redirect($path, [
            'msg' => $message,
            'reload' => $reload ? 1 : 0,
        ]);
    }
}
