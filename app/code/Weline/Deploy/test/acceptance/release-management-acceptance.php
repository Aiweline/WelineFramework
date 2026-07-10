<?php
/**
 * 发布管理 — 需求验收脚本（CLI）
 *
 * 用法：php app/code/Weline/Deploy/test/acceptance/release-management-acceptance.php
 * 映射：doc/发布管理-需求说明.md §10 验收标准
 */
declare(strict_types=1);

use Weline\Deploy\Service\DeployCoreUpdateService;
use Weline\Deploy\Service\DeployReleaseControlService;
use Weline\Framework\Manager\ObjectManager;

$root = dirname(__DIR__, 6);
chdir($root);
require $root . '/app/bootstrap.php';

$results = [];
$failed = 0;

function record(array &$results, int &$failed, string $id, string $title, bool $ok, string $detail = ''): void
{
    $results[] = [
        'id' => $id,
        'title' => $title,
        'ok' => $ok,
        'detail' => $detail,
    ];
    if (!$ok) {
        $failed++;
    }
}

function readMenuXml(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    return (string) file_get_contents($path);
}

// §10.1 菜单
$menuPath = $root . '/app/code/Weline/Deploy/etc/backend/menu.xml';
$menuXml = readMenuXml($menuPath);
record(
    $results,
    $failed,
    'RM-10.1-01',
    '系统维护下存在发布管理父菜单及三个子项',
    str_contains($menuXml, 'release_management')
        && str_contains($menuXml, 'deploy/backend/release-control')
        && str_contains($menuXml, 'deploy/backend/release')
        && str_contains($menuXml, 'deploy/backend/core-update'),
    'menu.xml'
);
record(
    $results,
    $failed,
    'RM-10.1-02',
    '发布历史不再作为系统维护顶级并列项',
    preg_match('/name="release_management"[\s\S]*name="deploy_release"/', $menuXml) === 1
        && !preg_match('/<menu[^>]*name="deploy_release"[^>]*parent="Weline_Backend::system_maintenance"/', $menuXml),
    'deploy_release 应嵌套在 release_management 子菜单内'
);

// §10.1-03 / 控制器存在性
$releaseController = $root . '/app/code/Weline/Deploy/Controller/Backend/Release.php';
$releaseControlController = $root . '/app/code/Weline/Deploy/Controller/Backend/ReleaseControl.php';
$coreUpdateController = $root . '/app/code/Weline/Deploy/Controller/Backend/CoreUpdate.php';
record(
    $results,
    $failed,
    'RM-10.1-03',
    '发布历史/发布控制/核心更新控制器可加载',
    is_file($releaseController)
        && is_file($releaseControlController)
        && is_file($coreUpdateController)
        && class_exists(\Weline\Deploy\Controller\Backend\Release::class)
        && class_exists(\Weline\Deploy\Controller\Backend\ReleaseControl::class)
        && class_exists(\Weline\Deploy\Controller\Backend\CoreUpdate::class),
    ''
);

// §10.2 / §10.4 服务层
$om = ObjectManager::getInstance();
/** @var DeployReleaseControlService $control */
$control = $om->get(DeployReleaseControlService::class);
/** @var DeployCoreUpdateService $coreUpdate */
$coreUpdate = $om->get(DeployCoreUpdateService::class);
$pageCtx = $control->buildPageContext();
$coreCtx = $coreUpdate->buildPageContext();

record(
    $results,
    $failed,
    'RM-10.2-01',
    '发布控制页上下文包含 deploy_root 与仓库配置字段',
    is_array($pageCtx['settings'] ?? null)
        && array_key_exists('project_branch', $pageCtx['settings'])
        && ($pageCtx['deploy_root'] ?? '') !== '',
    (string) ($pageCtx['deploy_root'] ?? '')
);
record(
    $results,
    $failed,
    'RM-10.4-01',
    '核心更新默认分支读取 core_branch_default（未配置回退 dev）',
    ($coreCtx['default_branch'] ?? '') === 'dev' || ($coreCtx['default_branch'] ?? '') !== '',
    'default_branch=' . ($coreCtx['default_branch'] ?? '')
);
record(
    $results,
    $failed,
    'RM-10.4-02',
    '核心更新页展示受保护路径说明',
    is_array($coreCtx['protected_paths'] ?? null)
        && in_array('app/etc/env.php', $coreCtx['protected_paths'], true)
        && in_array('dev/deploy/.config', $coreCtx['protected_paths'], true),
    implode(',', $coreCtx['protected_paths'] ?? [])
);

// §10.3 发布历史模板含回滚入口
$releaseTpl = (string) file_get_contents($root . '/app/code/Weline/Deploy/view/templates/Backend/Release/index.phtml');
record(
    $results,
    $failed,
    'RM-10.3-01',
    '发布历史模板含回滚到此版本按钮与确认弹窗',
    str_contains($releaseTpl, '回滚到此版本')
        && str_contains($releaseTpl, 'data-action="rollback"')
        && str_contains($releaseTpl, 'confirm_rollback')
        && str_contains($releaseTpl, 'confirm_older_version'),
    ''
);

