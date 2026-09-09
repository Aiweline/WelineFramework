<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\ShippingConfigScopeService;

/**
 * 配送后台作用范围：URL target_scope + 页头 <w:scope>。
 */
trait ShippingBackendScopeTrait
{
    /**
     * @return array{scope_type:string,scope_id:int,storage_scope:string,website_code:string,store_code:string,channel_code:string,kind:string}
     */
    protected function assignShippingWorkScope(bool $fromPost = false): array
    {
        /** @var ShippingConfigScopeService $scopeSvc */
        $scopeSvc = ObjectManager::getInstance(ShippingConfigScopeService::class);
        $input = $fromPost
            ? [
                'target_scope' => (string)$this->request->getPost('target_scope', ''),
                'scope' => (string)$this->request->getPost('scope', ''),
                'website_code' => (string)$this->request->getPost('website_code', ''),
                'store_code' => (string)$this->request->getPost('store_code', ''),
                'channel_code' => (string)$this->request->getPost('channel_code', ''),
                'scope_kind' => (string)$this->request->getPost('scope_kind', ''),
                'scope_type' => (string)$this->request->getPost('scope_type', ''),
                'scope_id' => (string)$this->request->getPost('scope_id', ''),
            ]
            : [
                'target_scope' => (string)$this->request->getGet('target_scope', ''),
                'scope' => (string)$this->request->getGet('scope', ''),
                'website_code' => (string)$this->request->getGet('website_code', ''),
                'store_code' => (string)$this->request->getGet('store_code', ''),
                'channel_code' => (string)$this->request->getGet('channel_code', ''),
                'scope_kind' => (string)$this->request->getGet('scope_kind', ''),
            ];

        $hasExplicit = trim((string)($input['target_scope'] ?? '')) !== ''
            || trim((string)($input['scope'] ?? '')) !== ''
            || trim((string)($input['scope_kind'] ?? '')) !== ''
            || array_key_exists('website_code', $fromPost ? (array)$this->request->getPost() : (array)$this->request->getGet());

        $target = $scopeSvc->resolveAdminTarget($input, !$hasExplicit && !$fromPost);

        $this->assign('selected_scope', $target['storage_scope']);
        $this->assign('target_scope', $target['storage_scope']);
        $this->assign('scope_website_code', $target['website_code']);
        $this->assign('scope_store_code', $target['store_code']);
        $this->assign('scope_channel_code', $target['channel_code']);
        $this->assign('scope_kind', $target['kind']);
        $this->assign('work_scope_type', $target['scope_type']);
        $this->assign('work_scope_id', $target['scope_id']);

        return $target;
    }

    /**
     * @param array{storage_scope:string,website_code:string,store_code:string,channel_code:string} $target
     * @return array<string, string>
     */
    protected function shippingScopeQuery(array $target): array
    {
        return [
            'target_scope' => (string)$target['storage_scope'],
            'website_code' => (string)$target['website_code'],
            'store_code' => (string)$target['store_code'],
            'channel_code' => (string)$target['channel_code'],
            'scope_kind' => (string)($target['kind'] ?? ''),
        ];
    }

    /**
     * 无显式范围时 302 规范化到带 target_scope 的可分享 URL。
     */
    protected function redirectUnlessShippingScopeExplicit(string $backendPath): ?string
    {
        $hasExplicit = trim((string)$this->request->getGet('target_scope', '')) !== ''
            || trim((string)$this->request->getGet('scope', '')) !== ''
            || array_key_exists('website_code', (array)$this->request->getGet());
        if ($hasExplicit) {
            return null;
        }
        $target = $this->assignShippingWorkScope(false);

        return $this->redirect($backendPath, $this->shippingScopeQuery($target));
    }
}
