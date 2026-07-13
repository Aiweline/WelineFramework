<?php
declare(strict_types=1);

namespace Weline\Server\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl(
    'Weline_Server::wls_panel_marketplace_legacy',
    'Legacy WLS Plugin Marketplace Redirect',
    'mdi-storefront-outline',
    'Redirect the old WLS marketplace entry to the standalone WLS Panel marketplace.',
    'Weline_Server::wls_panel',
    accessMode: Acl::ACCESS_MODE_READ
)]
class PanelMarketplace extends BackendController
{
    private const ALLOWED_QUERY_KEYS = [
        'tag',
        'type',
        'surface',
        'query',
        'q',
        'keyword',
        'panel_notice',
        'panel_auto_refresh',
    ];

    public function getIndex(): string
    {
        $url = $this->request->getUrlBuilder()->getBackendUrl(
            '*/backend/wls-panel/marketplace',
            $this->filterMarketplaceQuery((array)$this->request->getGet())
        );
        $this->request->getResponse()->redirect($url);
        return '';
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function filterMarketplaceQuery(array $query): array
    {
        $params = [];
        foreach (self::ALLOWED_QUERY_KEYS as $key) {
            $value = $query[$key] ?? null;
            if (\is_array($value) || \is_object($value)) {
                continue;
            }

            $value = \trim((string)$value);
            if ($value === '') {
                continue;
            }

            $params[$key] = \substr($value, 0, 160);
        }

        return $params;
    }
}
