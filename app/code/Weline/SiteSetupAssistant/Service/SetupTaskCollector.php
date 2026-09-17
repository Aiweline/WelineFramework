<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;
use Weline\Websites\Model\Website;

/**
 * 收集各模块 Extends 的建站任务（单站 / 全局总览）。
 */
class SetupTaskCollector
{
    /**
     * 单站维度：按 website_id 收集并待办靠前。
     *
     * @param array{website_id?:int,website_code?:string,storage_scope?:string} $context
     * @return list<array<string, mixed>>
     */
    public function collect(array $context = []): array
    {
        return $this->sortTasks($this->collectRaw($context));
    }

    /**
     * 全局总览：每个任务附带各站覆盖状态（含默认站 #0）。
     *
     * @return list<array<string, mixed>>
     */
    public function collectGlobalOverview(): array
    {
        $websites = $this->listWebsiteContexts();
        if ($websites === []) {
            $websites = [[
                'website_id' => Website::ID_DEFAULT,
                'website_code' => 'default',
                'label' => (string)__('默认网站'),
            ]];
        }

        /** @var array<string, array<string, mixed>> $byCode */
        $byCode = [];
        foreach ($websites as $site) {
            $websiteId = (int)$site['website_id'];
            $label = (string)$site['label'];
            $tasks = $this->collectRaw([
                'website_id' => $websiteId,
                'website_code' => (string)($site['website_code'] ?? ''),
                'storage_scope' => '',
            ]);
            foreach ($tasks as $task) {
                $code = (string)$task['code'];
                if (!isset($byCode[$code])) {
                    $byCode[$code] = [
                        'code' => $code,
                        'title' => (string)$task['title'],
                        'tip' => '',
                        'status' => 'todo',
                        'href' => (string)($task['href'] ?? ''),
                        'category' => (string)($task['category'] ?? ''),
                        'module' => (string)($task['module'] ?? ''),
                        'scenarios' => $task['scenarios'] ?? ['new', 'migrate'],
                        'parent_code' => (string)($task['parent_code'] ?? ''),
                        'sort' => (int)($task['sort'] ?? 100),
                        'meta' => [],
                        'dimension' => 'global',
                        'site_coverage' => [],
                    ];
                }
                $byCode[$code]['site_coverage'][] = [
                    'website_id' => $websiteId,
                    'label' => $label,
                    'status' => (string)$task['status'],
                    'tip' => (string)($task['tip'] ?? ''),
                    'inherited' => !empty($task['meta']['inherited']),
                    'from_default' => !empty($task['meta']['from_default']),
                ];
            }
        }

        foreach ($byCode as &$row) {
            $coverage = is_array($row['site_coverage'] ?? null) ? $row['site_coverage'] : [];
            $total = count($coverage);
            $done = count(array_filter(
                $coverage,
                static fn(array $c): bool => ($c['status'] ?? '') === 'done'
            ));
            $doing = count(array_filter(
                $coverage,
                static fn(array $c): bool => ($c['status'] ?? '') === 'doing'
            ));
            $todo = $total - $done - $doing;
            if ($total > 0 && $done === $total) {
                $row['status'] = 'done';
            } elseif ($doing > 0 && $todo === 0) {
                $row['status'] = 'doing';
            } else {
                $row['status'] = 'todo';
            }
            $row['tip'] = (string)__('站点就绪 %{1}/%{2}（待办 %{3}）', [
                (string)$done,
                (string)$total,
                (string)$todo,
            ]);
            $row['meta'] = [
                'sites_total' => $total,
                'sites_done' => $done,
                'sites_todo' => $todo,
                'sites_doing' => $doing,
            ];
            // 覆盖内：待办站靠前
            usort(
                $coverage,
                static function (array $a, array $b): int {
                    $rank = static fn(string $s): int => match ($s) {
                        'todo' => 0,
                        'doing' => 1,
                        default => 2,
                    };
                    $byStatus = $rank((string)($a['status'] ?? 'todo'))
                        <=> $rank((string)($b['status'] ?? 'todo'));
                    if ($byStatus !== 0) {
                        return $byStatus;
                    }

                    return ((int)($a['website_id'] ?? 0)) <=> ((int)($b['website_id'] ?? 0));
                }
            );
            $row['site_coverage'] = $coverage;
        }
        unset($row);

        return $this->sortTasks(array_values($byCode));
    }

    /**
     * 各站未完成任务数（仅 remaining>0），供主界面胶囊提示与切站。
     *
     * @param list<array<string, mixed>>|null $overview collectGlobalOverview() 结果，省略则内采
     * @return list<array{
     *   website_id: int,
     *   label: string,
     *   remaining: int,
     *   todo: int,
     *   doing: int
     * }>
     */
    public function summarizeIncompleteSites(?array $overview = null): array
    {
        $overview ??= $this->collectGlobalOverview();
        /** @var array<int, array{website_id:int,label:string,remaining:int,todo:int,doing:int}> $bySite */
        $bySite = [];
        foreach ($overview as $task) {
            if (!is_array($task)) {
                continue;
            }
            $coverage = is_array($task['site_coverage'] ?? null) ? $task['site_coverage'] : [];
            foreach ($coverage as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row['website_id'] ?? -1);
                if ($id < 0) {
                    continue;
                }
                if (!isset($bySite[$id])) {
                    $bySite[$id] = [
                        'website_id' => $id,
                        'label' => (string)($row['label'] ?? ('#' . $id)),
                        'remaining' => 0,
                        'todo' => 0,
                        'doing' => 0,
                    ];
                }
                $status = (string)($row['status'] ?? 'todo');
                if ($status === 'todo') {
                    $bySite[$id]['todo']++;
                    $bySite[$id]['remaining']++;
                } elseif ($status === 'doing') {
                    $bySite[$id]['doing']++;
                    $bySite[$id]['remaining']++;
                }
            }
        }

