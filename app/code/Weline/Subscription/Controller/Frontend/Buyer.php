<?php

declare(strict_types=1);

namespace Weline\Subscription\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Runtime\RequestContext;
use Weline\Subscription\Service\SubscriptionBuyerService;
use Weline\Subscription\Service\SubscriptionConflictException;

/**
 * Buyer subscription pages. Writes go through the subscription query provider.
 *
 * @Extra type=fpc enabled=false
 */
class Buyer extends FrontendController
{
    protected ?string $layoutType = 'account.dashboard';

    public function __construct(
        private readonly SubscriptionBuyerService $buyers,
    ) {
    }

    public function getIndex(): string
    {
        if (!$this->requireLogin()) {
            return '';
        }
        $customerId = $this->customerId();
        $websiteId = RequestContext::getWelineWebsiteId();
        $subscriptions = [];
        $error = '';
        try {
            $subscriptions = $this->buyers->listForCustomer($customerId, $websiteId);
        } catch (SubscriptionConflictException $exception) {
            $error = $exception->getMessage();
        } catch (\InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
        $this->assign('screen', 'list');
        $this->assign('subscriptions', $subscriptions);
        $this->assign('providers', $this->buyers->providers());
        $this->assign('idempotency_key', 'buyer-' . bin2hex(random_bytes(16)));
        $this->assign('error', $error);
        $this->assign('page_title', __('我的订阅'));

        return $this->fetch('Weline_Subscription::templates/frontend/buyer/screen.phtml');
    }

    public function getRenew(): string
    {
        return $this->renderOwnedScreen('renew', __('续费订阅'));
    }

    public function getCancel(): string
    {
        return $this->renderOwnedScreen('cancel', __('取消订阅'));
    }

    private function renderOwnedScreen(string $screen, string $title): string
    {
        if (!$this->requireLogin()) {
            return '';
        }
        $subscriptionId = trim((string) $this->request->getParam('subscription_id', ''));
        $subscription = null;
        $error = '';
        if ($subscriptionId === '') {
            $error = (string) __('请选择要处理的订阅');
        } else {
            try {
                $subscription = $this->buyers->presentOwned($subscriptionId, $this->customerId());
            } catch (SubscriptionConflictException $exception) {
                $error = $exception->getMessage();
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }
        $this->assign('screen', $screen);
        $this->assign('subscription', $subscription);
        $this->assign('subscriptions', []);
        $this->assign('providers', []);
        $this->assign('idempotency_key', '');
        $this->assign('error', $error);
        $this->assign('page_title', $title);

        return $this->fetch('Weline_Subscription::templates/frontend/buyer/screen.phtml');
    }

    private function requireLogin(): bool
    {
        if ($this->isLoggedIn() && $this->customerId() !== '') {
            return true;
        }
        $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
        $this->redirect('/customer/account/login', ['referer' => $currentUrl]);

        return false;
    }

    private function customerId(): string
    {
        $id = $this->getLoginUserId();
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return '';
        }

        return trim((string) $id);
    }
}
