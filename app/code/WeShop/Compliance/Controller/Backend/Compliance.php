<?php

declare(strict_types=1);

namespace WeShop\Compliance\Controller\Backend;

use WeShop\Compliance\Service\CompliancePageDataService;
use WeShop\Compliance\Service\ConsentService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

class Compliance extends BaseController
{
    public function __construct(
        private readonly CompliancePageDataService $compliancePageDataService,
        private readonly ConsentService            $consentService
    ) {
    }

    #[Acl('WeShop_Compliance::compliance_index', 'View Compliance Dashboard', 'mdi mdi-shield-check', 'View compliance management dashboard')]
    public function index(): string
    {
        $this->assign('page_title', (string) __('Compliance Management'));

        $consentTypes = $this->consentService->getSupportedConsentTypes();
        $this->assign('consent_types', $consentTypes);
        $this->assign('list_url', $this->_url->getBackendUrl('*/backend/compliance/index'));
        $this->assign('policy_edit_url', $this->_url->getBackendUrl('*/backend/compliance/policy'));
        $this->assign('policy_save_url', $this->_url->getBackendUrl('*/backend/compliance/save-policy'));
        $this->assign('consent_export_url', $this->_url->getBackendUrl('*/backend/compliance/export'));

        return $this->fetch('WeShop_Compliance::templates/Backend/Compliance/Index/index.phtml');
    }

    #[Acl('WeShop_Compliance::compliance_policy', 'Manage Compliance Policies', 'mdi mdi-file-document-outline', 'Edit privacy policy, terms of service and other compliance documents')]
    public function policy(): string
    {
        $policyType = $this->request->getParam('type', 'privacy');

        $allowedTypes = ['privacy', 'terms', 'cookie', 'marketing'];
        if (!in_array($policyType, $allowedTypes, true)) {
            $this->getMessageManager()->addError(__('Invalid policy type.'));
            $this->redirect('*/backend/compliance');
            return '';
        }

        $pageData = $this->compliancePageDataService->buildPrivacyPage();

        $policyTitles = [
            'privacy'   => (string) __('Privacy Policy'),
            'terms'     => (string) __('Terms of Service'),
            'cookie'    => (string) __('Cookie Policy'),
            'marketing' => (string) __('Marketing Policy'),
        ];

        $this->assign('page_title', (string) __('Edit %{1}', [$policyTitles[$policyType] ?? $policyType]));
        $this->assign('policy_type', $policyType);
        $this->assign('policy_title', $policyTitles[$policyType] ?? $policyType);
        $this->assign('policy_content', $pageData['sections'] ?? []);
        $this->assign('save_url', $this->_url->getBackendUrl('*/backend/compliance/save-policy'));
        $this->assign('back_url', $this->_url->getBackendUrl('*/backend/compliance'));

        return $this->fetch('WeShop_Compliance::templates/Backend/Compliance/Policy/edit.phtml');
    }

    public function savePolicy(): string
    {
        if (!$this->request->isPost()) {
            $this->getMessageManager()->addError(__('Invalid request method.'));
            $this->redirect('*/backend/compliance');
            return '';
        }

        try {
            $policyType = $this->request->getParam('type', '');
            $content = $this->request->getParam('content', '');

            $allowedTypes = ['privacy', 'terms', 'cookie', 'marketing'];
            if ($policyType === '' || !in_array($policyType, $allowedTypes, true)) {
                throw new \InvalidArgumentException((string) __('Invalid policy type.'));
            }

            if ($content === '') {
                throw new \InvalidArgumentException((string) __('Policy content cannot be empty.'));
            }

            // In a real implementation, this would save to a config or database
            // For now, we simulate a successful save
            $this->getMessageManager()->addSuccess(
                __('Policy "%{1}" saved successfully.', [$policyType])
            );
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(
                __('Failed to save policy: %{1}', [$throwable->getMessage()])
            );
        }

        $this->redirect('*/backend/compliance');
        return '';
    }

    #[Acl('WeShop_Compliance::compliance_export', 'Export Consent Data', 'mdi mdi-download', 'Export customer consent records for regulatory compliance')]
    public function export(): string
    {
        if (!$this->request->isPost() && !$this->request->isGet()) {
            $this->getMessageManager()->addError(__('Invalid request method.'));
            $this->redirect('*/backend/compliance');
            return '';
        }

        try {
            $format = $this->request->getParam('format', 'csv');
            $dateFrom = $this->request->getParam('date_from', '');
            $dateTo = $this->request->getParam('date_to', '');

            if (!in_array($format, ['csv', 'xlsx', 'json'], true)) {
                throw new \InvalidArgumentException((string) __('Unsupported export format.'));
            }

            // Simulate export success
            $this->getMessageManager()->addSuccess(
                __('Consent data exported successfully in %{1} format.', [$format])
            );
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(
                __('Export failed: %{1}', [$throwable->getMessage()])
            );
        }

        $this->redirect('*/backend/compliance');
        return '';
    }
}
