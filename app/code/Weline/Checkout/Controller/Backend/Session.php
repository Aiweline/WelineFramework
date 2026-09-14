<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller\Backend;

use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutSessionAdminPresenter;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Checkout::checkout_workspace', '结账工作台', 'clock', '结账会话与诊断', 'Weline_Backend::order_group')]
final class Session extends BackendController
{
    #[Acl('Weline_Checkout::checkout_sessions', '结账会话', 'clock', '查看真实结账冻结会话')]
    public function index(): string
    {
        $state = trim((string)$this->request->getParam('state', ''));
        $errorOnly = trim((string)$this->request->getParam('error', '')) === '1';
        $rawEntry = strtolower(trim((string)$this->request->getParam('checkout_entry', '')));
        $checkoutEntry = \in_array($rawEntry, \Weline\Checkout\Service\CheckoutEntry::codes(), true)
            ? $rawEntry
            : '';
        try {
            /** @var CheckoutSession $session */
            $session = ObjectManager::getInstance(CheckoutSession::class);
            if ($state !== '') {
                $session->where(CheckoutSession::schema_fields_STATE, $state);
            }
            if ($errorOnly) {
                $session->where(CheckoutSession::schema_fields_ERROR_CODE, null, 'is not null');
            }
            if ($checkoutEntry !== '') {
                $session->where(CheckoutSession::schema_fields_CHECKOUT_ENTRY, $checkoutEntry);
            }
            $session->pagination()->order(CheckoutSession::schema_fields_CREATED_AT, 'DESC')->select()->fetch();
            $presenter = ObjectManager::getInstance(CheckoutSessionAdminPresenter::class);
            $rows = [];
            foreach ($session->getItems() as $item) {
                if ($item instanceof CheckoutSession && $presenter instanceof CheckoutSessionAdminPresenter) {
                    $rows[] = $presenter->present($item);
                }
            }
            $this->assign('sessions', $rows);
            $this->assign('pagination', $session->getPagination());
            $this->assign('load_error', '');
        } catch (\Throwable $exception) {
            $this->assign('sessions', []);
            $this->assign('pagination', []);
            $this->assign('load_error', $exception->getMessage());
        }
        $this->assign('state', $state);
        $this->assign('error_only', $errorOnly);
        $this->assign('checkout_entry', $checkoutEntry);
        $this->assign('checkout_entry_rows', \Weline\Checkout\Service\CheckoutEntry::filterRows());
        return $this->fetch();
    }

    #[Acl('Weline_Checkout::checkout_diagnostics', '结账诊断', 'circle', '查看结账会话状态和过期诊断')]
    public function diagnostics(): string
    {
        $summaryKeys = [
            CheckoutSession::STATE_QUOTED,
            CheckoutSession::STATE_SUBMITTING,
            CheckoutSession::STATE_SUBMITTED,
            'expired',
            'unknown',
        ];
        $summary = array_fill_keys($summaryKeys, 0);
        $presenter = ObjectManager::getInstance(CheckoutSessionAdminPresenter::class);
        try {
            /** @var CheckoutSession $session */
            $session = ObjectManager::getInstance(CheckoutSession::class);
            $session->pagination()->order(CheckoutSession::schema_fields_CREATED_AT, 'DESC')->select()->fetch();
            $seenTokens = [];
            $recentRows = [];
            $now = time();
            foreach ($session->getItems() as $item) {
                if (!$item instanceof CheckoutSession || !$presenter instanceof CheckoutSessionAdminPresenter) {
                    continue;
                }
                $token = trim((string)$item->getData(CheckoutSession::schema_fields_QUOTE_TOKEN));
                if ($token !== '' && isset($seenTokens[$token])) {
                    continue;
                }
                if ($token !== '') {
                    $seenTokens[$token] = true;
                }
                $state = (string)$item->getData(CheckoutSession::schema_fields_STATE);
                ++$summary[array_key_exists($state, $summary) ? $state : 'unknown'];
                $expiresRaw = (string)$item->getData(CheckoutSession::schema_fields_EXPIRES_AT);
                $expired = false;
                if ($expiresRaw !== '') {
                    $expiresTs = strtotime($expiresRaw . ' UTC');
                    if ($expiresTs === false) {
                        $expiresTs = strtotime($expiresRaw);
                    }
                    if ($expiresTs !== false && $expiresTs < $now) {
                        $expired = true;
                        ++$summary['expired'];
                    }
                }
                $row = $presenter->present($item);
                $row['is_expired'] = $expired;
                $row['expiry_status'] = $presenter->expiryStatusLabel($expiresRaw, $now);
                $recentRows[] = $row;
            }
            $this->assign('recent_sessions', $recentRows);
            $this->assign('load_error', '');
        } catch (\Throwable $exception) {
            $this->assign('recent_sessions', []);
            $this->assign('load_error', $exception->getMessage());
        }
        $summaryTiles = [];
        if ($presenter instanceof CheckoutSessionAdminPresenter) {
            foreach ($summaryKeys as $key) {
                $summaryTiles[] = [
                    'key' => $key,
                    'label' => $presenter->summaryStateLabel($key),
                    'tone' => $presenter->summaryStateTone($key),
                    'count' => (int)($summary[$key] ?? 0),
                ];
            }
        }
        $this->assign('summary', $summary);
        $this->assign('summary_tiles', $summaryTiles);
        return $this->fetch('Weline_Checkout::templates/Backend/Session/diagnostics.phtml');
    }
}
