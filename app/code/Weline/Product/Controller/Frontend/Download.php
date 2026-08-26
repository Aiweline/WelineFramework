<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Api\ProductDownloadEntitlementException;
use Weline\Product\Api\ProductDownloadEntitlementInterface;
use Weline\Product\Service\ProductCurrentCustomerResolver;

final class Download extends FrontendController
{
    public function __construct(
        private readonly ProductDownloadEntitlementInterface $entitlements,
        private readonly ProductCurrentCustomerResolver $customers,
    ) {
    }

    public function index(): string
    {
        try {
            $customerId = $this->customers->requireCustomerId();
            $result = $this->entitlements->consume(
                trim((string)$this->request->getParam('entitlement_uuid', '')),
                $customerId,
                RequestContext::scopeIdentity(),
                trim((string)RequestContext::getWelineUserLang()),
            );
            return (string)$this->redirect((string)$result['url']);
        } catch (ProductDownloadEntitlementException $exception) {
            $this->getMessageManager()->addError($exception->getMessage());
            if ($exception->errorCode() === 'download_customer_required') {
                return (string)$this->redirect('/customer/account/login');
            }
            return (string)$this->redirect('/customer/account');
        }
    }
}
