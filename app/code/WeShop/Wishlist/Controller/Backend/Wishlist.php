<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Controller\Backend;

use WeShop\Customer\Model\Customer;
use WeShop\Wishlist\Model\Wishlist as WishlistModel;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;

class Wishlist extends BaseController
{
    public function __construct(
        private readonly WishlistService $wishlistService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $customerId = (int) $this->request->getParam('customer_id', 0);
        $search = $this->request->getParam('search', '');

        /** @var WishlistModel $wishlistModel */
        $wishlistModel = ObjectManager::getInstance(WishlistModel::class);

        $totalCount = $wishlistModel->clear()->count();

        $wishlists = $wishlistModel->clear()->select();
        if ($customerId > 0) {
            $wishlists = $wishlists->where(WishlistModel::schema_fields_CUSTOMER_ID, $customerId);
        }
        if ($search) {
            $wishlists = $wishlists->where(WishlistModel::schema_fields_PRODUCT_ID, (int) $search, '=', 'OR', true);
        }
        $wishlists = $wishlists->order(WishlistModel::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize)
            ->fetch();

        $wishlistItems = [];
        foreach ($wishlists as $item) {
            if (is_array($item)) {
                $itemArray = $item;
            } elseif (is_object($item) && method_exists($item, 'getData')) {
                /** @var array<string, mixed> $itemArray */
                $itemArray = $item->getData();
            } else {
                // Guard against unexpected scalar rows returned by ORM/runtime.
                continue;
            }
            $wishlistCustomerId = (int) ($itemArray[WishlistModel::schema_fields_CUSTOMER_ID] ?? 0);
            $productId = (int) ($itemArray[WishlistModel::schema_fields_PRODUCT_ID] ?? 0);

            $customerName = '';
            if ($wishlistCustomerId > 0) {
                /** @var Customer $customer */
                $customer = ObjectManager::getInstance(Customer::class);
                $customer->load($wishlistCustomerId);
                if ($customer->getId()) {
                    $customerName = $customer->getFullName();
                }
            }

            $wishlistItems[] = [
                'wishlist_id' => (int) ($itemArray[WishlistModel::schema_fields_ID] ?? 0),
                'customer_id' => $wishlistCustomerId,
                'customer_name' => $customerName,
                'product_id' => $productId,
                'created_at' => $itemArray[WishlistModel::schema_fields_CREATED_AT] ?? '',
                'updated_at' => $itemArray[WishlistModel::schema_fields_UPDATED_AT] ?? '',
            ];
        }

        $paginationQuery = $wishlistModel->clear()->select();
        if ($customerId > 0) {
            $paginationQuery = $paginationQuery->where(WishlistModel::schema_fields_CUSTOMER_ID, $customerId);
        }
        $pagination = $paginationQuery->order(WishlistModel::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize)
            ->fetch()
            ->getPaginationHtml();

        $this->assign([
            'title' => (string) __('Wishlist Management'),
            'wishlistIndexUrl' => $this->_url->getBackendUrl('*/backend/wishlist'),
            'wishlistItems' => $wishlistItems,
            'total_count' => $totalCount,
            'pagination' => $pagination,
            'current_customer_id' => $customerId,
            'current_search' => $search,
        ]);

        return $this->fetchBase();
    }

    public function view(): string
    {
        $customerId = (int) $this->request->getParam('customer_id', 0);

        if ($customerId <= 0) {
            $this->redirect($this->_url->getBackendUrl('*/backend/wishlist'));
            return '';
        }

        /** @var Customer $customer */
        $customer = ObjectManager::getInstance(Customer::class);
        $customer->load($customerId);

        if (!$customer->getId()) {
            $this->redirect($this->_url->getBackendUrl('*/backend/wishlist'));
            return '';
        }

        $wishlistItems = $this->wishlistService->getCustomerWishlist($customerId);

        $this->assign([
            'title' => (string) __('Customer Wishlist'),
            'customer' => [
                'customer_id' => (int) $customer->getId(),
                'full_name' => $customer->getFullName(),
                'email' => (string) ($customer->getData(Customer::schema_fields_EMAIL) ?? ''),
            ],
            'wishlist_items' => $wishlistItems,
            'wishlist_count' => count($wishlistItems),
            'back_url' => $this->_url->getBackendUrl('*/backend/wishlist'),
        ]);

        return $this->fetchBase();
    }

    public function delete(): string
    {
        $wishlistId = (int) $this->request->getParam('wishlist_id', 0);
        $customerId = (int) $this->request->getParam('customer_id', 0);

        if ($wishlistId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => (string) __('Wishlist item ID is required.')]);
        }

        try {
            $result = $this->wishlistService->removeFromWishlist($wishlistId, $customerId);

            if ($result) {
                return $this->fetchJson(['success' => true, 'message' => (string) __('Removed from wishlist.')]);
            } else {
                return $this->fetchJson(['success' => false, 'message' => (string) __('Failed to remove from wishlist.')]);
            }
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
