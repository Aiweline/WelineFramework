<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller\Backend;

use Weline\Checkout\Model\CheckoutSession;
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
        try {
            /** @var CheckoutSession $session */
            $session = ObjectManager::getInstance(CheckoutSession::class);
            if ($state !== '') {
                $session->where(CheckoutSession::schema_fields_STATE, $state);
            }
            $session->pagination()->order(CheckoutSession::schema_fields_CREATED_AT, 'DESC')->select()->fetch();
            $this->assign('sessions', $session->getItems());
            $this->assign('pagination', $session->getPagination());
            $this->assign('load_error', '');
        } catch (\Throwable $exception) {
            $this->assign('sessions', []);
            $this->assign('pagination', []);
            $this->assign('load_error', $exception->getMessage());
        }
        $this->assign('state', $state);
        return $this->fetch();
    }

    #[Acl('Weline_Checkout::checkout_diagnostics', '结账诊断', 'circle', '查看结账会话状态和过期诊断')]
    public function diagnostics(): string
    {
        $summary = [
            CheckoutSession::STATE_QUOTED => 0,
            CheckoutSession::STATE_SUBMITTING => 0,
            CheckoutSession::STATE_SUBMITTED => 0,
            'expired' => 0,
            'unknown' => 0,
        ];
        try {
            /** @var CheckoutSession $session */
            $session = ObjectManager::getInstance(CheckoutSession::class);
            $session->pagination()->order(CheckoutSession::schema_fields_CREATED_AT, 'DESC')->select()->fetch();
            $recentSessions = $session->getItems();
            $now = time();
            foreach ($recentSessions as $item) {
                $state = (string)$item->getData(CheckoutSession::schema_fields_STATE);
                ++$summary[array_key_exists($state, $summary) ? $state : 'unknown'];
                $expiresAt = (string)$item->getData(CheckoutSession::schema_fields_EXPIRES_AT);
                if ($expiresAt !== '' && strtotime($expiresAt) < $now) {
                    ++$summary['expired'];
                }
            }
            $this->assign('recent_sessions', $recentSessions);
            $this->assign('load_error', '');
        } catch (\Throwable $exception) {
            $this->assign('recent_sessions', []);
            $this->assign('load_error', $exception->getMessage());
        }
        $this->assign('summary', $summary);
        return $this->fetch('Weline_Checkout::templates/Backend/Session/diagnostics.phtml');
    }
}
