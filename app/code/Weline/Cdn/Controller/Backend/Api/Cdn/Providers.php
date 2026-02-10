<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Controller\Backend\Api\Cdn;

use Weline\Cdn\Service\ProviderManager;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;

/**
 * CDN 供应商 API 控制器
 * 为 w:select:provider 标签提供数据
 */
#[AclAttribute('Weline_Cdn::cdn_provider_api', 'CDN供应商API', 'mdi-api', 'CDN供应商API接口', 'Weline_Cdn::cdn_manager')]
class Providers extends BackendController
{
    private ProviderManager $providerManager;

    public function __construct(
        ProviderManager $providerManager
    ) {
        $this->providerManager = $providerManager;
    }

    /**
     * 获取供应商列表
     */
    #[AclAttribute('Weline_Cdn::cdn_provider_api_index', '获取CDN供应商列表', 'mdi-view-list', '获取CDN供应商列表API')]
    public function index(): string
    {
        try {
            $limit = (int)($this->request->getGet('limit') ?? 50);
            $search = trim((string)($this->request->getGet('search') ?? ''));

            $providers = $this->providerManager->getProviders();
            if ($search !== '') {
                $providers = array_filter($providers, static function (array $item) use ($search) {
                    $haystack = strtolower(($item['name'] ?? '') . ' ' . ($item['code'] ?? '') . ' ' . ($item['description'] ?? ''));
                    return str_contains($haystack, strtolower($search));
                });
            }
            $providers = array_values($providers);
            if ($limit > 0) {
                $providers = array_slice($providers, 0, $limit);
            }

            return $this->fetchJson([
                'success' => true,
                'data' => $providers,
                'total' => count($providers),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ]);
        }
    }
}
