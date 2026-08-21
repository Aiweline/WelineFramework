<?php

declare(strict_types=1);

namespace Weline\Order\Controller\Backend;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Order\Service\OrderTradeAdminCommandException;
use Weline\Order\Service\OrderTradeAdminCommandService;

#[Acl('Weline_Order::invoice_controller', '订单发票控制器', 'file', '支付 effect 驱动的订单发票管理', 'Weline_Backend::order_group')]
final class Invoice extends BackendController
{
    use OrderObjectAuthorizationTrait;

    private readonly OrderTradeAdminCommandService $commands;

    public function __construct(ObjectManager $objectManager)
    {
        $this->commands = $objectManager->getInstance(OrderTradeAdminCommandService::class);
    }

    #[Acl('Weline_Order::invoice_manage', '查看订单发票', 'list', '查看支付 effect 和最小发票', 'Weline_Backend::order_group')]
    public function index(): string
    {
        $candidates = [];
        foreach ($this->commands->invoiceCandidates() as $row) {
            $grant = $this->orderActionGrant((int)$row['order_id'], ObjectAction::UPDATE);
            if (!$grant['allowed']) {
                continue;
            }
            $candidates[] = $row + ['expected_grant_version' => $grant['grant_version']];
        }
        $invoices = [];
        foreach ($this->commands->invoices() as $row) {
            if ($this->orderActionGrant((int)$row['order_id'], ObjectAction::VIEW)['allowed']) {
                $invoices[] = $row;
            }
        }
        $this->assign('candidates', $candidates);
        $this->assign('invoices', $invoices);

        return $this->fetch();
    }

    #[Acl('Weline_Order::invoice_execute', '处理开票 effect', 'plus', '在 Payment outbox 事务内幂等生成最小发票', 'Weline_Order::invoice_manage')]
    public function execute(): mixed
    {
        $outboxCode = trim((string)$this->request->getPost('outbox_code', ''));
        try {
            $context = $this->commands->invoiceContext($outboxCode);
            $this->requireOrderSubmit((int)$context['order_id'], ObjectAction::UPDATE);
            $result = $this->commands->invoice($outboxCode);
            $this->getMessageManager()->addSuccess((string)__(
                !empty($result['replayed']) ? '开票 effect 已幂等重放' : '支付 effect 已完成开票',
            ));
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (OrderTradeAdminCommandException $exception) {
            $this->getMessageManager()->addError($exception->errorCode());
        } catch (\Throwable) {
            $this->getMessageManager()->addError((string)__('开票操作失败，请稍后重试。'));
        }

        return $this->redirect('weline_order/backend/invoice/index');
    }
}
