<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Controller\Backend;

use Weline\AiAcquisition\Model\Lead as LeadModel;
use Weline\AiAcquisition\Service\Customer\LeadConverter;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 线索转为客户：POST 转 LeadConverter service，json 返回 customer_id/created/merged。
 */
final class LeadConvert extends BackendController
{
    #[Acl('Weline_AiAcquisition::lead_convert', '线索转为客户', 'user-plus', '把可转化线索转为匿名客户', 'Weline_AiAcquisition::lead_list')]
    public function postConvert(): string
    {
        $leadId = (int)$this->request->getGet('lead_id', $this->request->getParam('lead_id', 0));
        if ($leadId <= 0) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('缺少线索ID')], 422);
        }

        /** @var LeadModel $lead */
        $lead = ObjectManager::getInstance(LeadModel::class);
        $lead = $lead->clear()->load($leadId);
        if (!$lead || !$lead->getId()) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('线索不存在')], 404);
        }

        try {
            $result = ObjectManager::getInstance(LeadConverter::class)->convert($lead);
        } catch (\Throwable $e) {
            return $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $alreadyConverted = (bool)($result['already_converted'] ?? false);
        return $this->jsonResponse([
            'success' => true,
            'message' => (string)__(
                $alreadyConverted ? '该线索已转化，关联客户 #%{1}' : '已转为客户 #%{1}',
                [(string)$result['customer_id']],
            ),
            'customer_id' => (int)$result['customer_id'],
            'created' => (bool)$result['created'],
            'merged' => (bool)$result['merged'],
            'already_converted' => $alreadyConverted,
            'status' => LeadModel::STATUS_CONVERTED,
        ]);
    }

    private function jsonResponse(array $data, int $code = 200): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setCode($code);

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
