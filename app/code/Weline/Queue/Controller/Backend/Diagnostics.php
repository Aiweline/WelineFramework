<?php

declare(strict_types=1);

namespace Weline\Queue\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Event\Outbox;
use Weline\Queue\Model\Queue;
use Weline\Queue\Model\Queue\Type;

#[Acl('Weline_Queue::queue_diagnostics', '队列诊断', 'mdi-stethoscope', '队列只读运行诊断', 'Weline_Queue::message_service')]
final class Diagnostics extends BackendController
{
    #[Acl('Weline_Queue::consumer_diagnostics', '消费者', 'mdi-account-cog-outline', '查看已注册队列消费者')]
    public function consumers(): string
    {
        return $this->renderWorkspace('consumers', '消费者', ['消费者注册' => [Type::class, ['type_id', 'name', 'module_name', 'tip', 'class', 'enable']]]);
    }

    #[Acl('Weline_Queue::retry_diagnostics', '重试诊断', 'mdi-reload-alert', '查看失败与待重试队列')]
    public function retries(): string
    {
        return $this->renderWorkspace('retries', '重试诊断', ['失败队列' => [Queue::class, ['queue_id', 'type_id', 'name', 'start_at', 'end_at', 'status', 'finished', 'auto', 'module', 'biz_key', 'dispatch_until', 'scope_kind', 'scope_website_id', 'scope_website_code', 'scope_store_code', 'scope_channel_code'], ['status' => Queue::status_error]]]);
    }

    #[Acl('Weline_Queue::inbox_diagnostics', 'Inbox', 'mdi-inbox-arrow-down-outline', '查看队列任务收件状态')]
    public function inbox(): string
    {
        return $this->renderWorkspace('inbox', 'Inbox', ['任务收件' => [Queue::class, ['queue_id', 'type_id', 'name', 'start_at', 'end_at', 'status', 'finished', 'auto', 'module', 'biz_key', 'dispatch_until', 'scope_kind', 'scope_website_id', 'scope_website_code', 'scope_store_code', 'scope_channel_code']]]);
    }

    #[Acl('Weline_Queue::outbox_diagnostics', 'Outbox', 'mdi-inbox-arrow-up-outline', '查看可靠异步事件 Outbox')]
    public function outbox(): string
    {
        return $this->renderWorkspace('outbox', 'Outbox', ['事件 Outbox' => [Outbox::class, ['outbox_id', 'event_id', 'event_name', 'payload_schema_version', 'status', 'attempt_count', 'lock_version', 'available_at', 'lease_expires_at', 'expanded_at', 'last_error_code', 'last_error', 'occurred_at', 'created_at', 'updated_at']]]);
    }

    /** @param array<string,array{0:class-string,1:list<string>,2?:array<string,mixed>}> $sources */
    private function renderWorkspace(string $code, string $title, array $sources): string
    {
        $datasets = [];
        foreach ($sources as $label => $definition) {
            try {
                /** @var Model $model */
                $model = ObjectManager::getInstance($definition[0])->reset();
                foreach ($definition[2] ?? [] as $field => $value) $model->where($field, $value);
                $rows = $model->limit(50)->select()->fetchArray();
                $safeRows = [];
                foreach ($rows as $row) if (is_array($row)) $safeRows[] = array_intersect_key($row, array_flip($definition[1]));
                $datasets[] = ['label' => __($label), 'rows' => $safeRows, 'error' => ''];
            } catch (\Throwable $throwable) {
                $datasets[] = ['label' => __($label), 'rows' => [], 'error' => $throwable->getMessage()];
            }
        }
        $this->assign('workspace_code', $code);
        $this->assign('workspace_title', __($title));
        $this->assign('workspace_datasets', $datasets);
        return $this->fetch('index');
    }
}
