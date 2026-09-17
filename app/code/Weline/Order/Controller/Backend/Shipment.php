<?php

declare(strict_types=1);

namespace Weline\Order\Controller\Backend;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Order\Api\OrderShippingFulfillmentGatewayInterface;
use Weline\Order\Service\OrderTradeAdminCommandException;
use Weline\Order\Service\OrderTradeAdminCommandService;

#[Acl('Weline_Order::shipment_controller', '订单发货控制器', 'truck', '订单仓维履约管理', 'Weline_Backend::order_group')]
final class Shipment extends BackendPageController
{
    use OrderObjectAuthorizationTrait;

    private readonly OrderTradeAdminCommandService $commands;

    public function __construct(ObjectManager $objectManager)
    {
        $this->commands = $objectManager->getInstance(OrderTradeAdminCommandService::class);
    }

    #[Acl('Weline_Order::shipment_manage', '查看订单发货', 'list', '查看可履约单元和进度', 'Weline_Backend::order_group')]
    public function index(): string
    {
        $candidates = [];
        foreach ($this->commands->shipmentCandidates() as $row) {
            $grant = $this->orderActionGrant((int)$row['order_id'], ObjectAction::FULFILL);
            if (!$grant['allowed']) {
                continue;
            }
            $candidates[] = $row + ['expected_grant_version' => $grant['grant_version']];
        }
        $progress = [];
        foreach ($this->commands->shipmentProgress() as $row) {
            if ($this->orderActionGrant((int)$row['order_id'], ObjectAction::VIEW)['allowed']) {
                $progress[] = $row;
            }
        }
        $this->assign('candidates', $candidates);
        $this->assign('progress', $progress);
        $meta = $candidates !== []
            ? $this->commands->shipmentPanelMeta((int)$candidates[0]['order_id'])
            : ['shipping_ref' => [], 'tracking_carriers' => [], 'label_services' => []];
        $this->assign('shipping_ref', $meta['shipping_ref'] ?? []);
        $this->assign('tracking_carriers', $meta['tracking_carriers'] ?? []);
        $this->assign('label_services', $meta['label_services'] ?? []);

        return $this->fetch();
    }