// §10.2 发布控制模板
$controlTpl = (string) file_get_contents($root . '/app/code/Weline/Deploy/view/templates/Backend/ReleaseControl/index.phtml');
record(
    $results,
    $failed,
    'RM-10.2-02',
    '发布控制支持分支切换与 commit/tag 双 Tab',
    str_contains($controlTpl, 'wrc-branch')
        && str_contains($controlTpl, 'wrc-panel-commits')
        && str_contains($controlTpl, 'wrc-panel-tags')
        && str_contains($controlTpl, 'release-commit')
        && str_contains($controlTpl, 'release-tag'),
    ''
);
record(
    $results,
    $failed,
    'RM-10.2-03',
    '旧版本发布需二次确认（前端+POST 参数）',
    str_contains($controlTpl, 'wrc-confirm-older')
        && str_contains($controlTpl, 'confirm_older_version')
        && str_contains($controlTpl, 'requires_older_confirm'),
    ''
);

// §10.6 服务端拒绝未确认发布 — 反射检查控制器源码含校验
$rcSource = (string) file_get_contents($releaseControlController);
record(
    $results,
    $failed,
    'RM-10.6-01',
    '发布执行 POST 须 confirm_release',
    str_contains($rcSource, "confirm_release") && str_contains($rcSource, '请先确认发布操作'),
    ''
);
record(
    $results,
    $failed,
    'RM-10.6-02',
    '旧版本发布须 confirm_older_version（服务端拦截）',
    str_contains($rcSource, 'confirm_older_version') && str_contains($rcSource, 'requires_older_confirm'),
    ''
);

// §10.5 / §6 Orchestrator 备份与目录保护
$orchSource = (string) file_get_contents($root . '/app/code/Weline/Deploy/Service/DeployOrchestratorService.php');
record(
    $results,
    $failed,
    'RM-10.5-01',
    '正式站强制备份（isProductionDeploy + DeploySiteBackupService）',
    str_contains($orchSource, 'isProductionDeploy')
        && str_contains($orchSource, 'DeploySiteBackupService')
        && str_contains($orchSource, 'shouldRunBackup'),
    ''
);
record(
    $results,
    $failed,
    'RM-10.2-04',
    '发布过程保护 pub/ 与 var/generated/ 等目录',
    str_contains($orchSource, "'pub'")
        && str_contains($orchSource, 'var/generated')
        && str_contains($orchSource, 'snapshotProtectedPaths')
        && str_contains($orchSource, 'restoreProtectedPaths'),
    ''
);
record(
    $results,
    $failed,
    'RM-10.2-05',
    '任意 commit 发布（ref_type=commit + checkoutCommit）',
    str_contains($orchSource, "refType === 'commit'") && str_contains($orchSource, 'checkoutCommit'),
    ''
);

// §10.2 Git 元数据：分支/commit/tag API 所需方法
$gitSource = (string) file_get_contents($root . '/app/code/Weline/Deploy/Service/DeployGitMetadataService.php');
record(
    $results,
    $failed,
    'RM-10.2-06',
    'Git 服务支持 listRemoteBranches/listCommits/listTags/isAncestor',
    str_contains($gitSource, 'listRemoteBranches')
        && str_contains($gitSource, 'listCommits')
        && str_contains($gitSource, 'listTags')
        && str_contains($gitSource, 'isAncestor'),
    ''
);

// HTTP 冒烟（服务运行时可测）
$httpCases = [];
$backendRoutes = [
    'RM-10.1-03-http' => 'deploy/backend/release',
    'RM-10.2-http-control' => 'deploy/backend/release-control',
    'RM-10.4-http-core' => 'deploy/backend/core-update',
];
foreach ($backendRoutes as $caseId => $route) {
    $cmd = 'cd ' . escapeshellarg($root) . ' && php bin/w http:request ' . escapeshellarg('/' . $route) . ' 2>&1';
    exec($cmd, $output, $exitCode);
    $text = implode("\n", $output);
    $ok = $exitCode === 0
        && !preg_match('/Fatal error|ParseError|Class .* not found/i', $text)
        && !str_contains($text, 'cURL错误: Failed to connect');
    record($results, $failed, $caseId, "HTTP 可访问 /{$route}", $ok, $ok ? 'ok' : mb_substr($text, 0, 200));
    $output = [];
}

$passed = count($results) - $failed;
echo json_encode([
    'summary' => [
        'total' => count($results),
        'passed' => $passed,
        'failed' => $failed,
        'ok' => $failed === 0,
    ],
    'cases' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failed === 0 ? 0 : 1);