        $list = array_values(array_filter(
            $bySite,
            static fn(array $s): bool => ($s['remaining'] ?? 0) > 0
        ));
        usort(
            $list,
            static function (array $a, array $b): int {
                // 默认站 #0 胶囊靠前，其余按未完成数降序
                $aId = (int)($a['website_id'] ?? -1);
                $bId = (int)($b['website_id'] ?? -1);
                if ($aId === Website::ID_DEFAULT && $bId !== Website::ID_DEFAULT) {
                    return -1;
                }
                if ($bId === Website::ID_DEFAULT && $aId !== Website::ID_DEFAULT) {
                    return 1;
                }
                $byRem = ((int)($b['remaining'] ?? 0)) <=> ((int)($a['remaining'] ?? 0));
                if ($byRem !== 0) {
                    return $byRem;
                }

                return $aId <=> $bId;
            }
        );

        return $list;
    }

    /**
     * @param array{website_id?:int,website_code?:string,storage_scope?:string} $context
     * @return list<array<string, mixed>>
     */
    private function collectRaw(array $context = []): array
    {
        $byCode = [];
        foreach ($this->getProviders() as $provider) {
            try {
                $rows = $provider->provideTasks($context);
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string)($row['code'] ?? ''));
                $title = trim((string)($row['title'] ?? ''));
                if ($code === '' || $title === '') {
                    continue;
                }
                $status = (string)($row['status'] ?? 'todo');
                if (!in_array($status, ['todo', 'doing', 'done'], true)) {
                    $status = 'todo';
                }
                $scenarios = $row['scenarios'] ?? ['new', 'migrate'];
                if (!is_array($scenarios) || $scenarios === []) {
                    $scenarios = ['new', 'migrate'];
                }
                $byCode[$code] = [
                    'code' => $code,
                    'title' => $title,
                    'tip' => (string)($row['tip'] ?? ''),
                    'status' => $status,
                    'href' => (string)($row['href'] ?? ''),
                    'category' => (string)($row['category'] ?? ''),
                    'module' => (string)($row['module'] ?? ''),
                    'scenarios' => array_values(array_filter(
                        $scenarios,
                        static fn($s): bool => in_array((string)$s, ['new', 'migrate'], true)
                    )) ?: ['new', 'migrate'],
                    'parent_code' => trim((string)($row['parent_code'] ?? '')),
                    'sort' => (int)($row['sort'] ?? 100),
                    'meta' => is_array($row['meta'] ?? null) ? $row['meta'] : [],
                    'dimension' => 'site',
                ];
            }
        }

        return array_values($byCode);
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    private function sortTasks(array $list): array
    {
        $statusRank = static fn(string $s): int => match ($s) {
            'todo' => 0,
            'doing' => 1,
            default => 2,
        };
        usort(
            $list,
            static function (array $a, array $b) use ($statusRank): int {
                $byStatus = $statusRank((string)($a['status'] ?? 'todo'))
                    <=> $statusRank((string)($b['status'] ?? 'todo'));
                if ($byStatus !== 0) {
                    return $byStatus;
                }
                $sort = ($a['sort'] ?? 100) <=> ($b['sort'] ?? 100);
                if ($sort !== 0) {
                    return $sort;
                }

                return strcmp((string)$a['code'], (string)$b['code']);
            }
        );

        return $list;
    }

    /**
     * @return list<array{website_id:int,website_code:string,label:string}>
     */
    private function listWebsiteContexts(): array
    {
        $out = [];
        try {
            /** @var Website $model */
            $model = ObjectManager::getInstance(Website::class);
            $rows = $model->clear()->select()->fetch()->getItems();
            foreach (is_array($rows) ? $rows : [] as $row) {
                $data = is_object($row) && method_exists($row, 'getData')
                    ? $row->getData()
                    : (is_array($row) ? $row : null);
                if (!is_array($data)) {
                    continue;
                }
                $id = (int)($data['website_id'] ?? $data['id'] ?? -1);
                if ($id < 0) {
                    continue;
                }
                $name = trim((string)($data['name'] ?? ''));
                $code = trim((string)($data['code'] ?? ''));
                $out[] = [
                    'website_id' => $id,
                    'website_code' => $code,
                    'label' => $name !== '' ? $name : ('#' . $id),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        usort($out, static fn(array $a, array $b): int => $a['website_id'] <=> $b['website_id']);

        return $out;
    }

    /** @return SetupTaskProviderInterface[] */
    private function getProviders(): array
    {
        $providers = [];
        foreach ($this->getProviderClassesFromExtends() as $implClass) {
            if (!is_string($implClass) || !class_exists($implClass)) {
                continue;
            }
            $instance = ObjectManager::getInstance($implClass);
            if ($instance instanceof SetupTaskProviderInterface) {
                $providers[] = $instance;
            }
        }

        return $providers;
    }

    /** @return string[] */
    private function getProviderClassesFromExtends(): array
    {
        $interface = SetupTaskProviderInterface::class;
        $merged = [];
        foreach (Env::getInstance()->getModuleList() as $module) {
            $basePath = $module['base_path'] ?? '';
            if ($basePath === '' || !($module['status'] ?? false)) {
                continue;
            }
            $extendsFile = rtrim((string)$basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'extends.php';
            if (!is_file($extendsFile)) {
                continue;
            }
            $config = include $extendsFile;
            if (!is_array($config) || !isset($config[$interface]) || !is_array($config[$interface])) {
                continue;
            }
            foreach ($config[$interface] as $implClass) {
                if (is_string($implClass)) {
                    $merged[] = $implClass;
                }
            }
        }

        return array_values(array_unique($merged));
    }
}
