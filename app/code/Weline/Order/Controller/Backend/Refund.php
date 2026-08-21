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

#[Acl('Weline_Order::refund_controller', '订单退款控制器', 'cash', '订单并发安全退款管理', 'Weline_Backend::order_group')]
final class Refund extends BackendController
{
    use OrderObjectAuthorizationTrait;

    private readonly OrderTradeAdminCommandService $commands;

    public function __construct(ObjectManager $objectManager)
    {
        $this->commands = $objectManager->getInstance(OrderTradeAdminCommandService::class);
    }

    #[Acl('Weline_Order::refund_manage', '查看订单退款', 'list', '查看可退款订单项和退款案例', 'Weline_Backend::order_group')]
    public function index(): string
    {
        $candidates = [];
        foreach ($this->commands->refundCandidates() as $row) {
            $grant = $this->orderActionGrant((int)$row['order_id'], ObjectAction::REFUND);
            if (!$grant['allowed']) {
                continue;
            }
            $candidates[] = $row + ['expected_grant_version' => $grant['grant_version']];
        }
        $cases = [];
        foreach ($this->commands->refundCases() as $row) {
            if ($this->orderActionGrant((int)$row['order_id'], ObjectAction::VIEW)['allowed']) {
                $cases[] = $row;
            }
        }
        $this->assign('candidates', $candidates);
        $this->assign('cases', $cases);

        return $this->fetch();
    }

    #[Acl('Weline_Order::refund_execute', '提交退款', 'cash', '锁内占用可退金额和数量并生成退款 outbox', 'Weline_Order::refund_manage')]
    public function execute(): mixed
    {
        $orderUuid = trim((string)$this->request->getPost('order_uuid', ''));
        try {
            $context = $this->commands->refundContext($orderUuid);
            $this->requireOrderSubmit((int)$context['order_id'], ObjectAction::REFUND);
            $result = $this->commands->refund(
                $orderUuid,
                (string)$this->request->getPost('item_uuid', ''),
                (int)$this->request->getPost('qty_minor', 0),
                (int)$this->request->getPost('shipping_refund_minor', 0),
                (string)$this->request->getPost('reason', ''),
                (string)$this->request->getPost('idempotency_key', ''),
            );
            $this->getMessageManager()->addSuccess((string)__(
                !empty($result['replayed']) ? '退款命令已幂等重放' : '退款申请已安全占额并进入处理队列',
            ));
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (OrderTradeAdminCommandException $exception) {
            $this->getMessageManager()->addError($exception->errorCode());
        } catch (\Throwable) {
            $this->getMessageManager()->addError((string)__('退款操作失败，请稍后重试。'));
        }

        return $this->redirect('weline_order/backend/refund/index');
    }
}
