<?php

declare(strict_types=1);

namespace Weline\Points\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Points\Model\ClawbackRecord;
use Weline\Points\Model\CustomerState;
use Weline\Points\Model\DiscountApplyRecord;
use Weline\Points\Model\EarnRecord;
use Weline\Points\Model\VipLevel;

#[Acl(
    'Weline_Points::commerce:partner:points',
    '积分与会员',
    'gift',
    '积分、成长值、VIP 等级、积分账本与抵扣记录',
    'Weline_Backend::commerce:partner:group',
)]
final class Points extends BackendController
{
    #[Acl('Weline_Points::commerce:partner:points_overview', '积分概览', 'chart', '查看积分与会员概览')]
    public function index(): string
    {
        return $this->render('overview', '积分概览', []);
    }

    #[Acl('Weline_Points::commerce:partner:points_levels', 'VIP 等级', 'circle', '查看 VIP 等级门槛与权益')]
    public function levels(): string
    {
        return $this->render('levels', 'VIP 等级', [
            'VIP 等级' => [VipLevel::class, [
                'level_id', 'code', 'name', 'min_growth', 'discount_rate',
                'points_multiplier', 'free_shipping', 'is_active', 'sort_order',
            ]],
        ]);
    }

    #[Acl('Weline_Points::commerce:partner:points_customers', '会员成长', 'users', '查看会员成长值与累计积分')]
    public function customers(): string
    {
        return $this->render('customers', '会员成长', [
            '会员成长' => [CustomerState::class, [
                'state_id', 'customer_id', 'website_id', 'namespace',
                'growth_value', 'lifetime_points', 'level_id', 'level_code', 'updated_at',
            ]],
        ]);
    }

    #[Acl('Weline_Points::commerce:partner:points_ledger', '积分账本', 'book', '查看积分赚取与退款扣回记录')]
    public function ledger(): string
    {
        return $this->render('ledger', '积分账本', [
            '赚分记录' => [EarnRecord::class, [
                'record_id', 'event_id', 'order_id', 'order_uuid', 'customer_id',
                'website_id', 'base_currency', 'cash_base_minor', 'points_awarded',
                'growth_awarded', 'points_clawed_back', 'status', 'created_at',
            ]],
            '退款扣回' => [ClawbackRecord::class, [
                'clawback_id', 'event_id', 'earn_record_id', 'order_id',
                'refund_key', 'customer_id', 'expected_points', 'actual_points',
                'unrecovered_points', 'reason', 'created_at',
            ]],
        ]);
    }

    #[Acl('Weline_Points::commerce:partner:points_discounts', '抵扣记录', 'cash', '查看结账积分抵扣记录')]
    public function discounts(): string
    {
        return $this->render('discounts', '抵扣记录', [
            '抵扣记录' => [DiscountApplyRecord::class, [
                'apply_id', 'order_uuid', 'customer_id', 'website_id', 'namespace',
                'checkout_currency', 'points', 'apply_checkout_minor', 'apply_base_minor',
                'reservation_id', 'committed_points', 'returned_points', 'status', 'created_at', 'updated_at',
            ]],
        ]);
    }

    #[Acl('Weline_Points::commerce:partner:points_settings', '积分设置', 'eye', '查看积分设置入口')]
    public function settings(): string
    {
        $this->assign('points_page', 'settings');
        $this->assign('points_title', (string) __('积分设置'));
        $this->assign('points_summary', []);
        $this->assign('points_datasets', []);
        $this->assign('points_settings_url', 'weline_systemconfig/backend/config');

        return $this->fetch('index');
    }

    /**
     * @param array<string, mixed> $datasets
     */
    private function render(string $page, string $title, array $datasets): string
    {
        if ($page === 'overview') {
            $datasets = [
                'VIP 等级' => [VipLevel::class, ['level_id', 'code', 'name', 'min_growth', 'points_multiplier', 'is_active']],
                '会员成长' => [CustomerState::class, ['state_id', 'customer_id', 'website_id', 'growth_value', 'lifetime_points', 'level_code', 'updated_at']],
                '最近赚分' => [EarnRecord::class, ['record_id', 'order_id', 'customer_id', 'points_awarded', 'growth_awarded', 'base_currency', 'cash_base_minor', 'status', 'created_at']],
                '最近抵扣' => [DiscountApplyRecord::class, ['apply_id', 'order_uuid', 'customer_id', 'points', 'apply_checkout_minor', 'status', 'created_at']],
            ];
        }

        $summary = [];
        $rendered = [];
        foreach ($datasets as $label => $definition) {
            if (!\is_array($definition) || !isset($definition[0])) {
                continue;
            }
            $row = $this->loadRows((string) $label, (string) $definition[0], (array) $definition[1]);
            $rendered[] = $row;
            $summary[(string) $label] = \count($row['rows']);
        }

        $this->assign('points_page', $page);
        $this->assign('points_title', (string) __($title));
        $this->assign('points_summary', $summary);
        $this->assign('points_datasets', $rendered);
        $this->assign('points_settings_url', 'weline_systemconfig/backend/config');

        return $this->fetch('index');
    }

    /**
     * @param class-string<Model> $modelClass
     * @param list<string> $fields
     * @return array{label:string,rows:list<array<string,mixed>>,error:string}
     */
    private function loadRows(string $label, string $modelClass, array $fields): array
    {
        try {
            /** @var Model $model */
            $model = ObjectManager::getInstance($modelClass)->reset();
            $rows = $model->limit(100)->select()->fetchArray();
            $safeRows = [];
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                if ($fields === ['*']) {
                    $safeRows[] = $row;
                    continue;
                }
                $safeRows[] = \array_intersect_key($row, \array_flip($fields));
            }

            return ['label' => (string) __($label), 'rows' => $safeRows, 'error' => ''];
        } catch (\Throwable $throwable) {
            $reference = strtoupper(substr(hash('sha256', self::class . '|' . $label . '|' . uniqid('', true)), 0, 12));
            try {
                w_log_error('Points backend load failed', [
                    'reference' => $reference,
                    'controller' => self::class,
                    'label' => $label,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ], 'points_backend');
            } catch (\Throwable) {
            }

            return [
                'label' => (string) __($label),
                'rows' => [],
                'error' => (string) __('数据暂时无法加载。参考编号：%{1}', [$reference]),
            ];
        }
    }
}
