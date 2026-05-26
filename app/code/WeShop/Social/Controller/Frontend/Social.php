<?php

declare(strict_types=1);

namespace WeShop\Social\Controller\Frontend;

use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Social\Service\SocialService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 前台社交分享控制器
 */
class Social extends BaseController
{
    private const CONTENT_TEMPLATE = 'WeShop_Social::templates/Frontend/Social/index.phtml';

    protected ?string $layoutType = 'social_share';

    public function __construct(
        private readonly SocialService $socialService,
        private readonly CustomerSession $customerSession
    ) {
    }

    /**
     * 社交分享首页/分享工具
     */
    public function index(): string
    {
        $targetUrl = (string) $this->request->getParam('url', '');
        $title = (string) $this->request->getParam('title', '');
        $productId = (int) $this->request->getParam('product_id', 0);

        $platforms = ['facebook', 'x', 'linkedin', 'whatsapp', 'pinterest'];
        $shareUrls = $this->socialService->getProductShareUrls($targetUrl, $title, $platforms);

        $pageTitle = $title !== '' ? $title : __('Share This Page');
        $this->assign('page_title', $pageTitle);
        $this->assign('target_url', $targetUrl);
        $this->assign('title', $title);
        $this->assign('product_id', $productId);
        $this->assign('share_urls', $shareUrls);

        return $this->fetch(self::CONTENT_TEMPLATE);
    }

    /**
     * AJAX记录分享
     */
    public function record(): string
    {
        try {
            $platform = (string) $this->request->getParam('platform', '');
            $targetUrl = (string) $this->request->getParam('url', '');
            $productId = (int) $this->request->getParam('product_id', 0);
            $affiliateShareCode = trim((string) $this->request->getParam('affiliate_share_code', ''));

            if ($platform === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('A social platform is required.'),
                ]);
            }

            $customer = $this->customerSession->getCustomer();
            $customerId = $customer && $customer->getId() ? (int) $customer->getId() : 0;

            $shareData = [
                'platform' => $platform,
                'customer_id' => $customerId,
                'product_id' => $productId,
            ];
            if ($affiliateShareCode !== '') {
                $shareData['affiliate_share_code'] = $affiliateShareCode;
            }

            $share = $this->socialService->recordShare($shareData);

            return $this->jsonResponse([
                'success' => true,
                'message' => __('Share recorded successfully.'),
                'data' => [
                    'share_id' => $share->getId(),
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * 获取商品分享数
     */
    public function counts(): string
    {
        try {
            $productId = (int) $this->request->getParam('product_id', 0);
            $platform = $this->request->getParam('platform');

            if ($productId <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('Invalid product ID.'),
                ]);
            }

            $count = $this->socialService->getShareCount($productId, $platform);

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'product_id' => $productId,
                    'platform' => $platform,
                    'count' => $count,
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
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
