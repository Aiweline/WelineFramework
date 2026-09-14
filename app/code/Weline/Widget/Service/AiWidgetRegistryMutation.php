<?php
declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Widget\Model\AiWidget;

/** 公共 AI 注册定义的写入、changed 与缓存版本共用默认主库事务。 */
final class AiWidgetRegistryMutation
{
    public const NAMESPACE = 'global/widget/registry';

    public function __construct(
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly ResourceChangeFactory $changes,
        private readonly ResourceRevisionService $revisions,
        private readonly NamespaceGenerationRepository $namespaces,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    public function run(AiWidget $model, array $identity, callable $mutation, bool $delete = false): mixed
    {
        $connection = $model->getConnection();
        // 事务接管查询器前保存 fluent delete 的 WHERE，避免失去删除范围。
        $deleteQuery = $delete ? clone $model->getQuery() : null;
        return $this->transactions->run($connection, function () use ($model, $identity, $mutation, $delete, $deleteQuery): mixed {
            $before = [];
            if ($deleteQuery === null && (int)($identity[AiWidget::schema_fields_ID] ?? 0) > 0) {
                $row = (clone $model)->clearData()->reset()
                    ->where(AiWidget::schema_fields_ID, $identity[AiWidget::schema_fields_ID])->find()->fetchArray();
                $before = is_array($row) ? $row : [];
            }
            $deletedRows = [];
            if ($deleteQuery !== null) {
                $rows = (clone $deleteQuery)->select()->fetchArray();
                $deletedRows = is_array($rows) ? $rows : [];
                $model->bindQuery($deleteQuery);
            }
            $result = $mutation();
            if ($result === false) {
                return $result;
            }
            if ($delete) {
                foreach ($deletedRows as $row) {
                    $this->publish(is_array($row) ? $row : [], null);
                }
            } else {
                $after = array_replace($before, (array)$model->getData());
                if ($after != $before) {
                    $this->publish($before, $after);
                }
            }
            return $result;
        });
    }

    private function publish(array $before, ?array $after): void
    {
        $current = $after ?? $before;
        $id = (int)($current[AiWidget::schema_fields_ID] ?? 0);
        $paths = [self::NAMESPACE, 'global/storefront/theme'];
        $change = $this->changes->create(
            resourceType: 'widget',
            resourceId: $id,
            action: $after === null ? 'delete' : 'upsert',
            revision: $this->revisions->next('widget', $id),
            websiteId: 0,
            websiteCode: 'global',
            before: $this->snapshot($before),
            after: $after === null ? null : $this->snapshot($after),
            changedFields: array_keys($after ?? $before),
            impact: ['namespaces' => $paths],
            origin: ['entry' => 'Weline_Widget::ai_registry'],
        );
        \w_changed($change);
        $requestId = (string)RequestContext::getId();
        // 与标准 Observer 的 bump 同事务去重；发布回调只接收最终已提交向量。
        $this->namespaces->bumpMany($paths, [
            'widget-ai-registry' => function (int $clock, array $versions) use ($requestId): void {
                WidgetRegistry::clearRuntimeCache();
                try {
                    $publisher = $this->runtimeProviders->resolve(RuntimeNamespaceInvalidationPublisherInterface::class);
                    if ($publisher instanceof RuntimeNamespaceInvalidationPublisherInterface) {
                        $publisher->publish($clock, $versions, null, $requestId);
                    }
                } catch (\Throwable) {
                    // 已提交版本仍是权威；运行时广播只加速其他 worker 观察。
                }
            },
        ]);
    }

    private function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip([
            AiWidget::schema_fields_ID, AiWidget::schema_fields_WIDGET_CODE,
            AiWidget::schema_fields_TYPE, AiWidget::schema_fields_NAME,
            AiWidget::schema_fields_IS_ACTIVE, AiWidget::schema_fields_UPDATE_TIME,
        ]));
    }
}
