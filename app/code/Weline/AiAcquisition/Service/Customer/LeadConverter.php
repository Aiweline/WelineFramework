<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Customer;

use Weline\AiAcquisition\Model\Lead;
use Weline\Customer\Model\Customer;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 线索转为客户：
 * - 校验 lead.status ∈ (replied/qualified/contacted) 且 email 非空合法
 * - 事务内按 email 查 w_customer：
 *   - 存在且 customer_type != 'ai_acquisition'：只关联（merged=true，不改类型）
 *   - 不存在或为 ai_acquisition 占位：复用/新建匿名客户（customer_type='ai_acquisition'，is_anonymous=1，随机密码不可登录）
 * - lead.status → converted，回填 lead.customer_id
 * - 幂等：重复调用同 email 只关联不重建
 */
class LeadConverter
{
    /** 允许转化的 lead 状态 */
    public const CONVERTIBLE_STATUSES = [
        Lead::STATUS_CONTACTED,
        Lead::STATUS_REPLIED,
        Lead::STATUS_QUALIFIED,
        Lead::STATUS_CONVERTED, // 重复转化幂等：converted 状态允许再次 convert，走只关联不重建
    ];
    /** 重复转化（status=converted）返回时附加的幂等标记 */
    public const ALREADY_CONVERTED_FLAG = 'already_converted';

    /**
     * @return array{customer_id:int, created:bool, merged:bool}
     * @throws \RuntimeException 校验失败或模型调用失败
     */
    public function convert(Lead $lead): array
    {
        $leadId = (int)$lead->getId();
        if ($leadId <= 0) {
            throw new \RuntimeException((string)__('线索不存在'));
        }
        $status = (string)$lead->getData(Lead::schema_fields_STATUS);
        $alreadyConverted = $status === Lead::STATUS_CONVERTED;
        if (!in_array($status, self::CONVERTIBLE_STATUSES, true)) {
            throw new \RuntimeException((string)__('线索状态 %{1} 不可转化', [$status]));
        }
        $email = strtolower(trim((string)$lead->getData(Lead::schema_fields_EMAIL)));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException((string)__('线索邮箱为空或格式不正确，不可转化'));
        }

        // 幂等：已 converted 的 lead 再次转化 → 只关联既有 customer，不重建。
        if ($alreadyConverted) {
            $existingCustomerId = (int)$lead->getData(Lead::schema_fields_CUSTOMER_ID);
            /** @var Customer $customerModel */
            $customerModel = ObjectManager::getInstance(Customer::class);
            $existing = null;
            if ($existingCustomerId > 0) {
                $existing = $customerModel->clearData()->reset()
                    ->where(Customer::schema_fields_ID, $existingCustomerId)
                    ->find()
                    ->fetch();
            }
            if (!$existing || !$existing->getId()) {
                // customer_id 缺失/为 0：按 email 再查补关联
                $existing = $customerModel->clearData()->reset()
                    ->where(Customer::schema_fields_email, $email)
                    ->find()
                    ->fetch();
            }
            if ($existing && $existing->getId()) {
                $customerId = (int)$existing->getId();
            } else {
                // 无任何既有客户：走正常转化路径补建
                $result = $this->doConvert($email, (string)$lead->getData(Lead::schema_fields_COMPANY_NAME));
                $customerId = $result['customer_id'];
            }
            if ($existingCustomerId !== $customerId) {
                $lead->setData(Lead::schema_fields_CUSTOMER_ID, $customerId);
                $lead->save();
            }
            return [
                'customer_id' => $customerId,
                'created' => false,
                'merged' => false,
                self::ALREADY_CONVERTED_FLAG => true,
            ];
        }

        $companyName = (string)$lead->getData(Lead::schema_fields_COMPANY_NAME);

        $result = $this->doConvert($email, $companyName);

        $lead->setData(Lead::schema_fields_STATUS, Lead::STATUS_CONVERTED);
        $lead->setData(Lead::schema_fields_CUSTOMER_ID, $result['customer_id']);
        $lead->save();

        return $result;
    }

    /**
     * 事务内按 email 查找/新建客户（只关联不重建的落库核心）。
     *
     * @return array{customer_id:int, created:bool, merged:bool}
     */
    private function doConvert(string $email, string $companyName): array
    {
        /** @var TransactionCoordinatorInterface $coordinator */
        $coordinator = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        $connection = ConnectionFactory::getInstance();
        return $coordinator->run(
            $connection,
            function () use ($email, $companyName): array {
                /** @var Customer $customerModel */
                $customerModel = ObjectManager::getInstance(Customer::class);
                $existing = $customerModel->clearData()->reset()
                    ->where(Customer::schema_fields_email, $email)
                    ->find()
                    ->fetch();

                if ($existing && $existing->getId()) {
                    $existingType = (string)$existing->getData(Customer::schema_fields_customer_type);
                    $isAiAcquisitionPlaceholder = $existingType === Customer::CUSTOMER_TYPE_AI_ACQUISITION;
                    if ($isAiAcquisitionPlaceholder) {
                        // AI 获客占位客户：确保类型与匿名标记
                        $existing->setData(Customer::schema_fields_customer_type, Customer::CUSTOMER_TYPE_AI_ACQUISITION)
                            ->setData(Customer::schema_fields_is_anonymous, 1)
                            ->save();
                        return ['customer_id' => (int)$existing->getId(), 'created' => false, 'merged' => false];
                    }
                    // 正式客户：只关联，不改类型
                    return ['customer_id' => (int)$existing->getId(), 'created' => false, 'merged' => true];
                }

                /** @var Customer $customer */
                $customer = clone $customerModel;
                $customer->clearData()->reset()->setData([
                    Customer::schema_fields_email => $email,
                    Customer::schema_fields_username => mb_substr(trim($companyName) !== '' ? $companyName : $email, 0, 100),
                    Customer::schema_fields_password => bin2hex(random_bytes(16)), // 随机 32hex，不可登录
                    Customer::schema_fields_is_anonymous => 1,
                    Customer::schema_fields_customer_type => Customer::CUSTOMER_TYPE_AI_ACQUISITION,
                ])->save();

                return ['customer_id' => (int)$customer->getId(), 'created' => true, 'merged' => false];
            },
        );
    }
}
