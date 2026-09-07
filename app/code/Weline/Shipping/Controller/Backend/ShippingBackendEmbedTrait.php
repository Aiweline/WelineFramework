<?php
declare(strict_types=1);

namespace Weline\Shipping\Controller\Backend;

/**
 * Manager Tab iframe 嵌入：保留 default.default（注入 Theme CSS），关闭壳层与页头。
 *
 * 注意：default.blank 当前不会渲染 Theme Partials head，导致 foundation/backend CSS 缺失、图标失控。
 */
trait ShippingBackendEmbedTrait
{
    protected function assignShippingEmbedLayout(): bool
    {
        $embed = ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true);
        $this->assign('embed', $embed);
        if ($embed) {
            // 必须保留 default.default，才能加载 weline-foundation / weline-backend。
            $this->layoutType = 'default.default';
            $this->assign('layoutShowPageHeader', false);
            $meta = is_array($this->getData('meta')) ? $this->getData('meta') : [];
            $meta['showHeader'] = false;
            $meta['showSidebar'] = false;
            $meta['showFooter'] = false;
            $meta['showPageHeader'] = false;
            $this->assign('meta', $meta);
        }

        return $embed;
    }
}
