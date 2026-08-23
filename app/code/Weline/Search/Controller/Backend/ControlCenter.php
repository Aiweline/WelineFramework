<?php

declare(strict_types=1);

namespace Weline\Search\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Model\SearchDegradeState;
use Weline\Search\Model\SearchServingAlias;
use Weline\Search\Model\SearchShardRegistry;
use Weline\Search\Service\SearchRolloutGate;
use Weline\Search\Service\SearchShardSchemaCatalog;

#[Acl(
    'Weline_Search::commerce:tax-search:control-center',
    '搜索管理',
    'search',
    '搜索索引只读管理与迁移诊断',
    'Weline_Backend::commerce:tax-search:group'
)]
final class ControlCenter extends BackendController
{
    #[Acl('Weline_Search::commerce:tax-search:config', '搜索配置', 'settings', '查看搜索配置')]
    public function config(): string
    {
        $gate = ObjectManager::getInstance(SearchRolloutGate::class);
        if ($this->request->isPost()) {
            try {
                $mode = strtolower(trim((string)$this->request->getPost('mode', SearchRolloutGate::MODE_OFF)));
                if (!in_array($mode, [SearchRolloutGate::MODE_OFF, SearchRolloutGate::MODE_SHADOW], true)) {
                    throw new \InvalidArgumentException('search_admin_mode_must_be_off_or_shadow');
                }
                $gate->setMode(SearchRolloutGate::CAPABILITY, $mode);
                $this->getMessageManager()->addSuccess(__('搜索运行模式已保存。'));
            } catch (\Throwable $throwable) {
                $this->getMessageManager()->addError(__('搜索运行模式保存失败：%{1}', [$throwable->getMessage()]));
            }

            return $this->redirect('search/backend/controlcenter/config');
        }

        $configuration = $gate->configuration();
        return $this->renderWorkspace('config', '搜索配置', [], $this->rolloutStatus() + [
            'schema_version' => SearchShardSchemaCatalog::SCHEMA_VERSION,
        ], [
            'mode' => (string)($configuration['mode'] ?? SearchRolloutGate::MODE_OFF),
            'env_locked' => !empty($configuration['env_locked']),
        ]);
    }

    #[Acl('Weline_Search::commerce:tax-search:generations', '索引代次', 'circle', '查看搜索索引代次')]
    public function generations(): string
    {
        return $this->renderWorkspace('generations', '索引代次', [
            'Website 分片' => [SearchShardRegistry::class, ['registry_id', 'website_id', 'shard_key', 'status', 'fingerprint', 'schema_version', 'error_message', 'updated_at']],
            '服务别名' => [SearchServingAlias::class, ['alias_id', 'website_id', 'active_alias', 'active_generation', 'alias_version', 'updated_at']],
        ]);
    }

    #[Acl('Weline_Search::commerce:tax-search:incremental', '增量状态', 'refresh', '查看搜索增量状态')]
    public function incremental(): string
    {
        return $this->renderWorkspace('incremental', '增量状态', [
            '分片注册状态' => [SearchShardRegistry::class, ['website_id', 'shard_key', 'status', 'schema_version', 'updated_at']],
        ], ['data_source' => 'durable_search_shard_registry']);
    }

    #[Acl('Weline_Search::commerce:tax-search:degraded', '降级状态', 'warning', '查看搜索降级状态')]
    public function degraded(): string
    {
        return $this->renderWorkspace('degraded', '降级状态', [
            '降级标记' => [SearchDegradeState::class, ['marker_id', 'website_id', 'active', 'reason', 'required_source_watermark', 'index_watermark_at_mark', 'marker_version', 'marked_at', 'cleared_at', 'updated_at']],
        ]);
    }

    #[Acl('Weline_Search::commerce:tax-search:migration', '迁移状态', 'eye', '只读查看搜索迁移状态')]
    public function migration(): string
    {
        return $this->renderWorkspace('migration', '迁移状态', [
            '服务别名' => [SearchServingAlias::class, ['website_id', 'active_alias', 'active_generation', 'alias_version', 'updated_at']],
        ], $this->rolloutStatus() + [
            'execution_policy' => 'registered_postgresql_full_clone_cli_only',
            'production_actions_exposed' => false,
        ]);
    }

    /** @param array<string,array{0:class-string,1:list<string>}> $sources */
    private function renderWorkspace(
        string $code,
        string $title,
        array $sources,
        array $status = [],
        ?array $configurationForm = null,
    ): string
    {
        $datasets = [];
        foreach ($sources as $label => [$modelClass, $fields]) {
            $datasets[] = $this->loadRows($label, $modelClass, $fields);
        }
        $this->assign('workspace_code', $code);
        $this->assign('workspace_title', __($title));
        $this->assign('workspace_status', $status);
        $this->assign('workspace_datasets', $datasets);
        $this->assign('workspace_config_form', $configurationForm);
        return $this->fetch('index');
    }

    /** @param class-string $modelClass @param list<string> $fields */
    private function loadRows(string $label, string $modelClass, array $fields): array
    {
        try {
            $rows = ObjectManager::getInstance($modelClass)->reset()->limit(50)->select()->fetchArray();
            $safeRows = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $safeRows[] = array_intersect_key($row, array_flip($fields));
                }
            }
            return ['label' => __($label), 'rows' => $safeRows, 'error' => ''];
        } catch (\Throwable $throwable) {
            return ['label' => __($label), 'rows' => [], 'error' => $throwable->getMessage()];
        }
    }

    private function rolloutStatus(): array
    {
        try {
            $configuration = ObjectManager::getInstance(SearchRolloutGate::class)->configuration();
            return [
                'mode' => (string)($configuration['mode'] ?? 'off'),
                'allowlist_count' => count((array)($configuration['allowlist'] ?? [])),
                'env_locked' => !empty($configuration['env_locked']),
            ];
        } catch (\Throwable $throwable) {
            return ['status_error' => $throwable->getMessage()];
        }
    }
}
