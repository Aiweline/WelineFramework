<?php

declare(strict_types=1);

namespace WeShop\Social\Controller\Backend;

use WeShop\Social\Service\SocialService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 后台社交配置控制器
 */
class Social extends BackendController
{
    private const CONTENT_TEMPLATE = 'WeShop_Social::templates/Backend/Social/Config/index.phtml';

    /**
     * @var SocialService
     */
    private SocialService $socialService;

    public function __construct()
    {
        $this->socialService = ObjectManager::getInstance(SocialService::class);
    }

    /**
     * Set social service for testing
     *
     * @param SocialService $socialService
     * @return void
     */
    public function setSocialService(SocialService $socialService): void
    {
        $this->socialService = $socialService;
    }

    /**
     * 社交配置首页
     */
    public function index(): string
    {
        $footerLinks = $this->socialService->getFooterSocialLinks();

        $platforms = [
            'facebook' => [
                'label' => 'Facebook',
                'icon' => 'facebook',
                'config_key' => 'social.links.facebook',
            ],
            'instagram' => [
                'label' => 'Instagram',
                'icon' => 'instagram',
                'config_key' => 'social.links.instagram',
            ],
            'x' => [
                'label' => 'X (Twitter)',
                'icon' => 'x',
                'config_key' => 'social.links.x',
            ],
            'youtube' => [
                'label' => 'YouTube',
                'icon' => 'youtube',
                'config_key' => 'social.links.youtube',
            ],
            'tiktok' => [
                'label' => 'TikTok',
                'icon' => 'tiktok',
                'config_key' => 'social.links.tiktok',
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'icon' => 'linkedin',
                'config_key' => 'social.links.linkedin',
            ],
        ];

        $pageTitle = (string) __('Social Configuration');
        $saveUrl = $this->getUrl('weshop/social/save');

        $this->assign('page_title', $pageTitle);
        $this->assign('save_url', $saveUrl);
        $this->assign('footer_links', $footerLinks);
        $this->assign('platforms', $platforms);

        return $this->fetch(self::CONTENT_TEMPLATE);
    }

    /**
     * 保存社交配置
     */
    public function save(): string
    {
        $links = $this->request->getPost('links', []);

        if (!\is_array($links)) {
            MessageManager::error(__('Invalid social link configuration.'));
            return $this->redirect('*/*/index');
        }

        try {
            foreach ($links as $platform => $url) {
                $url = trim((string) $url);
                $configKey = "social.links.{$platform}";

                if ($url !== '') {
                    State::getInstance()->setConfig($configKey, $url);
                }
            }

            MessageManager::success(__('Social links configuration saved successfully.'));
        } catch (\Throwable $e) {
            MessageManager::error(__('Failed to save configuration: %1', $e->getMessage()));
        }

        return $this->redirect('*/*/index');
    }

    /**
     * 获取分享统计
     */
    public function stats(): string
    {
        try {
            $productId = (int) $this->request->getParam('product_id', 0);

            if ($productId <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('Invalid product ID.'),
                ]);
            }

            $platforms = ['facebook', 'x', 'linkedin', 'whatsapp', 'pinterest'];
            $stats = [];

            foreach ($platforms as $platform) {
                $stats[$platform] = $this->socialService->getShareCount($productId, $platform);
            }

            $total = array_sum($stats);

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'product_id' => $productId,
                    'total' => $total,
                    'by_platform' => $stats,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * JSON响应
     *
     * @param array<string, mixed> $data
     * @return string
     */
    protected function jsonResponse(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
