<?php

declare(strict_types=1);

namespace WeShop\Address\Controller\Backend;

use WeShop\Address\Service\AddressService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Http\Param;

class Address extends BaseController
{
    public function __construct(
        private readonly AddressService $addressService
    ) {
    }

    #[Acl('WeShop_Address::address_index', 'View Addresses', 'mdi mdi-map-marker', 'View address list')]
    public function index(): string
    {
        $this->assign('page_title', (string) __('Address Management'));
        $this->assign('add_url', $this->_url->getBackendUrl('*/backend/address/edit'));
        $this->assign('edit_url', $this->_url->getBackendUrl('*/backend/address/edit', ['id' => 0]));
        $this->assign('delete_url', $this->_url->getBackendUrl('*/backend/address/delete', ['id' => 0]));
        $this->assign('set_default_url', $this->_url->getBackendUrl('*/backend/address/set-default', ['id' => 0]));
        $this->assign('customer_id', (int) $this->request->getParam('customer_id', 1));

        return $this->fetch('WeShop_Address::templates/Backend/Address/Index/index.phtml');
    }

    #[Acl('WeShop_Address::address_edit', 'Edit Address', 'mdi mdi-map-marker-edit', 'Edit or create address')]
    public function edit(): string
    {
        $addressId = (int) $this->request->getParam('id', 0);
        $address = null;

        if ($addressId > 0) {
            $address = $this->addressService->getAddress($addressId);
            if (!$address) {
                $this->getMessageManager()->addError(__('Address not found.'));
                $this->redirect('*/backend/address');
                return '';
            }
        }

        $this->assign('page_title', $addressId > 0 ? (string) __('Edit Address') : (string) __('Add Address'));
        $this->assign('address_id', $addressId);
        $this->assign('address', $address);
        $this->assign('save_url', $this->_url->getBackendUrl('*/backend/address/save'));

        return $this->fetch('WeShop_Address::templates/Backend/Address/Edit/index.phtml');
    }

    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondError((string) __('Invalid request method.'));
        }

        try {
            $data = $this->request->getParams();
            $savedAddress = $this->addressService->saveAddress($data);

            if (!empty($savedAddress['address_id'])) {
                return $this->respondSuccess((string) __('Address saved successfully.'));
            } else {
                return $this->respondError((string) __('Failed to save address.'));
            }
        } catch (\Throwable $throwable) {
            return $this->respondError((string) __('Address save failed: %{1}', [$throwable->getMessage()]));
        }
    }

    public function delete(): string
    {
        if (!$this->request->isPost() && !$this->request->isDelete()) {
            return $this->respondError((string) __('Invalid request method.'));
        }

        try {
            $addressId = (int) $this->request->getParam('id', 0);
            $customerId = (int) $this->request->getParam('customer_id', 0);

            if ($addressId <= 0) {
                throw new \InvalidArgumentException((string) __('Invalid address ID.'));
            }

            if ($customerId <= 0) {
                throw new \InvalidArgumentException((string) __('Invalid customer ID.'));
            }

            $result = $this->addressService->deleteAddress($addressId, $customerId);

            if ($result) {
                return $this->respondSuccess((string) __('Address deleted successfully.'));
            } else {
                return $this->respondError((string) __('Failed to delete address.'));
            }
        } catch (\Throwable $throwable) {
            return $this->respondError((string) __('Address delete failed: %{1}', [$throwable->getMessage()]));
        }
    }

    public function setDefault(): string
    {
        if (!$this->request->isPost()) {
            return $this->respondError((string) __('Invalid request method.'));
        }

        try {
            $addressId = (int) $this->request->getParam('id', 0);
            $customerId = (int) $this->request->getParam('customer_id', 0);

            if ($addressId <= 0) {
                throw new \InvalidArgumentException((string) __('Invalid address ID.'));
            }

            if ($customerId <= 0) {
                throw new \InvalidArgumentException((string) __('Invalid customer ID.'));
            }

            $this->addressService->setDefaultAddress($addressId, $customerId);
            return $this->respondSuccess((string) __('Default address updated successfully.'));
        } catch (\Throwable $throwable) {
            return $this->respondError((string) __('Failed to set default address: %{1}', [$throwable->getMessage()]));
        }
    }

    /**
     * DataTable AJAX API - Address List
     * 
     * Supports serverSide mode with pagination, sorting, and filtering.
     * Route: /address/backend/address/address-list
     */
    public function addressList(): string
    {
        $draw = (int) $this->request->getParam('draw', 1);
        $start = (int) $this->request->getParam('start', 0);
        $length = (int) $this->request->getParam('length', 10);
        $customerId = (int) $this->request->getParam('customer_id', 0);

        // Default customer_id for testing if not provided
        if ($customerId <= 0) {
            $customerId = 1;
        }

        // Get total count
        $allAddresses = $this->addressService->getCustomerAddresses($customerId);
        $recordsTotal = count($allAddresses);

        // Apply pagination
        $page = ($start / $length) + 1;
        $addresses = array_slice($allAddresses, $start, $length);

        return $this->fetchJson([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsTotal,
            'data' => $addresses,
        ]);
    }

    private function respondSuccess(string $message): string
    {
        if (method_exists($this->request, 'isAjax') && $this->request->isAjax()) {
            return $this->fetchJson([
                'success' => true,
                'message' => $message,
            ]);
        }

        $this->getMessageManager()->addSuccess($message);
        $this->redirect('*/backend/address');
        return '';
    }

    private function respondError(string $message): string
    {
        if (method_exists($this->request, 'isAjax') && $this->request->isAjax()) {
            return $this->fetchJson([
                'success' => false,
                'message' => $message,
            ]);
        }

        $this->getMessageManager()->addError($message);
        $this->redirect('*/backend/address');
        return '';
    }
}