    #[Acl('Weline_Order::shipment_execute', '提交发货', 'truck', '按仓维 CAS 提交部分或全部发货', 'Weline_Order::shipment_manage')]
    public function execute(): mixed
    {
        $action = strtolower(trim((string)$this->request->getPost('shipment_action', 'ship')));
        if ($action === 'update_tracking') {
            return $this->handleUpdateTracking();
        }
        if ($action === 'set_channel') {
            return $this->handleSetChannel();
        }

        $unitUuid = trim((string)$this->request->getPost('fulfillment_unit_uuid', ''));
        try {
            $context = $this->commands->shipmentContext($unitUuid);
            $this->requireOrderSubmit((int)$context['order_id'], ObjectAction::FULFILL);
            $fulfillMode = strtolower(trim((string)$this->request->getPost('fulfill_mode', 'manual')));
            $tracking = trim((string)$this->request->getPost('tracking_number', ''));
            $notify = $this->request->getPost('notify_customer') !== null
                && (string)$this->request->getPost('notify_customer') !== '0'
                && (string)$this->request->getPost('notify_customer') !== '';
            $result = $this->commands->ship(
                $unitUuid,
                (int)$this->request->getPost('qty_minor', 0),
                (int)$this->request->getPost('expected_version', -1),
                (string)$this->request->getPost('idempotency_key', ''),
                [
                    'fulfill_mode' => $fulfillMode === 'label' ? 'label' : 'manual',
                    'tracking_number' => $tracking,
                    'carrier_id' => (int)$this->request->getPost('carrier_id', 0),
                    'carrier_name' => (string)$this->request->getPost('carrier_name', ''),
                    'carrier' => (string)$this->request->getPost('carrier', ''),
                    'service_code' => (string)$this->request->getPost('service_code', ''),
                    'weight_grams' => (int)$this->request->getPost('weight_grams', 0),
                    'notify_customer' => $notify,
                ],
            );
            $this->flashShipResult($result, $tracking, $notify);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (OrderTradeAdminCommandException $exception) {
            $this->getMessageManager()->addError($this->humanizeShipError($exception->errorCode()));
        } catch (\Throwable) {
            $this->getMessageManager()->addError((string)__('发货操作失败，请稍后重试。'));
        }

        return $this->redirectBack();
    }

    /** @deprecated Prefer execute + shipment_action=update_tracking (registered route). */
    #[Acl('Weline_Order::shipment_execute', '补充物流单号', 'truck', '后补运单号', 'Weline_Order::shipment_manage')]
    public function updateTracking(): mixed
    {
        return $this->handleUpdateTracking();
    }

    /** @deprecated Prefer execute + shipment_action=set_channel (registered route). */
    #[Acl('Weline_Order::shipment_execute', '切换履约通道', 'truck', '商家自履约或物流商履约', 'Weline_Order::shipment_manage')]
    public function setChannel(): mixed
    {
        return $this->handleSetChannel();
    }

    private function handleUpdateTracking(): mixed
    {
        try {
            $shipmentId = (int)$this->request->getPost('shipment_id', 0);
            $shipment = ObjectManager::getInstance(\Weline\Order\Model\OrderShipment::class)->load($shipmentId);
            if (!$shipment->getId()) {
                throw new OrderTradeAdminCommandException('shipment_not_found');
            }
            $orderId = (int)$shipment->getData(\Weline\Order\Model\OrderShipment::schema_fields_ORDER_ID);
            $this->requireOrderSubmit($orderId, ObjectAction::FULFILL);
            $notify = $this->request->getPost('notify_customer') !== null
                && (string)$this->request->getPost('notify_customer') !== '0';
            $result = $this->commands->updateShipmentTracking(
                $shipmentId,
                (string)$this->request->getPost('tracking_number', ''),
                (string)$this->request->getPost('carrier', ''),
                $notify,
            );
            if (!empty($result['mail_sent'])) {
                $this->getMessageManager()->addSuccess((string)__('物流单号已更新，并已尝试通知客户'));
            } else {
                $this->getMessageManager()->addSuccess((string)__('物流单号已更新'));
            }
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (OrderTradeAdminCommandException $exception) {
            $this->getMessageManager()->addError($this->humanizeShipError($exception->errorCode()));
        } catch (\Throwable) {
            $this->getMessageManager()->addError((string)__('更新物流单号失败'));
        }

        return $this->redirectBack();
    }

    private function handleSetChannel(): mixed
    {
        try {
            $orderId = (int)$this->request->getPost('order_id', 0);
            $this->requireOrderSubmit($orderId, ObjectAction::FULFILL);
            $channel = (string)$this->request->getPost(
                'fulfillment_channel',
                OrderShippingFulfillmentGatewayInterface::CHANNEL_MERCHANT,
            );
            $this->commands->setOrderFulfillmentChannel($orderId, $channel);
            $this->getMessageManager()->addSuccess(
                $channel === OrderShippingFulfillmentGatewayInterface::CHANNEL_PROVIDER
                    ? (string)__('已交由物流商履约（发货通道已锁定）')
                    : (string)__('已改回商家自履约'),
            );
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (OrderTradeAdminCommandException $exception) {
            $this->getMessageManager()->addError($this->humanizeShipError($exception->errorCode()));
        } catch (\Throwable) {
            $this->getMessageManager()->addError((string)__('切换履约通道失败'));
        }

        return $this->redirectBack();
    }

    /** @param array<string,mixed> $result */
    private function flashShipResult(array $result, string $tracking, bool $notifyRequested): void
    {
        if (!empty($result['replayed'])) {
            $this->getMessageManager()->addSuccess((string)__('发货命令已幂等重放'));

            return;
        }
        $logistics = \is_array($result['logistics'] ?? null) ? $result['logistics'] : [];
        if (!empty($logistics['mail_sent'])) {
            $this->getMessageManager()->addSuccess(
                (string)__('发货已提交；已按勾选尝试发送「已发货」邮件给客户'),
            );

            return;
        }
        if ($tracking === '' && $notifyRequested) {
            $this->getMessageManager()->addSuccess(
                (string)__('发货已提交（无运单号，未发送发货邮件；可稍后补充）'),
            );

            return;
        }
        if (!empty($logistics['mail_skipped_no_email']) && $notifyRequested) {
            $this->getMessageManager()->addSuccess(
                (string)__('发货已提交（无客户邮箱，未发信）'),
            );

            return;
        }
        if ($notifyRequested) {
            $this->getMessageManager()->addSuccess(
                (string)__('发货已提交（未勾选或未能发送通知邮件）'),
            );

            return;
        }
        $this->getMessageManager()->addSuccess((string)__('发货已提交（未勾选通知客户，未发邮件）'));
    }

    private function redirectBack(): mixed
    {
        $returnUrl = trim((string)$this->request->getPost('return_url', ''));
        if ($returnUrl !== '' && preg_match('#^order/backend/order/edit\?id=[1-9][0-9]*(?:&|$)#', $returnUrl) === 1) {
            return $this->redirect($returnUrl);
        }

        return $this->redirect('order/backend/shipment/index');
    }

    private function humanizeShipError(string $code): string
    {
        return match ($code) {
            'shipment_tracking_required' => (string)__('请填写物流单号'),
            'shipment_tracking_too_long' => (string)__('物流单号过长，请控制在 100 字以内'),
            'shipment_carrier_too_long', 'shipment_carrier_invalid' => (string)__('请选择有效的承运商'),
            'shipment_channel_locked_provider' => (string)__('订单已交由物流商履约，请使用打单发货'),
            'shipment_label_weight_required' => (string)__('打单需要包裹重量（克），或先维护商品重量'),
            'shipment_label_address_incomplete' => (string)__('收货地址不完整，无法打单'),
            'shipment_label_lines_missing' => (string)__('缺少发货明细，无法打单'),
            'shipment_label_no_eligible_service' => (string)__('没有可用的物流商打单服务'),
            'shipment_label_orphaned_risk' => (string)__('履约失败且取消运单未确认，已进入补偿队列'),
            'shipment_label_failed', 'shipment_label_gateway_unavailable' => (string)__('物流商打单失败，请稍后重试或改用手工登记'),
            'shipment_not_found' => (string)__('发货记录不存在'),
            default => $code !== '' ? $code : (string)__('发货操作失败，请稍后重试。'),
        };
    }
}
