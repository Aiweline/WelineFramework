<?php
declare(strict_types=1);

namespace Weline\FileManager\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\FileManager\Queue\WlsFileManagerLargeOperationQueue;
use Weline\FileManager\Service\WlsFileManagerLargeOperationService;
use Weline\FileManager\Service\WlsFileManagerPathPolicyService;

#[Acl('Weline_FileManager::wls_file_manager', 'WLS 文件管理器', 'mdi-folder-cog-outline', 'WLS 面板文件管理器入口', 'Weline_Backend::system_maintenance')]
class WlsFileManager extends BackendController
{
    private const MAX_PREVIEW_BYTES = 65536;
    private const MAX_DOWNLOAD_BYTES = 20971520;
    private const MAX_TEXT_SAVE_BYTES = 131072;
    private const MAX_UPLOAD_BYTES = 5242880;
    private const MAX_COMPRESS_BYTES = 10485760;
    private const MAX_COMPRESS_ENTRIES = 200;
    private const MAX_RECURSIVE_DELETE_BYTES = 10485760;
    private const MAX_RECURSIVE_DELETE_ENTRIES = 100;
    private const OPERATION_LOG_LIMIT = 20;
    private const OPERATION_LOG_SCAN_LIMIT = 200;
    private const QUEUE_OPERATION_LIMIT = 10;
    private const TRASH_HISTORY_LIMIT = 30;
    private const OPERATION_LOG_RELATIVE_PATH = 'log/wls_file_manager_operations.log';
    private const WRITE_ROOT_KEYS = ['var', 'pub', 'project_var', 'project_pub'];
    private const SOURCE_EDIT_CONFIRM_PHRASE = 'SAVE_SOURCE';
    private const SOURCE_CREATE_CONFIRM_PHRASE = 'SOURCE_CREATE_FILE';
    private const SOURCE_RENAME_CONFIRM_PHRASE = 'SOURCE_RENAME';
    private const SOURCE_TRASH_CONFIRM_PHRASE = 'SOURCE_TRASH';
    private const SOURCE_QUEUE_TRASH_CONFIRM_PHRASE = 'SOURCE_QUEUE_TRASH';
    private const SOURCE_QUEUE_ARCHIVE_CONFIRM_PHRASE = 'SOURCE_QUEUE_ARCHIVE';
    private const SOURCE_QUEUE_ARCHIVE_TREE_CONFIRM_PHRASE = 'SOURCE_QUEUE_ARCHIVE_TREE';
    private const SOURCE_QUEUE_ARCHIVE_SELECTION_CONFIRM_PHRASE = 'SOURCE_QUEUE_ARCHIVE_SELECTION';
    private const SOURCE_ARCHIVE_SELECTION_MAX_ITEMS = 20;
    private const SOURCE_ARCHIVE_RELATIVE_PATH = 'wls-panel/file-manager/source-archives';
    private const SOURCE_EDIT_PROTECTED_SEGMENTS = ['.git', '.wls-trash', 'generated', 'node_modules', 'vendor', 'var'];
    private const SOURCE_EDIT_PROTECTED_PATHS = [
        '.env',
        'app/etc/env.php',
        'composer.lock',
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
    ];
    private const WRITE_TEXT_EXTENSIONS = [
        'conf',
        'css',
        'csv',
        'ini',
        'json',
        'log',
        'md',
        'txt',
        'xml',
        'yaml',
        'yml',
    ];
    private const WRITE_UPLOAD_EXTENSIONS = [
        'conf',
        'csv',
        'gif',
        'ini',
        'jpeg',
        'jpg',
        'json',
        'log',
        'md',
        'pdf',
        'png',
        'txt',
        'webp',
        'yaml',
        'yml',
        'zip',
    ];

    private ?WlsFileManagerPathPolicyService $pathPolicyService = null;

    #[Acl('Weline_FileManager::wls_file_manager_index', '查看 WLS 文件管理器', 'mdi-folder-open-outline', '查看 WLS 面板文件管理器')]
    public function getIndex(): string
    {
        $this->useStandaloneLayout();
        $context = $this->requestContext();
        $pathPolicy = $this->pathPolicyService()->getPolicyForContext($context);
        $roots = $this->rootCards($context, $pathPolicy);
        $browse = $this->browseData($roots);
        $pageKey = $this->normalizePageKey(
            trim((string)$this->request->getGet('page_key', '')),
            trim((string)$this->request->getGet('operation', ''))
        );

        $this->assign('title', __('WLS 文件管理器'));
        $this->assign('page_title', __('WLS 文件管理器'));
        $this->assign('wlsFileManagerPageKey', $pageKey);
        $this->assign('wlsFileManagerContext', $context);
        $this->assign('wlsFileManagerPathPolicy', $pathPolicy);
        $this->assign('wlsFileManagerRoots', $roots);
        $this->assign('wlsFileManagerBrowse', $browse);
        $this->assign('wlsFileManagerPreview', $this->previewData($roots));
        $this->assign('wlsFileManagerCapabilities', $this->capabilityCards($context));
        $this->assign('wlsFileManagerNotice', $this->resolveNotice((string)$this->request->getGet('wfm_notice', '')));
        $this->assign('wlsFileManagerError', $this->resolveError((string)$this->request->getGet('wfm_error', '')));
        $this->assign('wlsFileManagerEmbedded', $this->isEmbeddedPanelRequest());
        $this->assign('wlsFileManagerQueueOperations', $this->queueOperationData());
        $operationAudit = $this->operationAuditData($roots);
        $this->assign('wlsFileManagerOperationLogs', $operationAudit['logs']);
        $this->assign('wlsFileManagerOperationLogFilters', $operationAudit['filters']);
        $this->assign('wlsFileManagerOperationLogSummary', $operationAudit['summary']);

        return $this->fetch('index');
    }

    #[Acl('Weline_FileManager::wls_file_manager_roots', 'View WLS File Roots', 'mdi mdi-folder-outline', 'View WLS Panel file roots')]
    public function getRoots(): string
    {
        return $this->openPage('roots', 'roots');
    }

    #[Acl('Weline_FileManager::wls_file_manager_browser', 'View WLS File Browser', 'mdi mdi-file-tree-outline', 'View WLS Panel file browser')]
    public function getBrowser(): string
    {
        return $this->openPage('browser', 'file-manager');
    }

    #[Acl('Weline_FileManager::wls_file_manager_policy_page', 'View WLS File Policy', 'mdi mdi-shield-edit-outline', 'View WLS Panel file path policy')]
    public function getPolicyPage(): string
    {
        return $this->openPage('policy', 'path-policy');
    }

    #[Acl('Weline_FileManager::wls_file_manager_write_page', 'View WLS File Write Operations', 'mdi mdi-file-edit-outline', 'View WLS Panel file write operations')]
    public function getWritePage(): string
    {
        return $this->openPage('write', 'write-operations');
    }

    #[Acl('Weline_FileManager::wls_file_manager_queue_page', 'View WLS File Queue', 'mdi mdi-progress-clock', 'View WLS Panel file queue operations')]
    public function getQueuePage(): string
    {
        return $this->openPage('queue', 'file-queue');
    }

    #[Acl('Weline_FileManager::wls_file_manager_log_page', 'View WLS File Operation Log', 'mdi mdi-clipboard-text-clock-outline', 'View WLS Panel file operation log')]
    public function getLogPage(): string
    {
        return $this->openPage('audit', 'operation-log');
    }

    #[Acl('Weline_FileManager::wls_file_manager_capabilities_page', 'View WLS File Capabilities', 'mdi mdi-puzzle-outline', 'View WLS Panel file capabilities')]
    public function getCapabilitiesPage(): string
    {
        return $this->openPage('capabilities', 'capabilities');
    }

    private function openPage(string $pageKey, string $operation): string
    {
        $this->request->setGet('page_key', $pageKey);
        $this->request->setGet('operation', $operation);
        return $this->getIndex();
    }

    private function normalizePageKey(string $pageKey, string $operation): string
    {
        $pageKey = trim($pageKey);
        if (in_array($pageKey, ['roots', 'browser', 'policy', 'write', 'queue', 'audit', 'capabilities'], true)) {
            return $pageKey;
        }

        return match (trim($operation)) {
            'path-policy' => 'policy',
            'write-operations' => 'write',
            'file-queue', 'queue-operations' => 'queue',
            'operation-log' => 'audit',
            'capabilities' => 'capabilities',
            'file-manager', 'files.read' => 'browser',
            default => 'roots',
        };
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', '受控写入 WLS 文件', 'mdi-file-edit-outline', '创建目录和保存小文本文件')]
    public function postCreateDirectory(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#write-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $name = $this->safeNewEntryName((string)($post['directory_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);

        if ((string)($post['confirm_write'] ?? '0') !== '1') {
            $this->appendOperationLog('create_directory', 'denied', $rootKey, $relativePath, $name, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
            return '';
        }

        if ($name === '') {
            $this->appendOperationLog('create_directory', 'denied', $rootKey, $relativePath, '', 'invalid_name', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_name'], '#write-operations');
            return '';
        }

        $resolved = $this->resolveWritableDirectory($this->rootCards(), $rootKey, $relativePath);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog('create_directory', 'denied', $rootKey, $relativePath, $name, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#write-operations');
            return '';
        }

        $target = rtrim((string)$resolved['path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (!$this->isCandidateWithinRoot((string)$resolved['root_path'], $target)) {
            $this->appendOperationLog('create_directory', 'denied', $rootKey, $relativePath, $name, 'path_escape', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'path_escape'], '#write-operations');
            return '';
        }

        if (file_exists($target)) {
            $this->appendOperationLog('create_directory', 'denied', $rootKey, $relativePath, $name, 'target_exists', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'target_exists'], '#write-operations');
            return '';
        }

        if (!mkdir($target, 0775) && !is_dir($target)) {
            $this->appendOperationLog('create_directory', 'failed', $rootKey, $relativePath, $name, 'write_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'write_failed'], '#write-operations');
            return '';
        }

        $this->appendOperationLog('create_directory', 'success', $rootKey, $relativePath, $name, 'directory_created', $post);
        $this->redirectToFileManager($params + ['wfm_notice' => 'directory_created'], '#browser');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', '受控写入 WLS 文件', 'mdi-file-edit-outline', '创建目录和保存小文本文件')]
    public function postSaveText(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#write-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $fileName = $this->safeNewEntryName((string)($post['file_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $sourceEdit = (string)($post['source_edit'] ?? '0') === '1';
        $action = $sourceEdit ? 'save_source' : 'save_text';
        $requiredPhrase = $sourceEdit ? self::SOURCE_EDIT_CONFIRM_PHRASE : 'SAVE_TEXT';

        if ((string)($post['confirm_write'] ?? '0') !== '1' || trim((string)($post['confirm_phrase'] ?? '')) !== $requiredPhrase) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
            return '';
        }

        if ($fileName === '' || (!$sourceEdit && !$this->isAllowedTextFileName($fileName)) || ($sourceEdit && !$this->isAllowedSourceFileName($fileName))) {
            $errorCode = $sourceEdit ? 'invalid_source_file' : 'invalid_text_file';
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, $errorCode, $post);
            $this->redirectToFileManager($params + ['wfm_error' => $errorCode], '#write-operations');
            return '';
        }

        $content = (string)($post['file_content'] ?? '');
        if (strlen($content) > self::MAX_TEXT_SAVE_BYTES || str_contains($content, "\0")) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'invalid_text_content', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_text_content'], '#write-operations');
            return '';
        }

        $resolved = $sourceEdit
            ? $this->resolveSourceEditableFile($this->rootCards(), $rootKey, $relativePath, $fileName)
            : $this->resolveWritableDirectory($this->rootCards(), $rootKey, $relativePath);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#write-operations');
            return '';
        }

        $target = $sourceEdit ? (string)$resolved['path'] : rtrim((string)$resolved['path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        if (!$this->isCandidateWithinRoot((string)$resolved['root_path'], $target)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'path_escape', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'path_escape'], '#write-operations');
            return '';
        }

        $exists = file_exists($target);
        if ($exists && (!is_file($target) || !is_writable($target))) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'target_not_writable', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'target_not_writable'], '#write-operations');
            return '';
        }

        if ($exists && (string)($post['overwrite_existing'] ?? '0') !== '1') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'overwrite_required', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'overwrite_required'], '#write-operations');
            return '';
        }

        if ($sourceEdit && !$exists) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'source_edit_existing_file_required', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'source_edit_existing_file_required'], '#write-operations');
            return '';
        }

        if (file_put_contents($target, $content, LOCK_EX) === false) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $fileName, 'write_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'write_failed'], '#write-operations');
            return '';
        }

        $notice = $sourceEdit ? 'source_saved' : 'text_saved';
        $this->appendOperationLog($action, 'success', $rootKey, $relativePath, $fileName, $notice, $post);
        $this->redirectToFileManager($params + ['wfm_notice' => $notice], '#browser');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', '受控写入 WLS 文件', 'mdi-file-plus-outline', '创建受控源码文件')]
    public function postSourceCreate(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#write-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $fileName = $this->safeNewEntryName((string)($post['source_file_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $action = 'source_create';

        if ((string)($post['confirm_write'] ?? '0') !== '1'
            || trim((string)($post['confirm_phrase'] ?? '')) !== self::SOURCE_CREATE_CONFIRM_PHRASE
        ) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
            return '';
        }

        if ($fileName === '' || !$this->isAllowedSourceFileName($fileName)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'invalid_source_file', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_source_file'], '#write-operations');
            return '';
        }

        $content = (string)($post['source_file_content'] ?? '');
        if (strlen($content) > self::MAX_TEXT_SAVE_BYTES || str_contains($content, "\0")) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'invalid_text_content', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_text_content'], '#write-operations');
            return '';
        }

        $resolved = $this->resolveSourceCreateDirectory($this->rootCards(), $rootKey, $relativePath, $fileName);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#write-operations');
            return '';
        }

        $target = rtrim((string)$resolved['path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        if (!$this->isCandidateWithinRoot((string)$resolved['root_path'], $target)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'path_escape', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'path_escape'], '#write-operations');
            return '';
        }

        if (file_exists($target)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'target_exists', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'target_exists'], '#write-operations');
            return '';
        }

        if (file_put_contents($target, $content, LOCK_EX) === false) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $fileName, 'write_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'write_failed'], '#write-operations');
            return '';
        }

        $this->appendOperationLog($action, 'success', $rootKey, $relativePath, $fileName, 'source_created', $post);
        $this->redirectToFileManager($params + ['wfm_notice' => 'source_created'], '#browser');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', '受控写入 WLS 文件', 'mdi-file-edit-outline', '重命名受控源码文件')]
    public function postSourceRename(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#write-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $fileName = $this->safeNewEntryName((string)($post['source_file_name'] ?? ''));
        $newName = $this->safeNewEntryName((string)($post['source_new_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $action = 'source_rename';
        $targetLabel = trim($fileName . ' -> ' . $newName);

        if ((string)($post['confirm_write'] ?? '0') !== '1'
            || trim((string)($post['confirm_phrase'] ?? '')) !== self::SOURCE_RENAME_CONFIRM_PHRASE
        ) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $targetLabel, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
            return '';
        }

        if (
            $fileName === ''
            || $newName === ''
            || !$this->isAllowedSourceFileName($fileName)
            || !$this->isAllowedSourceFileName($newName)
        ) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $targetLabel, 'invalid_source_file', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_source_file'], '#write-operations');
            return '';
        }

        if ($fileName === $newName) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $targetLabel, 'source_rename_same_name', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'source_rename_same_name'], '#write-operations');
            return '';
        }

        $resolved = $this->resolveSourceRenameEntry($this->rootCards(), $rootKey, $relativePath, $fileName, $newName);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $targetLabel, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#write-operations');
            return '';
        }

        if (!rename((string)$resolved['source_path'], (string)$resolved['target_path'])) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $targetLabel, 'rename_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'rename_failed'], '#write-operations');
            return '';
        }

        $this->appendOperationLog($action, 'success', $rootKey, $relativePath, $targetLabel, 'source_renamed', $post);
        $this->redirectToFileManager($params + ['wfm_notice' => 'source_renamed'], '#browser');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', '受控回收 WLS 源码文件', 'mdi-delete-clock-outline', '将受控源码文件移动到可恢复回收站')]
    public function postSourceTrash(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#write-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $fileName = $this->safeNewEntryName((string)($post['source_file_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $action = 'source_trash';

        if ((string)($post['confirm_write'] ?? '0') !== '1'
            || trim((string)($post['confirm_phrase'] ?? '')) !== self::SOURCE_TRASH_CONFIRM_PHRASE
        ) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
            return '';
        }

        if ($fileName === '' || !$this->isAllowedSourceFileName($fileName)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'invalid_source_file', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_source_file'], '#write-operations');
            return '';
        }

        $resolved = $this->resolveSourceTrashEntry($this->rootCards(), $rootKey, $relativePath, $fileName);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#write-operations');
            return '';
        }

        /** @var WlsFileManagerLargeOperationService $service */
        $service = ObjectManager::getInstance(WlsFileManagerLargeOperationService::class);
        $result = $service->moveToTrash(
            (string)$resolved['source_path'],
            (string)$resolved['root_path'],
            (string)$resolved['source_relative_path'],
            1,
            self::MAX_TEXT_SAVE_BYTES
        );

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'trash_move_failed');
            $resultType = (string)($result['result'] ?? 'failed');
            $resultType = in_array($resultType, ['denied', 'failed'], true) ? $resultType : 'failed';
            $this->appendOperationLog($action, $resultType, $rootKey, $relativePath, $fileName, $errorCode, $post);
            $this->redirectToFileManager($params + ['wfm_error' => $errorCode], '#write-operations');
            return '';
        }

        $trashRelativePath = trim((string)($result['target_relative_path'] ?? ''));
        $targetLabel = $trashRelativePath !== '' ? $fileName . ' -> ' . $trashRelativePath : $fileName;
        $this->appendOperationLog($action, 'success', $rootKey, $relativePath, $targetLabel, 'source_trashed', $post);
        $this->redirectToFileManager($params + ['wfm_notice' => 'source_trashed'], '#browser');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', '队列回收 WLS 源码文件', 'mdi-progress-clock', '将受控源码文件通过队列移动到可恢复回收站')]
    public function postSourceTrashQueue(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#queue-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $fileName = $this->safeNewEntryName((string)($post['source_file_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $action = 'source_trash_queue';

        if ((string)($post['confirm_write'] ?? '0') !== '1'
            || trim((string)($post['confirm_phrase'] ?? '')) !== self::SOURCE_QUEUE_TRASH_CONFIRM_PHRASE
        ) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#queue-operations');
            return '';
        }

        if ($fileName === '' || !$this->isAllowedSourceFileName($fileName)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'invalid_source_file', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_source_file'], '#queue-operations');
            return '';
        }

        $resolved = $this->resolveSourceTrashEntry($this->rootCards(), $rootKey, $relativePath, $fileName);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#queue-operations');
            return '';
        }

        $sourcePath = (string)$resolved['source_path'];
        $bizKey = 'wls_file_manager_source_trash:' . sha1(implode('|', [
            (string)$resolved['root_path'],
            $sourcePath,
        ]));

        try {
            $existingQueue = \w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]);
        } catch (\Throwable) {
            $existingQueue = null;
        }

        if (is_array($existingQueue) && in_array((string)($existingQueue['status'] ?? ''), ['pending', 'running'], true)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'source_trash_queue_already_pending', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'source_trash_queue_already_pending'], '#queue-operations');
            return '';
        }

        $payload = [
            'operation' => WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_TRASH_ENTRY,
            'source_policy' => true,
            'root_key' => $rootKey,
            'root_path' => (string)$resolved['root_path'],
            'source_path' => $sourcePath,
            'source_relative_path' => (string)$resolved['source_relative_path'],
            'source_parent_relative_path' => trim($relativePath, '/'),
            'project_id' => trim((string)($post['project_id'] ?? '')),
            'domain' => trim((string)($post['domain'] ?? '')),
            'project_type' => trim((string)($post['project_type'] ?? '')),
            'requested_at' => date('Y-m-d H:i:s'),
            'requested_ip' => $this->clientIp(),
            'max_entries' => 1,
            'max_bytes' => self::MAX_TEXT_SAVE_BYTES,
        ];

        try {
            $queueResult = \w_query('queue', 'create', [
                'class' => WlsFileManagerLargeOperationQueue::class,
                'name' => (string)__('WLS 文件管理器源码回收：%{1}', [(string)$resolved['source_relative_path']]),
                'module' => 'Weline_FileManager',
                'content' => $payload,
                'auto' => true,
                'biz_key' => $bizKey,
            ]);
        } catch (\Throwable) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $fileName, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $queueId = is_array($queueResult) ? (int)($queueResult['queue_id'] ?? 0) : 0;
        if ($queueId <= 0) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $fileName, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $this->appendOperationLog(
            $action,
            'success',
            $rootKey,
            $relativePath,
            $fileName,
            'source_trash_queue_created',
            $post + ['queue_id' => (string)$queueId]
        );
        $this->redirectToFileManager($params + ['wfm_notice' => 'source_trash_queue_created'], '#queue-operations');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Queue WLS source file archive', 'mdi-archive-arrow-down-outline', 'Archive one controlled source file through queue')]
    public function postSourceArchiveQueue(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#queue-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $fileName = $this->safeNewEntryName((string)($post['source_file_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $action = 'source_archive_queue';

        if ((string)($post['confirm_write'] ?? '0') !== '1'
            || trim((string)($post['confirm_phrase'] ?? '')) !== self::SOURCE_QUEUE_ARCHIVE_CONFIRM_PHRASE
        ) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#queue-operations');
            return '';
        }

        if ($fileName === '' || !$this->isAllowedSourceFileName($fileName)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, 'invalid_source_file', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_source_file'], '#queue-operations');
            return '';
        }

        $resolved = $this->resolveSourceArchiveEntry($this->rootCards(), $rootKey, $relativePath, $fileName);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $fileName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#queue-operations');
            return '';
        }

        $archiveDirectory = $this->ensureSourceArchiveDirectory();
        if ($archiveDirectory['error_code'] !== '') {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $fileName, (string)$archiveDirectory['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$archiveDirectory['error_code']], '#queue-operations');
            return '';
        }

        $archiveName = $this->buildSourceArchiveName((string)$resolved['source_relative_path']);
        $targetPath = rtrim((string)$archiveDirectory['path'], "\\/") . DIRECTORY_SEPARATOR . $archiveName;
        $sourcePath = (string)$resolved['source_path'];
        $bizKey = 'wls_file_manager_source_archive:' . sha1(implode('|', [
            (string)$resolved['root_path'],
            $sourcePath,
            $targetPath,
        ]));

        $payload = [
            'operation' => WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_ARCHIVE_FILE,
            'source_policy' => true,
            'root_key' => $rootKey,
            'root_path' => (string)$resolved['root_path'],
            'source_path' => $sourcePath,
            'source_relative_path' => (string)$resolved['source_relative_path'],
            'source_parent_relative_path' => trim($relativePath, '/'),
            'archive_root_path' => (string)$archiveDirectory['path'],
            'target_path' => $targetPath,
            'target_relative_path' => $archiveName,
            'project_id' => trim((string)($post['project_id'] ?? '')),
            'domain' => trim((string)($post['domain'] ?? '')),
            'project_type' => trim((string)($post['project_type'] ?? '')),
            'requested_at' => date('Y-m-d H:i:s'),
            'requested_ip' => $this->clientIp(),
            'max_entries' => 1,
            'max_bytes' => self::MAX_TEXT_SAVE_BYTES,
        ];

        try {
            $queueResult = \w_query('queue', 'create', [
                'class' => WlsFileManagerLargeOperationQueue::class,
                'name' => (string)__('WLS source archive: %{1}', [(string)$resolved['source_relative_path']]),
                'module' => 'Weline_FileManager',
                'content' => $payload,
                'auto' => true,
                'biz_key' => $bizKey,
            ]);
        } catch (\Throwable) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $fileName, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $queueId = is_array($queueResult) ? (int)($queueResult['queue_id'] ?? 0) : 0;
        if ($queueId <= 0) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $fileName, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $this->appendOperationLog(
            $action,
            'success',
            $rootKey,
            $relativePath,
            $fileName . ' -> ' . $archiveName,
            'source_archive_queue_created',
            $post + ['queue_id' => (string)$queueId]
        );
        $this->redirectToFileManager($params + ['wfm_notice' => 'source_archive_queue_created'], '#queue-operations');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Queue WLS source directory archive', 'mdi-archive-arrow-down-outline', 'Archive one controlled source directory through queue')]
    public function postSourceArchiveTreeQueue(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#queue-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $directoryName = $this->safeNewEntryName((string)($post['source_directory_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $action = 'source_archive_tree_queue';

        if ((string)($post['confirm_write'] ?? '0') !== '1'
            || trim((string)($post['confirm_phrase'] ?? '')) !== self::SOURCE_QUEUE_ARCHIVE_TREE_CONFIRM_PHRASE
        ) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $directoryName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#queue-operations');
            return '';
        }

        if ($directoryName === '') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $directoryName, 'invalid_name', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_name'], '#queue-operations');
            return '';
        }

        $resolved = $this->resolveSourceArchiveTreeEntry($this->rootCards(), $rootKey, $relativePath, $directoryName);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $directoryName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#queue-operations');
            return '';
        }

        $archiveDirectory = $this->ensureSourceArchiveDirectory();
        if ($archiveDirectory['error_code'] !== '') {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $directoryName, (string)$archiveDirectory['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$archiveDirectory['error_code']], '#queue-operations');
            return '';
        }

        $archiveName = $this->buildSourceArchiveName((string)$resolved['source_relative_path']);
        $targetPath = rtrim((string)$archiveDirectory['path'], "\\/") . DIRECTORY_SEPARATOR . $archiveName;
        $sourcePath = (string)$resolved['source_path'];
        $bizKey = 'wls_file_manager_source_archive_tree:' . sha1(implode('|', [
            (string)$resolved['root_path'],
            $sourcePath,
        ]));

        try {
            $existingQueue = \w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]);
        } catch (\Throwable) {
            $existingQueue = null;
        }

        if (is_array($existingQueue) && in_array((string)($existingQueue['status'] ?? ''), ['pending', 'running'], true)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $directoryName, 'source_archive_tree_queue_already_pending', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'source_archive_tree_queue_already_pending'], '#queue-operations');
            return '';
        }

        $payload = [
            'operation' => WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_ARCHIVE_TREE,
            'source_policy' => true,
            'root_key' => $rootKey,
            'root_path' => (string)$resolved['root_path'],
            'source_path' => $sourcePath,
            'source_relative_path' => (string)$resolved['source_relative_path'],
            'source_parent_relative_path' => trim($relativePath, '/'),
            'archive_root_path' => (string)$archiveDirectory['path'],
            'target_path' => $targetPath,
            'target_relative_path' => $archiveName,
            'project_id' => trim((string)($post['project_id'] ?? '')),
            'domain' => trim((string)($post['domain'] ?? '')),
            'project_type' => trim((string)($post['project_type'] ?? '')),
            'requested_at' => date('Y-m-d H:i:s'),
            'requested_ip' => $this->clientIp(),
            'max_entries' => self::MAX_COMPRESS_ENTRIES,
            'max_bytes' => self::MAX_COMPRESS_BYTES,
        ];

        try {
            $queueResult = \w_query('queue', 'create', [
                'class' => WlsFileManagerLargeOperationQueue::class,
                'name' => (string)__('WLS source directory archive: %{1}', [(string)$resolved['source_relative_path']]),
                'module' => 'Weline_FileManager',
                'content' => $payload,
                'auto' => true,
                'biz_key' => $bizKey,
            ]);
        } catch (\Throwable) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $directoryName, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $queueId = is_array($queueResult) ? (int)($queueResult['queue_id'] ?? 0) : 0;
        if ($queueId <= 0) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $directoryName, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $this->appendOperationLog(
            $action,
            'success',
            $rootKey,
            $relativePath,
            $directoryName . ' -> ' . $archiveName,
            'source_archive_tree_queue_created',
            $post + ['queue_id' => (string)$queueId]
        );
        $this->redirectToFileManager($params + ['wfm_notice' => 'source_archive_tree_queue_created'], '#queue-operations');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Queue WLS source selection archive', 'mdi-archive-check-outline', 'Archive selected controlled source entries through queue')]
    public function postSourceArchiveSelectionQueue(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#queue-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $action = 'source_archive_selection_queue';
        $selection = $this->sourceSelectionEntryNames((string)($post['source_entry_names'] ?? ''));
        $entryNames = (array)($selection['names'] ?? []);
        $entryLabel = implode(', ', $entryNames);

        if ((string)($post['confirm_write'] ?? '0') !== '1'
            || trim((string)($post['confirm_phrase'] ?? '')) !== self::SOURCE_QUEUE_ARCHIVE_SELECTION_CONFIRM_PHRASE
        ) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $entryLabel, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#queue-operations');
            return '';
        }

        if (!empty($selection['invalid']) || $entryNames === []) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $entryLabel, 'invalid_source_selection', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_source_selection'], '#queue-operations');
            return '';
        }

        if (!empty($selection['too_many'])) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $entryLabel, 'source_archive_selection_too_many', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'source_archive_selection_too_many'], '#queue-operations');
            return '';
        }

        $resolved = $this->resolveSourceArchiveSelectionEntries($this->rootCards(), $rootKey, $relativePath, $entryNames);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $entryLabel, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#queue-operations');
            return '';
        }

        $archiveDirectory = $this->ensureSourceArchiveDirectory();
        if ($archiveDirectory['error_code'] !== '') {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $entryLabel, (string)$archiveDirectory['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$archiveDirectory['error_code']], '#queue-operations');
            return '';
        }

        $sourceEntries = (array)$resolved['source_entries'];
        $selectionKey = implode('|', array_map(
            static fn (array $entry): string => (string)($entry['source_relative_path'] ?? ''),
            $sourceEntries
        ));
        $archiveName = $this->buildSourceArchiveName('selection-' . substr(sha1($selectionKey), 0, 12));
        $targetPath = rtrim((string)$archiveDirectory['path'], "\\/") . DIRECTORY_SEPARATOR . $archiveName;
        $bizKey = 'wls_file_manager_source_archive_selection:' . sha1(implode('|', [
            (string)$resolved['root_path'],
            (string)$resolved['source_parent_relative_path'],
            $selectionKey,
        ]));

        try {
            $existingQueue = \w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]);
        } catch (\Throwable) {
            $existingQueue = null;
        }

        if (is_array($existingQueue) && in_array((string)($existingQueue['status'] ?? ''), ['pending', 'running'], true)) {
            $this->appendOperationLog($action, 'denied', $rootKey, $relativePath, $entryLabel, 'source_archive_selection_queue_already_pending', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'source_archive_selection_queue_already_pending'], '#queue-operations');
            return '';
        }

        $payload = [
            'operation' => WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_ARCHIVE_SELECTION,
            'source_policy' => true,
            'root_key' => $rootKey,
            'root_path' => (string)$resolved['root_path'],
            'source_parent_path' => (string)$resolved['source_parent_path'],
            'source_parent_relative_path' => (string)$resolved['source_parent_relative_path'],
            'source_entries' => $sourceEntries,
            'archive_root_path' => (string)$archiveDirectory['path'],
            'target_path' => $targetPath,
            'target_relative_path' => $archiveName,
            'project_id' => trim((string)($post['project_id'] ?? '')),
            'domain' => trim((string)($post['domain'] ?? '')),
            'project_type' => trim((string)($post['project_type'] ?? '')),
            'requested_at' => date('Y-m-d H:i:s'),
            'requested_ip' => $this->clientIp(),
            'max_selected' => self::SOURCE_ARCHIVE_SELECTION_MAX_ITEMS,
            'max_entries' => self::MAX_COMPRESS_ENTRIES,
            'max_bytes' => self::MAX_COMPRESS_BYTES,
        ];

        try {
            $queueResult = \w_query('queue', 'create', [
                'class' => WlsFileManagerLargeOperationQueue::class,
                'name' => (string)__('WLS source selection archive: %{1}', [
                    (string)$resolved['source_parent_relative_path'] !== '' ? (string)$resolved['source_parent_relative_path'] : '/',
                ]),
                'module' => 'Weline_FileManager',
                'content' => $payload,
                'auto' => true,
                'biz_key' => $bizKey,
            ]);
        } catch (\Throwable) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $entryLabel, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $queueId = is_array($queueResult) ? (int)($queueResult['queue_id'] ?? 0) : 0;
        if ($queueId <= 0) {
            $this->appendOperationLog($action, 'failed', $rootKey, $relativePath, $entryLabel, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $this->appendOperationLog(
            $action,
            'success',
            $rootKey,
            $relativePath,
            $entryLabel . ' -> ' . $archiveName,
            'source_archive_selection_queue_created',
            $post + ['queue_id' => (string)$queueId]
        );
        $this->redirectToFileManager($params + ['wfm_notice' => 'source_archive_selection_queue_created'], '#queue-operations');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_download', '下载 WLS 文件', 'mdi-download-outline', '下载 WLS 面板文件管理器文件')]
    public function getDownload(): Response
    {
        $resolved = $this->resolveRequestedFile($this->rootCards(), 'file');
        if ($resolved['error'] !== '') {
            return Response::text($resolved['error'], 400);
        }

        $path = (string)$resolved['path'];
        if (!is_file($path) || !is_readable($path)) {
            return Response::text((string)__('文件不可读取。'), 403);
        }

        $fileSize = $this->fileSizeBytes($path);
        if ($fileSize === null) {
            return Response::text((string)__('文件不可读取。'), 500);
        }

        if ($fileSize > self::MAX_DOWNLOAD_BYTES) {
            return Response::text((string)__('文件超过下载大小限制。'), 413);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return Response::text((string)__('文件不可读取。'), 500);
        }

        $response = Response::fromContent($content, 200, $this->mimeType($path));
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $this->safeDownloadName($path) . '"');
        $response->setHeader('Content-Length', (string)strlen($content));
        $response->setHeader('Cache-Control', 'no-store, max-age=0');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Controlled WLS file write', 'mdi-upload-outline', 'Upload controlled WLS files')]
    public function postUpload(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#write-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $upload = $this->request->getFile('upload_file');
        if (!is_array($upload)) {
            $upload = WelineEnv::getFiles('upload_file');
        }
        $uploadName = is_array($upload) ? $this->safeNewEntryName((string)($upload['name'] ?? '')) : '';

        if ((string)($post['confirm_write'] ?? '0') !== '1' || trim((string)($post['confirm_phrase'] ?? '')) !== 'UPLOAD_FILE') {
            $this->appendOperationLog('upload_file', 'denied', $rootKey, $relativePath, $uploadName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
            return '';
        }

        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->appendOperationLog('upload_file', 'denied', $rootKey, $relativePath, $uploadName, 'uploaded_file_invalid', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'uploaded_file_invalid'], '#write-operations');
            return '';
        }

        if ($uploadName === '' || !$this->isAllowedUploadFileName($uploadName)) {
            $this->appendOperationLog('upload_file', 'denied', $rootKey, $relativePath, $uploadName, 'invalid_upload_file', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_upload_file'], '#write-operations');
            return '';
        }

        $uploadSize = (int)($upload['size'] ?? 0);
        if ($uploadSize <= 0 || $uploadSize > self::MAX_UPLOAD_BYTES) {
            $this->appendOperationLog('upload_file', 'denied', $rootKey, $relativePath, $uploadName, 'uploaded_file_too_large', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'uploaded_file_too_large'], '#write-operations');
            return '';
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName) || !is_readable($tmpName)) {
            $this->appendOperationLog('upload_file', 'denied', $rootKey, $relativePath, $uploadName, 'uploaded_file_invalid', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'uploaded_file_invalid'], '#write-operations');
            return '';
        }

        $resolved = $this->resolveWritableDirectory($this->rootCards(), $rootKey, $relativePath);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog('upload_file', 'denied', $rootKey, $relativePath, $uploadName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#write-operations');
            return '';
        }

        $target = rtrim((string)$resolved['path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $uploadName;
        if (!$this->isCandidateWithinRoot((string)$resolved['root_path'], $target)) {
            $this->appendOperationLog('upload_file', 'denied', $rootKey, $relativePath, $uploadName, 'path_escape', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'path_escape'], '#write-operations');
            return '';
        }

        $exists = file_exists($target);
        if ($exists && (!is_file($target) || !is_writable($target))) {
            $this->appendOperationLog('upload_file', 'denied', $rootKey, $relativePath, $uploadName, 'target_not_writable', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'target_not_writable'], '#write-operations');
            return '';
        }

        if ($exists && (string)($post['overwrite_existing'] ?? '0') !== '1') {
            $this->appendOperationLog('upload_file', 'denied', $rootKey, $relativePath, $uploadName, 'overwrite_required', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'overwrite_required'], '#write-operations');
            return '';
        }

        if (!$this->moveUploadedFile($tmpName, $target)) {
            $this->appendOperationLog('upload_file', 'failed', $rootKey, $relativePath, $uploadName, 'upload_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'upload_failed'], '#write-operations');
            return '';
        }

        $this->appendOperationLog('upload_file', 'success', $rootKey, $relativePath, $uploadName, 'file_uploaded', $post);
        $this->redirectToFileManager($params + ['wfm_notice' => 'file_uploaded'], '#browser');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Controlled WLS file write', 'mdi-form-textbox', 'Rename controlled WLS files')]
    public function postRename(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#write-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $currentRelativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $entryRelativePath = $this->safeRelativePathForWrite((string)($post['entry_path'] ?? ''));
        $newName = $this->safeNewEntryName((string)($post['new_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $currentRelativePath);

        if ((string)($post['confirm_write'] ?? '0') !== '1' || trim((string)($post['confirm_phrase'] ?? '')) !== 'RENAME_ENTRY') {
            $this->appendOperationLog('rename_entry', 'denied', $rootKey, $entryRelativePath, $newName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
            return '';
        }

        if ($entryRelativePath === '' || $newName === '') {
            $this->appendOperationLog('rename_entry', 'denied', $rootKey, $entryRelativePath, $newName, 'invalid_name', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_name'], '#write-operations');
            return '';
        }

        $resolved = $this->resolveWritableEntry($this->rootCards(), $rootKey, $entryRelativePath);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog('rename_entry', 'denied', $rootKey, $entryRelativePath, $newName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#write-operations');
            return '';
        }

        $target = dirname((string)$resolved['path']) . DIRECTORY_SEPARATOR . $newName;
        if (!$this->isCandidateWithinRoot((string)$resolved['root_path'], $target)) {
            $this->appendOperationLog('rename_entry', 'denied', $rootKey, $entryRelativePath, $newName, 'path_escape', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'path_escape'], '#write-operations');
            return '';
        }

        if (file_exists($target)) {
            $this->appendOperationLog('rename_entry', 'denied', $rootKey, $entryRelativePath, $newName, 'target_exists', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'target_exists'], '#write-operations');
            return '';
        }

        if (!rename((string)$resolved['path'], $target)) {
            $this->appendOperationLog('rename_entry', 'failed', $rootKey, $entryRelativePath, $newName, 'rename_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'rename_failed'], '#write-operations');
            return '';
        }

        $targetParams = $this->redirectParamsFromInput($post, $rootKey, (string)$resolved['parent_path']);
        $this->appendOperationLog('rename_entry', 'success', $rootKey, $entryRelativePath, $newName, 'entry_renamed', $post);
        $this->redirectToFileManager($targetParams + ['wfm_notice' => 'entry_renamed'], '#browser');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Controlled WLS file write', 'mdi-delete-outline', 'Delete controlled WLS files')]
    public function postDelete(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#write-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $currentRelativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $entryRelativePath = $this->safeRelativePathForWrite((string)($post['entry_path'] ?? ''));
        $confirmationPhrase = trim((string)($post['confirm_phrase'] ?? ''));
        $recursiveRequested = (string)($post['delete_recursive'] ?? '0') === '1';
        $params = $this->redirectParamsFromInput($post, $rootKey, $currentRelativePath);

        if ((string)($post['confirm_write'] ?? '0') !== '1') {
            $this->appendOperationLog('delete_entry', 'denied', $rootKey, $entryRelativePath, $entryRelativePath, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
            return '';
        }

        if ($entryRelativePath === '') {
            $this->appendOperationLog('delete_entry', 'denied', $rootKey, $entryRelativePath, '', 'delete_root_forbidden', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'delete_root_forbidden'], '#write-operations');
            return '';
        }

        $resolved = $this->resolveWritableEntry($this->rootCards(), $rootKey, $entryRelativePath);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog('delete_entry', 'denied', $rootKey, $entryRelativePath, $entryRelativePath, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#write-operations');
            return '';
        }

        $path = (string)$resolved['path'];
        $deleted = false;
        if ((string)$resolved['type'] === 'directory') {
            if (!$this->directoryIsEmpty($path)) {
                if (!$recursiveRequested) {
                    $this->appendOperationLog('delete_entry', 'denied', $rootKey, $entryRelativePath, $entryRelativePath, 'directory_not_empty', $post);
                    $this->redirectToFileManager($params + ['wfm_error' => 'directory_not_empty'], '#write-operations');
                    return '';
                }

                if ($confirmationPhrase !== 'DELETE_TREE') {
                    $this->appendOperationLog('delete_tree', 'denied', $rootKey, $entryRelativePath, $entryRelativePath, 'recursive_delete_confirmation_required', $post);
                    $this->redirectToFileManager($params + ['wfm_error' => 'recursive_delete_confirmation_required'], '#write-operations');
                    return '';
                }

                if ($this->relativeCandidateIsSymlink((string)$resolved['root_path'], (string)$resolved['relative_path'])) {
                    $this->appendOperationLog('delete_tree', 'denied', $rootKey, $entryRelativePath, $entryRelativePath, 'delete_symlink_unsupported', $post);
                    $this->redirectToFileManager($params + ['wfm_error' => 'delete_symlink_unsupported'], '#write-operations');
                    return '';
                }

                $result = $this->deleteDirectoryTree($path, (string)$resolved['root_path']);
                if (!$result['success']) {
                    $resultCode = (string)$result['result'];
                    $this->appendOperationLog('delete_tree', $resultCode, $rootKey, $entryRelativePath, $entryRelativePath, (string)$result['error_code'], $post);
                    $this->redirectToFileManager($params + ['wfm_error' => (string)$result['error_code']], '#write-operations');
                    return '';
                }

                $targetParams = $this->redirectParamsFromInput($post, $rootKey, (string)$resolved['parent_path']);
                $this->appendOperationLog(
                    'delete_tree',
                    'success',
                    $rootKey,
                    $entryRelativePath,
                    $entryRelativePath,
                    'tree_deleted',
                    $post + [
                        'deleted_entries' => (string)$result['entries'],
                        'deleted_bytes' => (string)$result['bytes'],
                    ]
                );
                $this->redirectToFileManager($targetParams + ['wfm_notice' => 'tree_deleted'], '#browser');
                return '';
            }
            if ($confirmationPhrase !== 'DELETE_ENTRY') {
                $this->appendOperationLog('delete_entry', 'denied', $rootKey, $entryRelativePath, $entryRelativePath, 'missing_confirmation', $post);
                $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
                return '';
            }
            $deleted = rmdir($path);
        } elseif ((string)$resolved['type'] === 'file') {
            if ($confirmationPhrase !== 'DELETE_ENTRY') {
                $this->appendOperationLog('delete_entry', 'denied', $rootKey, $entryRelativePath, $entryRelativePath, 'missing_confirmation', $post);
                $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
                return '';
            }
            if ($this->relativeCandidateIsSymlink((string)$resolved['root_path'], (string)$resolved['relative_path'])) {
                $this->appendOperationLog('delete_entry', 'denied', $rootKey, $entryRelativePath, $entryRelativePath, 'delete_symlink_unsupported', $post);
                $this->redirectToFileManager($params + ['wfm_error' => 'delete_symlink_unsupported'], '#write-operations');
                return '';
            }
            $deleted = unlink($path);
        }

        if (!$deleted) {
            $this->appendOperationLog('delete_entry', 'failed', $rootKey, $entryRelativePath, $entryRelativePath, 'delete_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'delete_failed'], '#write-operations');
            return '';
        }

        $targetParams = $this->redirectParamsFromInput($post, $rootKey, (string)$resolved['parent_path']);
        $this->appendOperationLog('delete_entry', 'success', $rootKey, $entryRelativePath, $entryRelativePath, 'entry_deleted', $post);
        $this->redirectToFileManager($targetParams + ['wfm_notice' => 'entry_deleted'], '#browser');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Controlled WLS file write', 'mdi-archive-arrow-down-outline', 'Create guarded WLS ZIP archives')]
    public function postCompress(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#write-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $currentRelativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $entryRelativePath = $this->safeRelativePathForWrite((string)($post['entry_path'] ?? ''));
        $archiveName = $this->safeArchiveName((string)($post['archive_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $currentRelativePath);

        if ((string)($post['confirm_write'] ?? '0') !== '1' || trim((string)($post['confirm_phrase'] ?? '')) !== 'COMPRESS_ENTRY') {
            $this->appendOperationLog('compress_entry', 'denied', $rootKey, $entryRelativePath, $archiveName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#write-operations');
            return '';
        }

        if ($entryRelativePath === '') {
            $this->appendOperationLog('compress_entry', 'denied', $rootKey, $entryRelativePath, $archiveName, 'compress_root_forbidden', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'compress_root_forbidden'], '#write-operations');
            return '';
        }

        if ($archiveName === '') {
            $this->appendOperationLog('compress_entry', 'denied', $rootKey, $entryRelativePath, '', 'invalid_archive_name', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_archive_name'], '#write-operations');
            return '';
        }

        $resolved = $this->resolveWritableEntry($this->rootCards(), $rootKey, $entryRelativePath);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog('compress_entry', 'denied', $rootKey, $entryRelativePath, $archiveName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#write-operations');
            return '';
        }

        $sourcePath = (string)$resolved['path'];
        if (!is_readable($sourcePath)) {
            $this->appendOperationLog('compress_entry', 'denied', $rootKey, $entryRelativePath, $archiveName, 'entry_not_readable', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'entry_not_readable'], '#write-operations');
            return '';
        }

        $targetPath = dirname($sourcePath) . DIRECTORY_SEPARATOR . $archiveName;
        if (!$this->isCandidateWithinRoot((string)$resolved['root_path'], $targetPath)) {
            $this->appendOperationLog('compress_entry', 'denied', $rootKey, $entryRelativePath, $archiveName, 'path_escape', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'path_escape'], '#write-operations');
            return '';
        }

        if (file_exists($targetPath)) {
            $this->appendOperationLog('compress_entry', 'denied', $rootKey, $entryRelativePath, $archiveName, 'archive_target_exists', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'archive_target_exists'], '#write-operations');
            return '';
        }

        $result = $this->createZipArchive($sourcePath, $targetPath, (string)$resolved['root_path']);
        if (!$result['success']) {
            $resultCode = (string)$result['result'];
            $this->appendOperationLog('compress_entry', $resultCode, $rootKey, $entryRelativePath, $archiveName, (string)$result['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$result['error_code']], '#write-operations');
            return '';
        }

        $targetParams = $this->redirectParamsFromInput($post, $rootKey, (string)$resolved['parent_path']);
        $this->appendOperationLog(
            'compress_entry',
            'success',
            $rootKey,
            $entryRelativePath,
            $archiveName,
            'archive_created',
            $post + [
                'archive_entries' => (string)$result['entries'],
                'archive_bytes' => (string)$result['bytes'],
            ]
        );
        $this->redirectToFileManager($targetParams + ['wfm_notice' => 'archive_created'], '#browser');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Queue WLS large ZIP archive', 'mdi-progress-clock', 'Create queued WLS file archives')]
    public function postCompressQueue(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#queue-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $currentRelativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $entryRelativePath = $this->safeRelativePathForWrite((string)($post['entry_path'] ?? ''));
        $archiveName = $this->safeArchiveName((string)($post['archive_name'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $currentRelativePath);

        if ((string)($post['confirm_write'] ?? '0') !== '1' || trim((string)($post['confirm_phrase'] ?? '')) !== 'QUEUE_COMPRESS') {
            $this->appendOperationLog('compress_queue', 'denied', $rootKey, $entryRelativePath, $archiveName, 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#queue-operations');
            return '';
        }

        if ($entryRelativePath === '') {
            $this->appendOperationLog('compress_queue', 'denied', $rootKey, $entryRelativePath, $archiveName, 'compress_root_forbidden', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'compress_root_forbidden'], '#queue-operations');
            return '';
        }

        if ($archiveName === '') {
            $this->appendOperationLog('compress_queue', 'denied', $rootKey, $entryRelativePath, '', 'invalid_archive_name', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_archive_name'], '#queue-operations');
            return '';
        }

        $resolved = $this->resolveWritableEntry($this->rootCards(), $rootKey, $entryRelativePath);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog('compress_queue', 'denied', $rootKey, $entryRelativePath, $archiveName, (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#queue-operations');
            return '';
        }

        $sourcePath = (string)$resolved['path'];
        if (!is_readable($sourcePath)) {
            $this->appendOperationLog('compress_queue', 'denied', $rootKey, $entryRelativePath, $archiveName, 'entry_not_readable', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'entry_not_readable'], '#queue-operations');
            return '';
        }

        $targetPath = dirname($sourcePath) . DIRECTORY_SEPARATOR . $archiveName;
        if (!$this->isCandidateWithinRoot((string)$resolved['root_path'], $targetPath)) {
            $this->appendOperationLog('compress_queue', 'denied', $rootKey, $entryRelativePath, $archiveName, 'path_escape', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'path_escape'], '#queue-operations');
            return '';
        }

        if (file_exists($targetPath)) {
            $this->appendOperationLog('compress_queue', 'denied', $rootKey, $entryRelativePath, $archiveName, 'archive_target_exists', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'archive_target_exists'], '#queue-operations');
            return '';
        }

        $targetRelativePath = trim((string)$resolved['parent_path'] . '/' . $archiveName, '/');
        $bizKey = 'wls_file_manager_large:' . sha1(implode('|', [
            (string)$resolved['root_path'],
            $sourcePath,
            $targetPath,
        ]));

        try {
            $existingQueue = \w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]);
        } catch (\Throwable) {
            $existingQueue = null;
        }

        if (is_array($existingQueue) && in_array((string)($existingQueue['status'] ?? ''), ['pending', 'running'], true)) {
            $this->appendOperationLog('compress_queue', 'denied', $rootKey, $entryRelativePath, $archiveName, 'queue_already_pending', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_already_pending'], '#queue-operations');
            return '';
        }

        $payload = [
            'operation' => WlsFileManagerLargeOperationQueue::OPERATION_COMPRESS_ZIP,
            'root_key' => $rootKey,
            'root_path' => (string)$resolved['root_path'],
            'source_path' => $sourcePath,
            'source_relative_path' => (string)$resolved['relative_path'],
            'target_path' => $targetPath,
            'target_relative_path' => $targetRelativePath,
            'archive_name' => $archiveName,
            'project_id' => trim((string)($post['project_id'] ?? '')),
            'domain' => trim((string)($post['domain'] ?? '')),
            'project_type' => trim((string)($post['project_type'] ?? '')),
            'requested_at' => date('Y-m-d H:i:s'),
            'requested_ip' => $this->clientIp(),
            'max_entries' => WlsFileManagerLargeOperationService::DEFAULT_MAX_ZIP_ENTRIES,
            'max_bytes' => WlsFileManagerLargeOperationService::DEFAULT_MAX_ZIP_BYTES,
        ];

        try {
            $queueResult = \w_query('queue', 'create', [
                'class' => WlsFileManagerLargeOperationQueue::class,
                'name' => (string)__('WLS 文件管理器压缩：%{1}', [$entryRelativePath]),
                'module' => 'Weline_FileManager',
                'content' => $payload,
                'auto' => true,
                'biz_key' => $bizKey,
            ]);
        } catch (\Throwable) {
            $this->appendOperationLog('compress_queue', 'failed', $rootKey, $entryRelativePath, $archiveName, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $queueId = is_array($queueResult) ? (int)($queueResult['queue_id'] ?? 0) : 0;
        if ($queueId <= 0) {
            $this->appendOperationLog('compress_queue', 'failed', $rootKey, $entryRelativePath, $archiveName, 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $this->appendOperationLog(
            'compress_queue',
            'success',
            $rootKey,
            $entryRelativePath,
            $archiveName,
            'queue_created',
            $post + ['queue_id' => (string)$queueId]
        );
        $this->redirectToFileManager($params + ['wfm_notice' => 'queue_created'], '#queue-operations');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Queue recoverable WLS trash move', 'mdi-delete-clock-outline', 'Move controlled WLS files to recoverable trash through queue')]
    public function postTrashQueue(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#queue-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $currentRelativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $entryRelativePath = $this->safeRelativePathForWrite((string)($post['entry_path'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $currentRelativePath);

        if ((string)($post['confirm_write'] ?? '0') !== '1' || trim((string)($post['confirm_phrase'] ?? '')) !== 'QUEUE_TRASH') {
            $this->appendOperationLog('trash_queue', 'denied', $rootKey, $entryRelativePath, '.wls-trash', 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#queue-operations');
            return '';
        }

        if ($entryRelativePath === '' || str_starts_with($entryRelativePath . '/', '.wls-trash/')) {
            $this->appendOperationLog('trash_queue', 'denied', $rootKey, $entryRelativePath, '.wls-trash', 'trash_source_forbidden', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'trash_source_forbidden'], '#queue-operations');
            return '';
        }

        $resolved = $this->resolveWritableEntry($this->rootCards(), $rootKey, $entryRelativePath);
        if ($resolved['error'] !== '') {
            $this->appendOperationLog('trash_queue', 'denied', $rootKey, $entryRelativePath, '.wls-trash', (string)$resolved['error_code'], $post);
            $this->redirectToFileManager($params + ['wfm_error' => (string)$resolved['error_code']], '#queue-operations');
            return '';
        }

        $sourcePath = (string)$resolved['path'];
        if ($this->relativeCandidateIsSymlink((string)$resolved['root_path'], (string)$resolved['relative_path'])) {
            $this->appendOperationLog('trash_queue', 'denied', $rootKey, $entryRelativePath, '.wls-trash', 'trash_symlink_unsupported', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'trash_symlink_unsupported'], '#queue-operations');
            return '';
        }

        $bizKey = 'wls_file_manager_trash:' . sha1(implode('|', [
            (string)$resolved['root_path'],
            $sourcePath,
        ]));

        try {
            $existingQueue = \w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]);
        } catch (\Throwable) {
            $existingQueue = null;
        }

        if (is_array($existingQueue) && in_array((string)($existingQueue['status'] ?? ''), ['pending', 'running'], true)) {
            $this->appendOperationLog('trash_queue', 'denied', $rootKey, $entryRelativePath, '.wls-trash', 'trash_queue_already_pending', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'trash_queue_already_pending'], '#queue-operations');
            return '';
        }

        $payload = [
            'operation' => WlsFileManagerLargeOperationQueue::OPERATION_TRASH_ENTRY,
            'root_key' => $rootKey,
            'root_path' => (string)$resolved['root_path'],
            'source_path' => $sourcePath,
            'source_relative_path' => (string)$resolved['relative_path'],
            'source_parent_relative_path' => (string)$resolved['parent_path'],
            'project_id' => trim((string)($post['project_id'] ?? '')),
            'domain' => trim((string)($post['domain'] ?? '')),
            'project_type' => trim((string)($post['project_type'] ?? '')),
            'requested_at' => date('Y-m-d H:i:s'),
            'requested_ip' => $this->clientIp(),
            'max_entries' => WlsFileManagerLargeOperationService::DEFAULT_MAX_TRASH_ENTRIES,
            'max_bytes' => WlsFileManagerLargeOperationService::DEFAULT_MAX_TRASH_BYTES,
        ];

        try {
            $queueResult = \w_query('queue', 'create', [
                'class' => WlsFileManagerLargeOperationQueue::class,
                'name' => (string)__('WLS 文件管理器回收：%{1}', [$entryRelativePath]),
                'module' => 'Weline_FileManager',
                'content' => $payload,
                'auto' => true,
                'biz_key' => $bizKey,
            ]);
        } catch (\Throwable) {
            $this->appendOperationLog('trash_queue', 'failed', $rootKey, $entryRelativePath, '.wls-trash', 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $queueId = is_array($queueResult) ? (int)($queueResult['queue_id'] ?? 0) : 0;
        if ($queueId <= 0) {
            $this->appendOperationLog('trash_queue', 'failed', $rootKey, $entryRelativePath, '.wls-trash', 'queue_create_failed', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'queue_create_failed'], '#queue-operations');
            return '';
        }

        $this->appendOperationLog(
            'trash_queue',
            'success',
            $rootKey,
            $entryRelativePath,
            '.wls-trash',
            'trash_queue_created',
            $post + ['queue_id' => (string)$queueId]
        );
        $this->redirectToFileManager($params + ['wfm_notice' => 'trash_queue_created'], '#queue-operations');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Restore WLS trash queue entry', 'mdi-restore', 'Restore a recoverable WLS file queue entry')]
    public function postTrashRestore(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#queue-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $currentRelativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $currentRelativePath);
        $queueId = (int)($post['queue_id'] ?? 0);

        if ((string)($post['confirm_write'] ?? '0') !== '1' || trim((string)($post['confirm_phrase'] ?? '')) !== 'RESTORE_TRASH') {
            $this->appendOperationLog('trash_restore', 'denied', $rootKey, '', '', 'missing_confirmation', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'missing_confirmation'], '#queue-operations');
            return '';
        }

        if ($queueId <= 0) {
            $this->appendOperationLog('trash_restore', 'denied', $rootKey, '', '', 'invalid_trash_queue', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_trash_queue'], '#queue-operations');
            return '';
        }

        try {
            $queueRow = \w_query('queue', 'get', ['queue_id' => $queueId]);
        } catch (\Throwable) {
            $queueRow = [];
        }

        $queueRow = is_object($queueRow) && method_exists($queueRow, 'getData') ? (array)$queueRow->getData() : (array)$queueRow;
        $payload = json_decode((string)($queueRow['content'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? ''));
        $trashRelativePath = trim((string)($payload['trash_relative_path'] ?? ''));
        $rootKey = trim((string)($payload['root_key'] ?? $rootKey));

        if (!in_array((string)($payload['operation'] ?? ''), [WlsFileManagerLargeOperationQueue::OPERATION_TRASH_ENTRY, WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_TRASH_ENTRY], true)) {
            $this->appendOperationLog('trash_restore', 'denied', $rootKey, $sourceRelativePath, $trashRelativePath, 'invalid_trash_queue', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_trash_queue'], '#queue-operations');
            return '';
        }

        if ((string)($queueRow['status'] ?? '') !== 'done') {
            $this->appendOperationLog('trash_restore', 'denied', $rootKey, $sourceRelativePath, $trashRelativePath, 'trash_queue_not_done', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'trash_queue_not_done'], '#queue-operations');
            return '';
        }

        $service = ObjectManager::getInstance(WlsFileManagerLargeOperationService::class);
        $result = $service->restoreFromTrash(
            (string)($payload['trash_path'] ?? ''),
            (string)($payload['source_path'] ?? ''),
            (string)($payload['root_path'] ?? '')
        );

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'restore_failed');
            $this->appendOperationLog('trash_restore', (string)($result['result'] ?? 'failed'), $rootKey, $sourceRelativePath, $trashRelativePath, $errorCode, $post);
            $this->redirectToFileManager($params + ['wfm_error' => $errorCode], '#queue-operations');
            return '';
        }

        $targetParams = $this->redirectParamsFromInput($post, $rootKey, trim((string)($payload['source_parent_relative_path'] ?? ''), '/'));
        $this->appendOperationLog(
            'trash_restore',
            'success',
            $rootKey,
            $sourceRelativePath,
            $trashRelativePath,
            'trash_restored',
            $post + ['queue_id' => (string)$queueId]
        );
        $this->redirectToFileManager($targetParams + ['wfm_notice' => 'trash_restored'], '#queue-operations');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', 'Purge WLS trash queue entry', 'mdi-delete-forever-outline', 'Permanently purge a queue-created WLS trash entry')]
    public function postTrashPurge(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#queue-operations');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $currentRelativePath = $this->safeRelativePathForWrite((string)($post['path'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $currentRelativePath);
        $queueId = (int)($post['queue_id'] ?? 0);

        if ((string)($post['confirm_write'] ?? '0') !== '1' || trim((string)($post['confirm_phrase'] ?? '')) !== 'PURGE_TRASH') {
            $this->appendOperationLog('trash_purge', 'denied', $rootKey, '', '', 'purge_confirmation_required', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'purge_confirmation_required'], '#queue-operations');
            return '';
        }

        if ($queueId <= 0) {
            $this->appendOperationLog('trash_purge', 'denied', $rootKey, '', '', 'invalid_trash_queue', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_trash_queue'], '#queue-operations');
            return '';
        }

        try {
            $queueRow = \w_query('queue', 'get', ['queue_id' => $queueId]);
        } catch (\Throwable) {
            $queueRow = [];
        }

        $queueRow = is_object($queueRow) && method_exists($queueRow, 'getData') ? (array)$queueRow->getData() : (array)$queueRow;
        $payload = json_decode((string)($queueRow['content'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? ''));
        $trashRelativePath = trim((string)($payload['trash_relative_path'] ?? ''));
        $rootKey = trim((string)($payload['root_key'] ?? $rootKey));

        if ((string)($payload['operation'] ?? '') !== WlsFileManagerLargeOperationQueue::OPERATION_TRASH_ENTRY) {
            $this->appendOperationLog('trash_purge', 'denied', $rootKey, $sourceRelativePath, $trashRelativePath, 'invalid_trash_queue', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'invalid_trash_queue'], '#queue-operations');
            return '';
        }

        if ((string)($queueRow['status'] ?? '') !== 'done') {
            $this->appendOperationLog('trash_purge', 'denied', $rootKey, $sourceRelativePath, $trashRelativePath, 'trash_queue_not_done', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'trash_queue_not_done'], '#queue-operations');
            return '';
        }

        if (trim((string)($payload['trash_purged_at'] ?? '')) !== '') {
            $this->appendOperationLog('trash_purge', 'denied', $rootKey, $sourceRelativePath, $trashRelativePath, 'trash_already_purged', $post);
            $this->redirectToFileManager($params + ['wfm_error' => 'trash_already_purged'], '#queue-operations');
            return '';
        }

        $service = ObjectManager::getInstance(WlsFileManagerLargeOperationService::class);
        $result = $service->purgeTrash(
            (string)($payload['trash_path'] ?? ''),
            (string)($payload['root_path'] ?? '')
        );

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'trash_purge_failed');
            $this->appendOperationLog('trash_purge', (string)($result['result'] ?? 'failed'), $rootKey, $sourceRelativePath, $trashRelativePath, $errorCode, $post);
            $this->redirectToFileManager($params + ['wfm_error' => $errorCode], '#queue-operations');
            return '';
        }

        $payload['trash_purged_at'] = date('Y-m-d H:i:s');
        $payload['trash_purged_ip'] = $this->clientIp();
        $payload['trash_purged_relative_path'] = $trashRelativePath;

        $auditCode = 'trash_purged';
        try {
            \w_query('queue', 'update', [
                'queue_id' => $queueId,
                'content' => $payload,
            ]);
        } catch (\Throwable) {
            $auditCode = 'trash_purged_audit_update_failed';
        }

        $this->appendOperationLog(
            'trash_purge',
            'success',
            $rootKey,
            $sourceRelativePath,
            $trashRelativePath,
            $auditCode,
            $post + ['queue_id' => (string)$queueId]
        );
        $this->redirectToFileManager($params + ['wfm_notice' => 'trash_purged'], '#queue-operations');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', '保存 WLS 文件路径策略', 'mdi-shield-edit-outline', '保存 WLS 面板文件管理器的项目级路径策略')]
    public function postPathPolicySave(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#path-policy');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePath((string)($post['path'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $result = $this->pathPolicyService()->saveFromPanel($post);

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'path_policy_write_failed');
            $this->appendOperationLog('path_policy_save', 'denied', 'policy', '', '', $errorCode, $post);
            $this->redirectToFileManager($params + ['wfm_error' => $errorCode], '#path-policy');
            return '';
        }

        $policy = (array)($result['policy'] ?? []);
        $this->appendOperationLog(
            'path_policy_save',
            'success',
            'policy',
            '',
            implode(', ', (array)($policy['enabled_roots'] ?? [])),
            'path_policy_saved',
            $post
        );
        $this->redirectToFileManager($params + ['wfm_notice' => 'path_policy_saved'], '#path-policy');
        return '';
    }

    #[Acl('Weline_FileManager::wls_file_manager_write', '恢复 WLS 文件路径默认策略', 'mdi-shield-refresh-outline', '恢复 WLS 面板文件管理器的项目级路径默认继承策略')]
    public function postPathPolicyReset(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToFileManager(['wfm_error' => 'method_not_allowed'], '#path-policy');
            return '';
        }

        $post = (array)$this->request->getPost();
        $rootKey = trim((string)($post['root'] ?? ''));
        $relativePath = $this->safeRelativePath((string)($post['path'] ?? ''));
        $params = $this->redirectParamsFromInput($post, $rootKey, $relativePath);
        $result = $this->pathPolicyService()->resetFromPanel($post);

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'path_policy_reset_failed');
            $this->appendOperationLog('path_policy_reset', 'denied', 'policy', '', '', $errorCode, $post);
            $this->redirectToFileManager($params + ['wfm_error' => $errorCode], '#path-policy');
            return '';
        }

        $policy = (array)($result['policy'] ?? []);
        $this->appendOperationLog(
            'path_policy_reset',
            'success',
            'policy',
            '',
            (string)($policy['profile_key'] ?? ''),
            'path_policy_reset',
            $post
        );
        $this->redirectToFileManager($params + ['wfm_notice' => 'path_policy_reset'], '#path-policy');
        return '';
    }

    /**
     * @return array<string, string>
     */
    private function requestContext(): array
    {
        $post = $this->request->isPost() ? (array)$this->request->getPost() : [];
        $context = [
            'operation' => $this->contextValue('operation', $post),
            'project_id' => $this->contextValue('project_id', $post),
            'domain' => $this->contextValue('domain', $post),
            'project_type' => $this->contextValue('project_type', $post),
        ];

        return $context + $this->resolveManagedProjectContext($context);
    }

    private function isEmbeddedPanelRequest(): bool
    {
        $value = strtolower(trim((string)$this->request->getGet('embedded', '')));
        return in_array($value, ['1', 'true', 'yes', 'wls_panel'], true);
    }

    private function shouldKeepEmbeddedMode(): bool
    {
        if ($this->isEmbeddedPanelRequest()) {
            return true;
        }

        $post = $this->request->isPost() ? (array)$this->request->getPost() : [];
        $value = strtolower(trim((string)($post['embedded'] ?? '')));
        return in_array($value, ['1', 'true', 'yes', 'wls_panel'], true);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function contextValue(string $key, array $post): string
    {
        $value = trim((string)($post[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }

        return trim((string)$this->request->getGet($key, ''));
    }

    /**
     * @param array<string, string> $context
     * @return array<string, string>
     */
    private function resolveManagedProjectContext(array $context): array
    {
        $defaults = [
            'project_name' => '',
            'project_status' => '',
            'project_path' => '',
            'project_path_real' => '',
            'project_path_available' => '0',
            'project_lookup' => 'local',
            'project_lookup_message' => '',
        ];

        $projectId = (int)($context['project_id'] ?? 0);
        $domain = trim((string)($context['domain'] ?? ''));
        if ($projectId <= 0 && $domain === '') {
            return $defaults;
        }

        try {
            $result = \w_query('server', 'wlsPanelProject', [
                'project_id' => $projectId,
                'domain' => $domain,
            ]);
        } catch (\Throwable $throwable) {
            return [
                'project_name' => '',
                'project_status' => '',
                'project_path' => '',
                'project_path_real' => '',
                'project_path_available' => '0',
                'project_lookup' => 'error',
                'project_lookup_message' => $throwable->getMessage(),
            ];
        }

        if (!is_array($result) || empty($result['success']) || empty($result['found'])) {
            return [
                'project_name' => '',
                'project_status' => '',
                'project_path' => '',
                'project_path_real' => '',
                'project_path_available' => '0',
                'project_lookup' => !is_array($result) || empty($result['success']) ? 'error' : 'missing',
                'project_lookup_message' => is_array($result) ? trim((string)($result['message'] ?? '')) : '',
            ];
        }

        $project = (array)($result['project'] ?? []);
        return [
            'project_name' => trim((string)($project['name'] ?? '')),
            'project_status' => trim((string)($project['status'] ?? '')),
            'project_path' => trim((string)($project['project_path'] ?? '')),
            'project_path_real' => trim((string)($project['path_real'] ?? '')),
            'project_path_available' => !empty($project['path_available']) ? '1' : '0',
            'project_lookup' => 'found',
            'project_lookup_message' => trim((string)($result['message'] ?? '')),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rootCards(?array $context = null, ?array $pathPolicy = null): array
    {
        $context ??= $this->requestContext();
        $pathPolicy ??= $this->pathPolicyService()->getPolicyForContext($context);
        $localProjectPath = defined('BP') ? BP : (string)getcwd();
        $managedProjectPath = trim((string)($context['project_path_real'] ?? ''));
        if ($managedProjectPath === '') {
            $managedProjectPath = trim((string)($context['project_path'] ?? ''));
        }
        $hasManagedProjectPath = (string)($context['project_lookup'] ?? '') === 'found' && $managedProjectPath !== '';
        $activeProjectPath = $hasManagedProjectPath ? $managedProjectPath : $localProjectPath;
        $activeProjectRoot = $this->normalizePath($activeProjectPath);
        $localProjectRoot = $this->normalizePath($localProjectPath);

        $paths = [
            [
                'key' => 'project',
                'label' => (string)__('项目根目录'),
                'path' => $activeProjectPath,
                'description' => (string)__('当前 Weline 项目的根目录。后续文件列表、上传和删除都必须受路径白名单约束。'),
                'write_enabled' => false,
            ],
            ...($hasManagedProjectPath && $localProjectRoot !== '' && $localProjectRoot !== $activeProjectRoot ? [[
                'key' => 'local_project',
                'label' => (string)__('Panel Instance Root'),
                'path' => $localProjectPath,
                'description' => $hasManagedProjectPath
                    ? (string)__('Resolved from the WLS Panel managed project registry; the root is read-only and child var/pub roots are write-gated separately.')
                    : (string)__('Current Weline project root; directory browsing is restricted to approved root cards.'),
                'write_enabled' => false,
            ]] : []),
            ...($hasManagedProjectPath ? [[
                'key' => 'project_var',
                'label' => (string)__('Child Project var'),
                'path' => rtrim($activeProjectPath, "\\/") . DIRECTORY_SEPARATOR . 'var',
                'description' => (string)__('Child project runtime files; controlled writes require ACL, confirmation, extension checks, and operation audit logs.'),
                'write_enabled' => true,
            ], [
                'key' => 'project_pub',
                'label' => (string)__('Child Project pub'),
                'path' => rtrim($activeProjectPath, "\\/") . DIRECTORY_SEPARATOR . 'pub',
                'description' => (string)__('Child project public assets root; controlled writes require ACL, confirmation, extension checks, and operation audit logs.'),
                'write_enabled' => true,
            ]] : []),
            [
                'key' => 'app_code',
                'label' => (string)__('模块目录'),
                'path' => defined('APP_CODE_PATH') ? APP_CODE_PATH : '',
                'description' => (string)__('框架模块源码目录，仅作为只读路径上下文展示。'),
                'write_enabled' => false,
            ],
            [
                'key' => 'var',
                'label' => (string)__('运行时目录'),
                'path' => defined('VAR_PATH') ? VAR_PATH : ((defined('BP') ? BP : '') . 'var' . DIRECTORY_SEPARATOR),
                'description' => (string)__('缓存、日志、队列和临时运行数据所在目录。'),
                'write_enabled' => true,
            ],
            [
                'key' => 'pub',
                'label' => (string)__('公开目录'),
                'path' => defined('PUB') ? PUB : ((defined('BP') ? BP : '') . 'pub' . DIRECTORY_SEPARATOR),
                'description' => (string)__('静态资源和公开媒体入口目录。'),
                'write_enabled' => true,
            ],
        ];

        $result = [];
        foreach ($paths as $path) {
            $normalizedPath = $this->normalizePath((string)$path['path']);
            $exists = $normalizedPath !== '' && is_dir($normalizedPath);
            $writeEnabled = !empty($path['write_enabled']) && $exists && is_writable($normalizedPath);
            $result[] = [
                'key' => (string)$path['key'],
                'label' => (string)$path['label'],
                'path' => $normalizedPath,
                'description' => (string)$path['description'],
                'status' => $exists ? (string)__('可访问') : (string)__('未找到'),
                'status_tone' => $exists ? 'ok' : 'warning',
                'mode' => $writeEnabled ? (string)__('受控写入根目录') : (string)__('只读根目录'),
                'write_enabled' => $writeEnabled,
                'write_extensions' => implode(', ', self::WRITE_TEXT_EXTENSIONS),
                'source_edit_extensions' => implode(', ', WlsFileManagerPathPolicyService::SOURCE_EDIT_EXTENSIONS),
                'upload_extensions' => implode(', ', self::WRITE_UPLOAD_EXTENSIONS),
            ];
        }

        return $this->pathPolicyService()->applyToRoots($result, $pathPolicy);
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array<string, mixed>
     */
    private function browseData(array $roots): array
    {
        $rootMap = [];
        foreach ($roots as $root) {
            $key = (string)($root['key'] ?? '');
            $path = (string)($root['path'] ?? '');
            if ($key === '' || $path === '' || !is_dir($path)) {
                continue;
            }
            $rootMap[$key] = $root;
        }

        $requestedRoot = trim((string)$this->request->getGet('root', 'project'));
        $selectedRootKey = isset($rootMap[$requestedRoot]) ? $requestedRoot : (array_key_first($rootMap) ?: '');
        if ($selectedRootKey === '') {
            return [
                'root_key' => '',
                'relative_path' => '',
                'current_path' => '',
                'parent_path' => '',
                'can_go_up' => false,
                'entries' => [],
                'entry_count' => 0,
                'truncated' => false,
                'error' => (string)__('没有可浏览的根目录。'),
            ];
        }

        $rootPath = (string)($rootMap[$selectedRootKey]['path'] ?? '');
        $safeRelativePath = $this->safeRelativePath((string)$this->request->getGet('path', ''));
        $resolved = $this->resolveBrowsePath($rootPath, $safeRelativePath);
        $error = '';
        if ($resolved === null) {
            $safeRelativePath = '';
            $resolved = $this->resolveBrowsePath($rootPath, '');
            $error = (string)__('所选路径不在允许的根目录内，已回到根目录。');
        }

        $currentPath = $resolved ?? $rootPath;
        $entries = [];
        $truncated = false;
        if (!is_dir($currentPath) || !is_readable($currentPath)) {
            $error = $error !== '' ? $error : (string)__('当前目录不可读取。');
        } else {
            $scan = scandir($currentPath);
            if ($scan === false) {
                $error = $error !== '' ? $error : (string)__('当前目录读取失败。');
            } else {
                $entries = $this->buildDirectoryEntries($rootPath, $safeRelativePath, $currentPath, $scan, $truncated);
            }
        }

        return [
            'root_key' => $selectedRootKey,
            'relative_path' => $safeRelativePath,
            'current_path' => $this->normalizePath($currentPath),
            'parent_path' => $this->parentRelativePath($safeRelativePath),
            'can_go_up' => $safeRelativePath !== '',
            'entries' => $entries,
            'entry_count' => count($entries),
            'truncated' => $truncated,
            'error' => $error,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array<string, mixed>
     */
    private function previewData(array $roots): array
    {
        $requestedPreview = trim((string)$this->request->getGet('preview', ''));
        if ($requestedPreview === '') {
            return ['active' => false];
        }

        $resolved = $this->resolveRequestedFile($roots, 'preview');
        $base = [
            'active' => true,
            'root_key' => (string)$resolved['root_key'],
            'relative_path' => (string)$resolved['relative_path'],
            'parent_path' => $this->parentRelativePath((string)$resolved['relative_path']),
            'file_name' => basename((string)$resolved['relative_path']),
            'error' => (string)$resolved['error'],
            'content' => '',
            'size' => '',
            'modified' => '',
            'mime_type' => '',
            'truncated' => false,
            'editable' => false,
            'edit_blocker' => '',
            'edit_root_key' => (string)$resolved['root_key'],
            'edit_relative_path' => $this->parentRelativePath((string)$resolved['relative_path']),
            'edit_file_name' => basename((string)$resolved['relative_path']),
        ];

        if ($base['error'] !== '') {
            return $base;
        }

        $path = (string)$resolved['path'];
        if (!is_file($path) || !is_readable($path)) {
            $base['error'] = (string)__('文件不可读取。');
            return $base;
        }

        if (!$this->isPreviewableTextFile($path)) {
            $base['error'] = (string)__('此文件类型暂不支持预览。');
            return $base;
        }

        $content = file_get_contents($path, false, null, 0, self::MAX_PREVIEW_BYTES + 1);
        if ($content === false) {
            $base['error'] = (string)__('文件不可读取。');
            return $base;
        }

        if (str_contains($content, "\0")) {
            $base['error'] = (string)__('此文件看起来是二进制内容，暂不支持预览。');
            return $base;
        }

        $truncated = strlen($content) > self::MAX_PREVIEW_BYTES;
        if ($truncated) {
            $content = substr($content, 0, self::MAX_PREVIEW_BYTES);
        }

        $base['content'] = $content;
        $base['size'] = $this->formatFileSize($path);
        $base['modified'] = $this->formatModifiedTime($path);
        $base['mime_type'] = $this->mimeType($path);
        $base['truncated'] = $truncated;

        return array_merge($base, $this->previewEditState(
            $roots,
            (string)$resolved['root_key'],
            (string)$resolved['relative_path'],
            $path,
            $truncated
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array<string, mixed>
     */
    private function previewEditState(array $roots, string $rootKey, string $relativePath, string $path, bool $truncated): array
    {
        $fileName = basename($relativePath);
        $parentPath = $this->parentRelativePath($relativePath);
        $state = [
            'editable' => false,
            'edit_blocker' => '',
            'edit_root_key' => $rootKey,
            'edit_relative_path' => $parentPath,
            'edit_file_name' => $fileName,
            'source_edit' => false,
            'edit_confirm_phrase' => 'SAVE_TEXT',
        ];

        if ($truncated) {
            $state['edit_blocker'] = (string)__('预览内容已截断，不能直接编辑。');
            return $state;
        }

        if ($this->sourceEditRootEnabled($roots, $rootKey) && $this->isAllowedSourceFileName($fileName)) {
            return $this->previewSourceEditState($roots, $rootKey, $parentPath, $fileName, $path, $state);
        }

        if (!$this->isAllowedTextFileName($fileName)) {
            return $this->previewSourceEditState($roots, $rootKey, $parentPath, $fileName, $path, $state);
        }

        $directory = $this->resolveWritableDirectory($roots, $rootKey, $parentPath);
        if ((string)$directory['error'] !== '') {
            $state['edit_blocker'] = (string)$directory['error'];
            return $state;
        }

        $targetPath = rtrim((string)$directory['path'], "\\/") . DIRECTORY_SEPARATOR . $fileName;
        $targetRealPath = realpath($targetPath);
        $previewRealPath = realpath($path);
        if ($targetRealPath === false || $previewRealPath === false || $this->normalizePath($targetRealPath) !== $this->normalizePath($previewRealPath)) {
            $state['edit_blocker'] = (string)__('目标路径不在允许的根目录内。');
            return $state;
        }

        if (!is_writable($previewRealPath)) {
            $state['edit_blocker'] = (string)__('目标文件不可写。');
            return $state;
        }

        $state['editable'] = true;
        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function previewSourceEditState(
        array $roots,
        string $rootKey,
        string $parentPath,
        string $fileName,
        string $previewPath,
        array $state
    ): array {
        if (!$this->isAllowedSourceFileName($fileName)) {
            $state['edit_blocker'] = (string)__('文件扩展名不在源码编辑白名单内。');
            return $state;
        }

        $source = $this->resolveSourceEditableFile($roots, $rootKey, $parentPath, $fileName);
        if ((string)$source['error'] !== '') {
            $state['edit_blocker'] = (string)$source['error'];
            return $state;
        }

        $sourceRealPath = realpath((string)$source['path']);
        $previewRealPath = realpath($previewPath);
        if (
            $sourceRealPath === false
            || $previewRealPath === false
            || $this->normalizePath($sourceRealPath) !== $this->normalizePath($previewRealPath)
        ) {
            $state['edit_blocker'] = (string)__('目标路径不在允许的根目录内。');
            return $state;
        }

        $state['editable'] = true;
        $state['source_edit'] = true;
        $state['edit_confirm_phrase'] = self::SOURCE_EDIT_CONFIRM_PHRASE;

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array{path: ?string, root_key: string, relative_path: string, error: string}
     */
    private function resolveRequestedFile(array $roots, string $parameter): array
    {
        $rootMap = [];
        foreach ($roots as $root) {
            $key = (string)($root['key'] ?? '');
            $path = (string)($root['path'] ?? '');
            if ($key === '' || $path === '' || !is_dir($path)) {
                continue;
            }
            $rootMap[$key] = $root;
        }

        $requestedRoot = trim((string)$this->request->getGet('root', 'project'));
        $selectedRootKey = isset($rootMap[$requestedRoot]) ? $requestedRoot : (array_key_first($rootMap) ?: '');
        $safeRelativePath = $this->safeRelativePath((string)$this->request->getGet($parameter, ''));
        $emptyResult = [
            'path' => null,
            'root_key' => $selectedRootKey,
            'relative_path' => $safeRelativePath,
            'error' => '',
        ];

        if ($selectedRootKey === '') {
            $emptyResult['error'] = (string)__('没有可浏览的根目录。');
            return $emptyResult;
        }

        if ($safeRelativePath === '') {
            $emptyResult['error'] = (string)__('文件路径无效或不在允许的根目录内。');
            return $emptyResult;
        }

        $rootPath = (string)($rootMap[$selectedRootKey]['path'] ?? '');
        $rootRealPath = realpath($rootPath);
        if ($rootRealPath === false) {
            $emptyResult['error'] = (string)__('文件路径无效或不在允许的根目录内。');
            return $emptyResult;
        }

        $candidate = $rootRealPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeRelativePath);
        $candidateRealPath = realpath($candidate);
        if ($candidateRealPath === false || !is_file($candidateRealPath) || !$this->isWithinRoot($rootPath, $candidateRealPath)) {
            $emptyResult['error'] = (string)__('文件路径无效或不在允许的根目录内。');
            return $emptyResult;
        }

        $emptyResult['path'] = $candidateRealPath;
        return $emptyResult;
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                return '';
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function resolveBrowsePath(string $rootPath, string $relativePath): ?string
    {
        $rootRealPath = realpath($rootPath);
        if ($rootRealPath === false) {
            return null;
        }

        $candidate = $relativePath === ''
            ? $rootRealPath
            : $rootRealPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $candidateRealPath = realpath($candidate);
        if ($candidateRealPath === false || !is_dir($candidateRealPath)) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $rootRealPath), '/') . '/';
        $candidatePath = rtrim(str_replace('\\', '/', $candidateRealPath), '/') . '/';
        if ($candidatePath !== $root && !str_starts_with($candidatePath, $root)) {
            return null;
        }

        return $candidateRealPath;
    }

    /**
     * @param array<int, string> $scan
     * @return array<int, array<string, mixed>>
     */
    private function buildDirectoryEntries(
        string $rootPath,
        string $relativePath,
        string $currentPath,
        array $scan,
        bool &$truncated
    ): array {
        $entries = [];
        foreach ($scan as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $entryPath = $currentPath . DIRECTORY_SEPARATOR . $name;
            $entryRealPath = realpath($entryPath);
            if ($entryRealPath === false || !$this->isWithinRoot($rootPath, $entryRealPath)) {
                continue;
            }

            $isDir = is_dir($entryRealPath);
            $isReadableFile = !$isDir && is_readable($entryRealPath);
            $fileSizeBytes = $isReadableFile ? $this->fileSizeBytes($entryRealPath) : null;
            $entryRelativePath = trim($relativePath . '/' . $name, '/');
            $entries[] = [
                'name' => $name,
                'type' => $isDir ? 'directory' : 'file',
                'relative_path' => $entryRelativePath,
                'size' => $isDir ? '' : $this->formatFileSize($entryRealPath),
                'modified' => $this->formatModifiedTime($entryRealPath),
                'readable' => is_readable($entryRealPath),
                'previewable' => $isReadableFile && $this->isPreviewableTextFile($entryRealPath),
                'downloadable' => $isReadableFile && $fileSizeBytes !== null && $fileSizeBytes <= self::MAX_DOWNLOAD_BYTES,
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            if (($left['type'] ?? '') !== ($right['type'] ?? '')) {
                return ($left['type'] ?? '') === 'directory' ? -1 : 1;
            }

            return strnatcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        if (count($entries) > 200) {
            $truncated = true;
            $entries = array_slice($entries, 0, 200);
        }

        return $entries;
    }

    private function isWithinRoot(string $rootPath, string $candidatePath): bool
    {
        $rootRealPath = realpath($rootPath);
        $candidateRealPath = realpath($candidatePath);
        if ($rootRealPath === false || $candidateRealPath === false) {
            return false;
        }

        $root = rtrim(str_replace('\\', '/', $rootRealPath), '/') . '/';
        $candidate = rtrim(str_replace('\\', '/', $candidateRealPath), '/') . (is_dir($candidateRealPath) ? '/' : '');

        return $candidate === rtrim($root, '/') || str_starts_with($candidate, $root);
    }

    private function parentRelativePath(string $relativePath): string
    {
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '' || !str_contains($relativePath, '/')) {
            return '';
        }

        return substr($relativePath, 0, (int)strrpos($relativePath, '/'));
    }

    private function formatFileSize(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '-';
        }

        $bytes = filesize($path);
        if ($bytes === false) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float)$bytes;
        $unitIndex = 0;
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return $unitIndex === 0
            ? (string)$bytes . ' B'
            : number_format($size, 1) . ' ' . $units[$unitIndex];
    }

    private function formatModifiedTime(string $path): string
    {
        $modified = filemtime($path);
        if ($modified === false) {
            return '-';
        }

        return date('Y-m-d H:i', $modified);
    }

    private function isPreviewableTextFile(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $allowedExtensions = [
            'conf',
            'config',
            'css',
            'csv',
            'env',
            'htm',
            'html',
            'ini',
            'js',
            'json',
            'log',
            'md',
            'phtml',
            'php',
            'sql',
            'txt',
            'xml',
            'yaml',
            'yml',
        ];
        if ($extension !== '' && in_array($extension, $allowedExtensions, true)) {
            return true;
        }

        $mimeType = strtolower($this->mimeType($path));
        return str_starts_with($mimeType, 'text/')
            || str_contains($mimeType, 'json')
            || str_contains($mimeType, 'xml')
            || str_contains($mimeType, 'javascript')
            || str_contains($mimeType, 'x-php');
    }

    private function mimeType(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($path);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }

        return 'application/octet-stream';
    }

    private function safeDownloadName(string $path): string
    {
        $name = basename($path);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'download.dat';
        $name = trim($name, '._-');

        return $name !== '' ? $name : 'download.dat';
    }

    private function fileSizeBytes(string $path): ?int
    {
        if (!is_file($path)) {
            return null;
        }

        $bytes = filesize($path);
        return $bytes === false ? null : $bytes;
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array{path:string,root_path:string,error:string,error_code:string}
     */
    private function resolveWritableDirectory(array $roots, string $rootKey, string $relativePath): array
    {
        $empty = [
            'path' => '',
            'root_path' => '',
            'error' => '',
            'error_code' => '',
        ];
        $rootMap = $this->rootMap($roots);
        if ($rootKey === '' || !isset($rootMap[$rootKey])) {
            $empty['error'] = (string)__('没有可写入的根目录。');
            $empty['error_code'] = 'invalid_root';
            return $empty;
        }

        $root = $rootMap[$rootKey];
        if (empty($root['write_enabled']) || !in_array($rootKey, self::WRITE_ROOT_KEYS, true)) {
            $empty['error'] = (string)__('所选根目录当前为只读。');
            $empty['error_code'] = 'readonly_root';
            return $empty;
        }

        $rootPath = (string)($root['path'] ?? '');
        $resolved = $this->resolveBrowsePath($rootPath, $relativePath);
        if ($resolved === null || !is_dir($resolved)) {
            $empty['error'] = (string)__('写入目录无效或不在允许的根目录内。');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        if (!is_writable($resolved)) {
            $empty['error'] = (string)__('当前目录不可写。');
            $empty['error_code'] = 'directory_not_writable';
            return $empty;
        }

        return [
            'path' => $resolved,
            'root_path' => $rootPath,
            'error' => '',
            'error_code' => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array{path:string,root_path:string,error:string,error_code:string}
     */
    private function resolveSourceCreateDirectory(array $roots, string $rootKey, string $relativePath, string $fileName): array
    {
        $empty = [
            'path' => '',
            'root_path' => '',
            'error' => '',
            'error_code' => '',
        ];

        $rootMap = $this->rootMap($roots);
        if (
            $rootKey === ''
            || !isset($rootMap[$rootKey])
            || !in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)
            || empty($rootMap[$rootKey]['source_edit_enabled'])
        ) {
            $empty['error'] = (string)__('源码编辑策略未允许当前根目录。');
            $empty['error_code'] = 'source_edit_policy_disabled';
            return $empty;
        }

        if (!$this->isAllowedSourceFileName($fileName)) {
            $empty['error'] = (string)__('源码文件名无效或扩展名不在允许列表内。');
            $empty['error_code'] = 'invalid_source_file';
            return $empty;
        }

        $rawRelativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $safeRelativePath = $this->safeRelativePath($rawRelativePath);
        if ($rawRelativePath !== '' && $safeRelativePath === '') {
            $empty['error'] = (string)__('写入目录无效或不在允许的根目录内。');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $safeFileName = $this->safeNewEntryName($fileName);
        $candidateRelativePath = trim(($safeRelativePath !== '' ? $safeRelativePath . '/' : '') . $safeFileName, '/');
        if ($candidateRelativePath === '' || !$this->sourceEditRelativePathAllowed($candidateRelativePath)) {
            $empty['error'] = (string)__('源码编辑路径被策略保护。');
            $empty['error_code'] = 'source_edit_protected_path';
            return $empty;
        }

        $rootPath = (string)($rootMap[$rootKey]['path'] ?? '');
        $directory = $this->resolveBrowsePath($rootPath, $safeRelativePath);
        if ($directory === null || !is_dir($directory)) {
            $empty['error'] = (string)__('写入目录无效或不在允许的根目录内。');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        if (!is_writable($directory)) {
            $empty['error'] = (string)__('当前目录不可写。');
            $empty['error_code'] = 'directory_not_writable';
            return $empty;
        }

        return [
            'path' => $directory,
            'root_path' => $rootPath,
            'error' => '',
            'error_code' => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array{source_path:string,target_path:string,root_path:string,error:string,error_code:string}
     */
    private function resolveSourceRenameEntry(array $roots, string $rootKey, string $relativePath, string $fileName, string $newName): array
    {
        $empty = [
            'source_path' => '',
            'target_path' => '',
            'root_path' => '',
            'error' => '',
            'error_code' => '',
        ];

        $rootMap = $this->rootMap($roots);
        if (
            $rootKey === ''
            || !isset($rootMap[$rootKey])
            || !in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)
            || empty($rootMap[$rootKey]['source_edit_enabled'])
        ) {
            $empty['error'] = (string)__('源码编辑策略未允许当前根目录。');
            $empty['error_code'] = 'source_edit_policy_disabled';
            return $empty;
        }

        if (!$this->isAllowedSourceFileName($fileName) || !$this->isAllowedSourceFileName($newName)) {
            $empty['error'] = (string)__('源码文件名无效或扩展名不在允许列表内。');
            $empty['error_code'] = 'invalid_source_file';
            return $empty;
        }

        if ($fileName === $newName) {
            $empty['error'] = (string)__('源码改名需要提供不同的新名称。');
            $empty['error_code'] = 'source_rename_same_name';
            return $empty;
        }

        $rawRelativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $safeRelativePath = $this->safeRelativePath($rawRelativePath);
        if ($rawRelativePath !== '' && $safeRelativePath === '') {
            $empty['error'] = (string)__('写入目录无效或不在允许的根目录内。');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $safeFileName = $this->safeNewEntryName($fileName);
        $safeNewName = $this->safeNewEntryName($newName);
        $sourceRelativePath = trim(($safeRelativePath !== '' ? $safeRelativePath . '/' : '') . $safeFileName, '/');
        $targetRelativePath = trim(($safeRelativePath !== '' ? $safeRelativePath . '/' : '') . $safeNewName, '/');
        if (
            $sourceRelativePath === ''
            || $targetRelativePath === ''
            || !$this->sourceEditRelativePathAllowed($sourceRelativePath)
            || !$this->sourceEditRelativePathAllowed($targetRelativePath)
        ) {
            $empty['error'] = (string)__('源码编辑路径被策略保护。');
            $empty['error_code'] = 'source_edit_protected_path';
            return $empty;
        }

        $rootPath = (string)($rootMap[$rootKey]['path'] ?? '');
        $directory = $this->resolveBrowsePath($rootPath, $safeRelativePath);
        if ($directory === null || !is_dir($directory)) {
            $empty['error'] = (string)__('写入目录无效或不在允许的根目录内。');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        if (!is_writable($directory)) {
            $empty['error'] = (string)__('当前目录不可写。');
            $empty['error_code'] = 'directory_not_writable';
            return $empty;
        }

        $sourcePath = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $safeFileName;
        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !$this->isWithinRoot($rootPath, $sourceRealPath)) {
            $empty['error'] = (string)__('源码编辑只允许修改已存在文件。');
            $empty['error_code'] = 'source_edit_existing_file_required';
            return $empty;
        }

        if (!is_file($sourceRealPath) || is_link($sourcePath) || is_link($sourceRealPath) || !is_readable($sourceRealPath)) {
            $empty['error'] = (string)__('源码编辑只允许修改已存在文件。');
            $empty['error_code'] = 'source_edit_existing_file_required';
            return $empty;
        }

        if (!is_writable($sourceRealPath)) {
            $empty['error'] = (string)__('目标文件不可写。');
            $empty['error_code'] = 'target_not_writable';
            return $empty;
        }

        $targetPath = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $safeNewName;
        if (!$this->isCandidateWithinRoot($rootPath, $targetPath)) {
            $empty['error'] = (string)__('目标路径不在允许的根目录内。');
            $empty['error_code'] = 'path_escape';
            return $empty;
        }

        if (file_exists($targetPath)) {
            $empty['error'] = (string)__('目标源码文件已存在。');
            $empty['error_code'] = 'source_rename_target_exists';
            return $empty;
        }

        return [
            'source_path' => $sourceRealPath,
            'target_path' => $targetPath,
            'root_path' => $rootPath,
            'error' => '',
            'error_code' => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array{source_path:string,root_path:string,source_relative_path:string,error:string,error_code:string}
     */
    private function resolveSourceTrashEntry(array $roots, string $rootKey, string $relativePath, string $fileName): array
    {
        $empty = [
            'source_path' => '',
            'root_path' => '',
            'source_relative_path' => '',
            'error' => '',
            'error_code' => '',
        ];

        $rootMap = $this->rootMap($roots);
        if (
            $rootKey === ''
            || !isset($rootMap[$rootKey])
            || !in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)
            || empty($rootMap[$rootKey]['source_edit_enabled'])
        ) {
            $empty['error'] = (string)__('源码编辑策略未允许当前根目录。');
            $empty['error_code'] = 'source_edit_policy_disabled';
            return $empty;
        }

        if (!$this->isAllowedSourceFileName($fileName)) {
            $empty['error'] = (string)__('源码文件名无效或扩展名不在允许列表内。');
            $empty['error_code'] = 'invalid_source_file';
            return $empty;
        }

        $rawRelativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $safeRelativePath = $this->safeRelativePath($rawRelativePath);
        if ($rawRelativePath !== '' && $safeRelativePath === '') {
            $empty['error'] = (string)__('写入目录无效或不在允许的根目录内。');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $safeFileName = $this->safeNewEntryName($fileName);
        $sourceRelativePath = trim(($safeRelativePath !== '' ? $safeRelativePath . '/' : '') . $safeFileName, '/');
        if ($sourceRelativePath === '' || !$this->sourceEditRelativePathAllowed($sourceRelativePath)) {
            $empty['error'] = (string)__('源码编辑路径被策略保护。');
            $empty['error_code'] = 'source_edit_protected_path';
            return $empty;
        }

        $rootPath = (string)($rootMap[$rootKey]['path'] ?? '');
        $directory = $this->resolveBrowsePath($rootPath, $safeRelativePath);
        if ($directory === null || !is_dir($directory)) {
            $empty['error'] = (string)__('写入目录无效或不在允许的根目录内。');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        if (!is_writable($directory)) {
            $empty['error'] = (string)__('当前目录不可写。');
            $empty['error_code'] = 'directory_not_writable';
            return $empty;
        }

        $sourcePath = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $safeFileName;
        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !$this->isWithinRoot($rootPath, $sourceRealPath)) {
            $empty['error'] = (string)__('源码编辑只允许修改已存在文件。');
            $empty['error_code'] = 'source_edit_existing_file_required';
            return $empty;
        }

        if (!is_file($sourceRealPath) || is_link($sourcePath) || is_link($sourceRealPath) || !is_readable($sourceRealPath)) {
            $empty['error'] = (string)__('源码编辑只允许修改已存在文件。');
            $empty['error_code'] = 'source_edit_existing_file_required';
            return $empty;
        }

        if (!is_writable($sourceRealPath)) {
            $empty['error'] = (string)__('目标文件不可写。');
            $empty['error_code'] = 'target_not_writable';
            return $empty;
        }

        $bytes = $this->fileSizeBytes($sourceRealPath);
        if ($bytes === null || $bytes > self::MAX_TEXT_SAVE_BYTES) {
            $empty['error'] = (string)__('源码回收仅允许小于 128 KB 的单个源码文件。');
            $empty['error_code'] = 'source_trash_too_large';
            return $empty;
        }

        return [
            'source_path' => $sourceRealPath,
            'root_path' => $rootPath,
            'source_relative_path' => $sourceRelativePath,
            'error' => '',
            'error_code' => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array{source_path:string,root_path:string,source_relative_path:string,error:string,error_code:string}
     */
    private function resolveSourceArchiveEntry(array $roots, string $rootKey, string $relativePath, string $fileName): array
    {
        $empty = [
            'source_path' => '',
            'root_path' => '',
            'source_relative_path' => '',
            'error' => '',
            'error_code' => '',
        ];

        $rootMap = $this->rootMap($roots);
        if (
            $rootKey === ''
            || !isset($rootMap[$rootKey])
            || !in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)
            || empty($rootMap[$rootKey]['source_edit_enabled'])
        ) {
            $empty['error'] = (string)__('Source policy does not allow the selected root.');
            $empty['error_code'] = 'source_edit_policy_disabled';
            return $empty;
        }

        if (!$this->isAllowedSourceFileName($fileName)) {
            $empty['error'] = (string)__('Source file name or extension is not allowed.');
            $empty['error_code'] = 'invalid_source_file';
            return $empty;
        }

        $rawRelativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $safeRelativePath = $this->safeRelativePath($rawRelativePath);
        if ($rawRelativePath !== '' && $safeRelativePath === '') {
            $empty['error'] = (string)__('Source directory is invalid or outside the selected root.');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $safeFileName = $this->safeNewEntryName($fileName);
        $sourceRelativePath = trim(($safeRelativePath !== '' ? $safeRelativePath . '/' : '') . $safeFileName, '/');
        if ($sourceRelativePath === '' || !$this->sourceEditRelativePathAllowed($sourceRelativePath)) {
            $empty['error'] = (string)__('Source path is protected by policy.');
            $empty['error_code'] = 'source_edit_protected_path';
            return $empty;
        }

        $rootPath = (string)($rootMap[$rootKey]['path'] ?? '');
        $directory = $this->resolveBrowsePath($rootPath, $safeRelativePath);
        if ($directory === null || !is_dir($directory)) {
            $empty['error'] = (string)__('Source directory is invalid or outside the selected root.');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $sourcePath = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $safeFileName;
        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !$this->isWithinRoot($rootPath, $sourceRealPath)) {
            $empty['error'] = (string)__('Source archive requires an existing readable file.');
            $empty['error_code'] = 'source_edit_existing_file_required';
            return $empty;
        }

        if (!is_file($sourceRealPath) || is_link($sourcePath) || is_link($sourceRealPath) || !is_readable($sourceRealPath)) {
            $empty['error'] = (string)__('Source archive requires an existing readable file.');
            $empty['error_code'] = 'source_edit_existing_file_required';
            return $empty;
        }

        $bytes = $this->fileSizeBytes($sourceRealPath);
        if ($bytes === null || $bytes > self::MAX_TEXT_SAVE_BYTES) {
            $empty['error'] = (string)__('Source archive only allows one source file smaller than 128 KB.');
            $empty['error_code'] = 'source_archive_too_large';
            return $empty;
        }

        return [
            'source_path' => $sourceRealPath,
            'root_path' => $rootPath,
            'source_relative_path' => $sourceRelativePath,
            'error' => '',
            'error_code' => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array{source_path:string,root_path:string,source_relative_path:string,error:string,error_code:string}
     */
    private function resolveSourceArchiveTreeEntry(array $roots, string $rootKey, string $relativePath, string $directoryName): array
    {
        $empty = [
            'source_path' => '',
            'root_path' => '',
            'source_relative_path' => '',
            'error' => '',
            'error_code' => '',
        ];

        $rootMap = $this->rootMap($roots);
        if (
            $rootKey === ''
            || !isset($rootMap[$rootKey])
            || !in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)
            || empty($rootMap[$rootKey]['source_edit_enabled'])
        ) {
            $empty['error'] = (string)__('Source policy does not allow the selected root.');
            $empty['error_code'] = 'source_edit_policy_disabled';
            return $empty;
        }

        $safeDirectoryName = $this->safeNewEntryName($directoryName);
        if ($safeDirectoryName === '') {
            $empty['error'] = (string)__('Source directory name is invalid.');
            $empty['error_code'] = 'invalid_name';
            return $empty;
        }

        $rawRelativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $safeRelativePath = $this->safeRelativePath($rawRelativePath);
        if ($rawRelativePath !== '' && $safeRelativePath === '') {
            $empty['error'] = (string)__('Source directory is invalid or outside the selected root.');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $sourceRelativePath = trim(($safeRelativePath !== '' ? $safeRelativePath . '/' : '') . $safeDirectoryName, '/');
        if ($sourceRelativePath === '' || !$this->sourceEditRelativePathAllowed($sourceRelativePath)) {
            $empty['error'] = (string)__('Source path is protected by policy.');
            $empty['error_code'] = 'source_edit_protected_path';
            return $empty;
        }

        $rootPath = (string)($rootMap[$rootKey]['path'] ?? '');
        $directory = $this->resolveBrowsePath($rootPath, $safeRelativePath);
        if ($directory === null || !is_dir($directory)) {
            $empty['error'] = (string)__('Source directory is invalid or outside the selected root.');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $sourcePath = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $safeDirectoryName;
        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !$this->isWithinRoot($rootPath, $sourceRealPath)) {
            $empty['error'] = (string)__('Source archive requires an existing readable directory.');
            $empty['error_code'] = 'entry_not_found';
            return $empty;
        }

        if (!is_dir($sourceRealPath) || is_link($sourcePath) || is_link($sourceRealPath) || !is_readable($sourceRealPath)) {
            $empty['error'] = (string)__('Source archive requires an existing readable directory.');
            $empty['error_code'] = 'entry_not_readable';
            return $empty;
        }

        return [
            'source_path' => $sourceRealPath,
            'root_path' => $rootPath,
            'source_relative_path' => $sourceRelativePath,
            'error' => '',
            'error_code' => '',
        ];
    }

    /**
     * @return array{names: array<int, string>, invalid: bool, too_many: bool}
     */
    private function sourceSelectionEntryNames(string $rawNames): array
    {
        $parts = preg_split('/[\r\n,;]+/', $rawNames) ?: [];
        $names = [];
        $invalid = false;
        $tooMany = false;

        foreach ($parts as $part) {
            $name = trim((string)$part);
            if ($name === '') {
                continue;
            }

            $safeName = $this->safeNewEntryName($name);
            if ($safeName === '' || $safeName !== $name) {
                $invalid = true;
                continue;
            }

            if (!in_array($safeName, $names, true)) {
                $names[] = $safeName;
            }

            if (count($names) > self::SOURCE_ARCHIVE_SELECTION_MAX_ITEMS) {
                $tooMany = true;
                $names = array_slice($names, 0, self::SOURCE_ARCHIVE_SELECTION_MAX_ITEMS);
                break;
            }
        }

        return [
            'names' => $names,
            'invalid' => $invalid,
            'too_many' => $tooMany,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @param array<int, string> $entryNames
     * @return array{source_parent_path:string,root_path:string,source_parent_relative_path:string,source_entries:array<int,array{source_path:string,source_relative_path:string,type:string}>,error:string,error_code:string}
     */
    private function resolveSourceArchiveSelectionEntries(array $roots, string $rootKey, string $relativePath, array $entryNames): array
    {
        $empty = [
            'source_parent_path' => '',
            'root_path' => '',
            'source_parent_relative_path' => '',
            'source_entries' => [],
            'error' => '',
            'error_code' => '',
        ];

        $rootMap = $this->rootMap($roots);
        if (
            $rootKey === ''
            || !isset($rootMap[$rootKey])
            || !in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)
            || empty($rootMap[$rootKey]['source_edit_enabled'])
        ) {
            $empty['error'] = (string)__('Source policy does not allow the selected root.');
            $empty['error_code'] = 'source_edit_policy_disabled';
            return $empty;
        }

        if ($entryNames === [] || count($entryNames) > self::SOURCE_ARCHIVE_SELECTION_MAX_ITEMS) {
            $empty['error'] = (string)__('Source selection is invalid.');
            $empty['error_code'] = count($entryNames) > self::SOURCE_ARCHIVE_SELECTION_MAX_ITEMS
                ? 'source_archive_selection_too_many'
                : 'invalid_source_selection';
            return $empty;
        }

        $rawRelativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $safeRelativePath = $this->safeRelativePath($rawRelativePath);
        if ($rawRelativePath !== '' && $safeRelativePath === '') {
            $empty['error'] = (string)__('Source directory is invalid or outside the selected root.');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $rootPath = (string)($rootMap[$rootKey]['path'] ?? '');
        $directory = $this->resolveBrowsePath($rootPath, $safeRelativePath);
        if ($directory === null || !is_dir($directory) || is_link($directory) || !is_readable($directory)) {
            $empty['error'] = (string)__('Source directory is invalid or outside the selected root.');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $sourceEntries = [];
        foreach ($entryNames as $entryName) {
            $safeEntryName = $this->safeNewEntryName((string)$entryName);
            if ($safeEntryName === '') {
                $empty['error'] = (string)__('Source selection is invalid.');
                $empty['error_code'] = 'invalid_source_selection';
                return $empty;
            }

            $sourceRelativePath = trim(($safeRelativePath !== '' ? $safeRelativePath . '/' : '') . $safeEntryName, '/');
            if ($sourceRelativePath === '' || !$this->sourceEditRelativePathAllowed($sourceRelativePath)) {
                $empty['error'] = (string)__('Source path is protected by policy.');
                $empty['error_code'] = 'source_edit_protected_path';
                return $empty;
            }

            $sourcePath = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $safeEntryName;
            $sourceRealPath = realpath($sourcePath);
            if ($sourceRealPath === false || !$this->isWithinRoot($rootPath, $sourceRealPath)) {
                $empty['error'] = (string)__('Source selection requires existing readable entries.');
                $empty['error_code'] = 'entry_not_found';
                return $empty;
            }

            if (is_link($sourcePath) || is_link($sourceRealPath) || !is_readable($sourceRealPath)) {
                $empty['error'] = (string)__('Source selection requires existing readable entries.');
                $empty['error_code'] = 'entry_not_readable';
                return $empty;
            }

            $type = is_dir($sourceRealPath) ? 'directory' : (is_file($sourceRealPath) ? 'file' : '');
            if ($type === '') {
                $empty['error'] = (string)__('Source selection requires existing readable entries.');
                $empty['error_code'] = 'entry_not_readable';
                return $empty;
            }

            if ($type === 'file') {
                if (!$this->isAllowedSourceFileName($safeEntryName)) {
                    $empty['error'] = (string)__('Source file name or extension is not allowed.');
                    $empty['error_code'] = 'invalid_source_file';
                    return $empty;
                }

                $bytes = $this->fileSizeBytes($sourceRealPath);
                if ($bytes === null || $bytes > self::MAX_TEXT_SAVE_BYTES) {
                    $empty['error'] = (string)__('Source archive only allows source files smaller than 128 KB.');
                    $empty['error_code'] = 'source_archive_too_large';
                    return $empty;
                }
            }

            $sourceEntries[] = [
                'source_path' => $sourceRealPath,
                'source_relative_path' => $sourceRelativePath,
                'type' => $type,
            ];
        }

        return [
            'source_parent_path' => $directory,
            'root_path' => $rootPath,
            'source_parent_relative_path' => $safeRelativePath,
            'source_entries' => $sourceEntries,
            'error' => '',
            'error_code' => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array{path:string,root_path:string,error:string,error_code:string}
     */
    private function resolveSourceEditableFile(array $roots, string $rootKey, string $relativePath, string $fileName): array
    {
        $empty = [
            'path' => '',
            'root_path' => '',
            'error' => '',
            'error_code' => '',
        ];

        $rootMap = $this->rootMap($roots);
        if (
            $rootKey === ''
            || !isset($rootMap[$rootKey])
            || !in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)
            || empty($rootMap[$rootKey]['source_edit_enabled'])
        ) {
            $empty['error'] = (string)__('源码编辑策略未允许当前根目录。');
            $empty['error_code'] = 'source_edit_policy_disabled';
            return $empty;
        }

        if (!$this->isAllowedSourceFileName($fileName)) {
            $empty['error'] = (string)__('源码文件名无效或扩展名不在允许列表内。');
            $empty['error_code'] = 'invalid_source_file';
            return $empty;
        }

        $rawRelativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $safeRelativePath = $this->safeRelativePath($rawRelativePath);
        if ($rawRelativePath !== '' && $safeRelativePath === '') {
            $empty['error'] = (string)__('写入目录无效或不在允许的根目录内。');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $safeFileName = $this->safeNewEntryName($fileName);
        $candidateRelativePath = trim(($safeRelativePath !== '' ? $safeRelativePath . '/' : '') . $safeFileName, '/');
        if ($candidateRelativePath === '' || !$this->sourceEditRelativePathAllowed($candidateRelativePath)) {
            $empty['error'] = (string)__('源码编辑路径被策略保护。');
            $empty['error_code'] = 'source_edit_protected_path';
            return $empty;
        }

        $rootPath = (string)($rootMap[$rootKey]['path'] ?? '');
        $directory = $this->resolveBrowsePath($rootPath, $safeRelativePath);
        if ($directory === null) {
            $empty['error'] = (string)__('写入目录无效或不在允许的根目录内。');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $target = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $safeFileName;
        $targetRealPath = realpath($target);
        if ($targetRealPath === false || !$this->isWithinRoot($rootPath, $targetRealPath)) {
            $empty['error'] = (string)__('源码编辑只允许修改已存在文件。');
            $empty['error_code'] = 'source_edit_existing_file_required';
            return $empty;
        }

        if (!is_file($targetRealPath) || is_link($targetRealPath) || !is_readable($targetRealPath)) {
            $empty['error'] = (string)__('源码编辑只允许修改已存在文件。');
            $empty['error_code'] = 'source_edit_existing_file_required';
            return $empty;
        }

        $bytes = $this->fileSizeBytes($targetRealPath);
        if ($bytes === null || $bytes > self::MAX_TEXT_SAVE_BYTES) {
            $empty['error'] = (string)__('文本内容超过 128 KB 或包含二进制空字节。');
            $empty['error_code'] = 'invalid_text_content';
            return $empty;
        }

        if (!is_writable($targetRealPath)) {
            $empty['error'] = (string)__('目标文件不可写。');
            $empty['error_code'] = 'target_not_writable';
            return $empty;
        }

        return [
            'path' => $targetRealPath,
            'root_path' => $rootPath,
            'error' => '',
            'error_code' => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array{path:string,root_path:string,relative_path:string,parent_path:string,type:string,error:string,error_code:string}
     */
    private function resolveWritableEntry(array $roots, string $rootKey, string $relativePath): array
    {
        $empty = [
            'path' => '',
            'root_path' => '',
            'relative_path' => '',
            'parent_path' => '',
            'type' => '',
            'error' => '',
            'error_code' => '',
        ];

        $rootMap = $this->rootMap($roots);
        if ($rootKey === '' || !isset($rootMap[$rootKey])) {
            $empty['error'] = (string)__('No writable root is available.');
            $empty['error_code'] = 'invalid_root';
            return $empty;
        }

        $root = $rootMap[$rootKey];
        if (empty($root['write_enabled']) || !in_array($rootKey, self::WRITE_ROOT_KEYS, true)) {
            $empty['error'] = (string)__('Selected root is read-only.');
            $empty['error_code'] = 'readonly_root';
            return $empty;
        }

        $safeRelativePath = $this->safeRelativePathForWrite($relativePath);
        if ($safeRelativePath === '') {
            $empty['error'] = (string)__('File path is invalid or outside the selected root.');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $rootPath = (string)($root['path'] ?? '');
        $rootRealPath = realpath($rootPath);
        if ($rootRealPath === false) {
            $empty['error'] = (string)__('File path is invalid or outside the selected root.');
            $empty['error_code'] = 'invalid_write_path';
            return $empty;
        }

        $candidate = $rootRealPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeRelativePath);
        $candidateRealPath = realpath($candidate);
        if ($candidateRealPath === false || !$this->isWithinRoot($rootPath, $candidateRealPath)) {
            $empty['error'] = (string)__('File path is invalid or outside the selected root.');
            $empty['error_code'] = 'entry_not_found';
            return $empty;
        }

        $parentPath = dirname($candidateRealPath);
        if (!is_writable($parentPath)) {
            $empty['error'] = (string)__('Target parent directory is not writable.');
            $empty['error_code'] = 'entry_not_writable';
            return $empty;
        }

        return [
            'path' => $candidateRealPath,
            'root_path' => $rootPath,
            'relative_path' => $safeRelativePath,
            'parent_path' => $this->parentRelativePath($safeRelativePath),
            'type' => is_dir($candidateRealPath) ? 'directory' : 'file',
            'error' => '',
            'error_code' => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     * @return array<string, array<string, mixed>>
     */
    private function rootMap(array $roots): array
    {
        $rootMap = [];
        foreach ($roots as $root) {
            $key = (string)($root['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $rootMap[$key] = $root;
        }

        return $rootMap;
    }

    private function safeRelativePathForWrite(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || trim($path, '/') === '') {
            return '';
        }

        return $this->safeRelativePath($path);
    }

    private function safeNewEntryName(string $name): string
    {
        $name = trim(mb_substr($name, 0, 128));
        if ($name === '' || $name === '.' || $name === '..' || str_starts_with($name, '.')) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) === 1 ? $name : '';
    }

    private function safeArchiveName(string $name): string
    {
        $name = trim(mb_substr($name, 0, 128));
        if ($name === '') {
            return '';
        }

        if (!str_ends_with(strtolower($name), '.zip')) {
            $name .= '.zip';
        }

        $name = $this->safeNewEntryName($name);
        if ($name === '') {
            return '';
        }

        return strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) === 'zip' ? $name : '';
    }

    private function buildSourceArchiveName(string $sourceRelativePath): string
    {
        $baseName = (string)(preg_replace('/[^A-Za-z0-9._-]+/', '_', basename(str_replace('\\', '/', $sourceRelativePath))) ?: 'source');
        $baseName = trim($baseName, '._-');
        if ($baseName === '') {
            $baseName = 'source';
        }

        return $this->safeArchiveName(sprintf(
            'source-archive-%s-%s-%s.zip',
            date('Ymd-His'),
            substr(sha1($sourceRelativePath . '|' . microtime(true)), 0, 12),
            mb_substr($baseName, 0, 64)
        ));
    }

    /**
     * @return array{path:string,error_code:string}
     */
    private function ensureSourceArchiveDirectory(): array
    {
        $varPath = defined('VAR_PATH') ? (string)VAR_PATH : ((defined('BP') ? (string)BP : '') . DIRECTORY_SEPARATOR . 'var');
        $varPath = rtrim($varPath, "\\/");
        $varRealPath = $varPath !== '' ? realpath($varPath) : false;
        if ($varRealPath === false) {
            return ['path' => '', 'error_code' => 'invalid_write_path'];
        }

        $archivePath = rtrim($varRealPath, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::SOURCE_ARCHIVE_RELATIVE_PATH);
        if (!is_dir($archivePath) && !mkdir($archivePath, 0775, true) && !is_dir($archivePath)) {
            return ['path' => '', 'error_code' => 'write_failed'];
        }

        $archiveRealPath = realpath($archivePath);
        if ($archiveRealPath === false || !$this->isCandidateWithinRoot($varRealPath, $archiveRealPath)) {
            return ['path' => '', 'error_code' => 'path_escape'];
        }

        if (!is_writable($archiveRealPath)) {
            return ['path' => '', 'error_code' => 'directory_not_writable'];
        }

        return ['path' => $archiveRealPath, 'error_code' => ''];
    }

    private function isAllowedTextFileName(string $fileName): bool
    {
        $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, self::WRITE_TEXT_EXTENSIONS, true);
    }

    private function isAllowedSourceFileName(string $fileName): bool
    {
        $safeFileName = $this->safeNewEntryName($fileName);
        if ($safeFileName === '') {
            return false;
        }

        $extension = strtolower((string)pathinfo($safeFileName, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, WlsFileManagerPathPolicyService::SOURCE_EDIT_EXTENSIONS, true);
    }

    /**
     * @param array<int, array<string, mixed>> $roots
     */
    private function sourceEditRootEnabled(array $roots, string $rootKey): bool
    {
        if ($rootKey === '' || !in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)) {
            return false;
        }

        $rootMap = $this->rootMap($roots);
        return !empty($rootMap[$rootKey]['source_edit_enabled']);
    }

    private function isAllowedUploadFileName(string $fileName): bool
    {
        $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, self::WRITE_UPLOAD_EXTENSIONS, true);
    }

    private function sourceEditRelativePathAllowed(string $relativePath): bool
    {
        $relativePath = strtolower(trim(str_replace('\\', '/', $relativePath), '/'));
        if ($relativePath === '') {
            return false;
        }

        foreach (self::SOURCE_EDIT_PROTECTED_PATHS as $protectedPath) {
            if ($relativePath === strtolower($protectedPath)) {
                return false;
            }
        }

        foreach (explode('/', $relativePath) as $segment) {
            if (in_array($segment, self::SOURCE_EDIT_PROTECTED_SEGMENTS, true)) {
                return false;
            }
        }

        return true;
    }

    private function directoryIsEmpty(string $path): bool
    {
        if (!is_dir($path) || !is_readable($path)) {
            return false;
        }

        $entries = scandir($path);
        if (!is_array($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, items: array<int, array{path: string, type: string}>}
     */
    private function deleteDirectoryTree(string $sourcePath, string $rootPath): array
    {
        $entries = $this->collectRecursiveDeleteEntries($sourcePath, $rootPath);
        if (!$entries['success']) {
            return $entries;
        }

        try {
            foreach ($entries['items'] as $item) {
                $path = (string)($item['path'] ?? '');
                $type = (string)($item['type'] ?? '');
                if ($path === '') {
                    return $this->deleteTreeResult(false, 'failed', 'delete_failed');
                }

                if ($type === 'directory') {
                    if (!is_dir($path) || is_link($path) || !rmdir($path)) {
                        return $this->deleteTreeResult(false, 'failed', 'delete_failed');
                    }
                    continue;
                }

                if (!is_file($path) || is_link($path) || !unlink($path)) {
                    return $this->deleteTreeResult(false, 'failed', 'delete_failed');
                }
            }
        } catch (\Throwable) {
            return $this->deleteTreeResult(false, 'failed', 'delete_failed');
        }

        return $this->deleteTreeResult(true, 'success', '', (int)$entries['entries'], (int)$entries['bytes']);
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, items: array<int, array{path: string, type: string}>}
     */
    private function collectRecursiveDeleteEntries(string $sourcePath, string $rootPath): array
    {
        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !$this->isWithinRoot($rootPath, $sourceRealPath)) {
            return $this->deleteTreeResult(false, 'denied', 'entry_not_found');
        }

        if (is_link($sourcePath) || is_link($sourceRealPath)) {
            return $this->deleteTreeResult(false, 'denied', 'delete_symlink_unsupported');
        }

        if (!is_dir($sourceRealPath) || !is_readable($sourceRealPath)) {
            return $this->deleteTreeResult(false, 'denied', 'entry_not_readable');
        }

        $items = [];
        $bytes = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRealPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo) {
                    continue;
                }

                if ($fileInfo->isLink()) {
                    return $this->deleteTreeResult(false, 'denied', 'delete_symlink_unsupported');
                }

                $entryPath = $fileInfo->getPathname();
                $entryRealPath = realpath($entryPath);
                if ($entryRealPath === false || !$this->isWithinRoot($rootPath, $entryRealPath) || !$this->isCandidateWithinRoot($sourceRealPath, $entryRealPath)) {
                    return $this->deleteTreeResult(false, 'denied', 'path_escape');
                }

                if (count($items) >= self::MAX_RECURSIVE_DELETE_ENTRIES) {
                    return $this->deleteTreeResult(false, 'denied', 'recursive_delete_entry_limit');
                }

                $entryType = $fileInfo->isDir() ? 'directory' : 'file';
                if (!$fileInfo->isReadable() || !$fileInfo->isWritable()) {
                    return $this->deleteTreeResult(false, 'denied', $entryType === 'directory' ? 'directory_not_writable' : 'entry_not_writable');
                }

                if ($entryType === 'file') {
                    $fileSize = $this->fileSizeBytes($entryRealPath);
                    if ($fileSize === null) {
                        return $this->deleteTreeResult(false, 'denied', 'entry_not_readable');
                    }
                    $bytes += $fileSize;
                    if ($bytes > self::MAX_RECURSIVE_DELETE_BYTES) {
                        return $this->deleteTreeResult(false, 'denied', 'recursive_delete_source_too_large');
                    }
                }

                $items[] = [
                    'path' => $entryRealPath,
                    'type' => $entryType,
                ];
            }
        } catch (\Throwable) {
            return $this->deleteTreeResult(false, 'failed', 'delete_failed');
        }

        if (count($items) >= self::MAX_RECURSIVE_DELETE_ENTRIES) {
            return $this->deleteTreeResult(false, 'denied', 'recursive_delete_entry_limit');
        }

        if (!is_writable($sourceRealPath)) {
            return $this->deleteTreeResult(false, 'denied', 'directory_not_writable');
        }

        $items[] = [
            'path' => $sourceRealPath,
            'type' => 'directory',
        ];

        return $this->deleteTreeResult(true, 'success', '', count($items), $bytes, $items);
    }

    /**
     * @param array<int, array{path: string, type: string}> $items
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, items: array<int, array{path: string, type: string}>}
     */
    private function deleteTreeResult(
        bool $success,
        string $result,
        string $errorCode,
        int $entries = 0,
        int $bytes = 0,
        array $items = []
    ): array {
        return [
            'success' => $success,
            'result' => $result,
            'error_code' => $errorCode,
            'entries' => $entries,
            'bytes' => $bytes,
            'items' => $items,
        ];
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int}
     */
    private function createZipArchive(string $sourcePath, string $targetPath, string $rootPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return $this->compressResult(false, 'failed', 'zip_extension_missing');
        }

        $entries = $this->collectCompressEntries($sourcePath, $rootPath);
        if (!$entries['success']) {
            return $entries;
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($targetPath, \ZipArchive::CREATE | \ZipArchive::EXCL);
        if ($opened !== true) {
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        $sourceRealPath = realpath($sourcePath);
        $sourceBaseName = $sourceRealPath !== false ? basename($sourceRealPath) : 'archive';
        $writeOk = true;

        if ($entries['entries'] === 0 && is_dir($sourcePath)) {
            $writeOk = $zip->addEmptyDir($sourceBaseName);
        }

        foreach ($entries['items'] as $item) {
            $localName = (string)($item['local_name'] ?? '');
            $path = (string)($item['path'] ?? '');
            if ($localName === '' || $path === '') {
                $writeOk = false;
                break;
            }

            if ((string)($item['type'] ?? '') === 'directory') {
                $writeOk = $zip->addEmptyDir($localName);
            } else {
                $writeOk = $zip->addFile($path, $localName);
            }

            if (!$writeOk) {
                break;
            }
        }

        if (!$zip->close() || !$writeOk) {
            if (is_file($targetPath)) {
                unlink($targetPath);
            }
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        return $this->compressResult(true, 'success', '', (int)$entries['entries'], (int)$entries['bytes']);
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, items: array<int, array{path: string, local_name: string, type: string}>}
     */
    private function collectCompressEntries(string $sourcePath, string $rootPath): array
    {
        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !$this->isWithinRoot($rootPath, $sourceRealPath)) {
            return $this->compressResult(false, 'denied', 'entry_not_found');
        }

        if (is_link($sourceRealPath)) {
            return $this->compressResult(false, 'denied', 'compress_symlink_unsupported');
        }

        if (is_file($sourceRealPath)) {
            $bytes = $this->fileSizeBytes($sourceRealPath);
            if ($bytes === null || !is_readable($sourceRealPath)) {
                return $this->compressResult(false, 'denied', 'entry_not_readable');
            }
            if ($bytes > self::MAX_COMPRESS_BYTES) {
                return $this->compressResult(false, 'denied', 'compress_source_too_large');
            }

            return $this->compressResult(true, 'success', '', 1, $bytes, [[
                'path' => $sourceRealPath,
                'local_name' => basename($sourceRealPath),
                'type' => 'file',
            ]]);
        }

        if (!is_dir($sourceRealPath) || !is_readable($sourceRealPath)) {
            return $this->compressResult(false, 'denied', 'entry_not_readable');
        }

        $items = [];
        $bytes = 0;
        $sourcePrefix = rtrim(str_replace('\\', '/', $sourceRealPath), '/') . '/';
        $sourceBaseName = basename($sourceRealPath);

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRealPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo) {
                    continue;
                }

                if ($fileInfo->isLink()) {
                    return $this->compressResult(false, 'denied', 'compress_symlink_unsupported');
                }

                $entryPath = $fileInfo->getPathname();
                $entryRealPath = realpath($entryPath);
                if ($entryRealPath === false || !$this->isWithinRoot($rootPath, $entryRealPath) || !$this->isCandidateWithinRoot($sourceRealPath, $entryRealPath)) {
                    return $this->compressResult(false, 'denied', 'path_escape');
                }

                if (count($items) >= self::MAX_COMPRESS_ENTRIES) {
                    return $this->compressResult(false, 'denied', 'compress_entry_limit');
                }

                $entryType = $fileInfo->isDir() ? 'directory' : 'file';
                if ($entryType === 'file') {
                    if (!$fileInfo->isReadable()) {
                        return $this->compressResult(false, 'denied', 'entry_not_readable');
                    }
                    $bytes += (int)$fileInfo->getSize();
                    if ($bytes > self::MAX_COMPRESS_BYTES) {
                        return $this->compressResult(false, 'denied', 'compress_source_too_large');
                    }
                }

                $entryNormalized = str_replace('\\', '/', $entryRealPath);
                $relativeName = ltrim(str_starts_with($entryNormalized, $sourcePrefix)
                    ? substr($entryNormalized, strlen($sourcePrefix))
                    : basename($entryRealPath), '/');
                $localName = trim($sourceBaseName . '/' . $relativeName, '/');

                if ($localName === '') {
                    continue;
                }

                $items[] = [
                    'path' => $entryRealPath,
                    'local_name' => $localName,
                    'type' => $entryType,
                ];
            }
        } catch (\Throwable) {
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        return $this->compressResult(true, 'success', '', count($items), $bytes, $items);
    }

    /**
     * @param array<int, array{path: string, local_name: string, type: string}> $items
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, items: array<int, array{path: string, local_name: string, type: string}>}
     */
    private function compressResult(
        bool $success,
        string $result,
        string $errorCode,
        int $entries = 0,
        int $bytes = 0,
        array $items = []
    ): array {
        return [
            'success' => $success,
            'result' => $result,
            'error_code' => $errorCode,
            'entries' => $entries,
            'bytes' => $bytes,
            'items' => $items,
        ];
    }

    private function moveUploadedFile(string $tmpName, string $target): bool
    {
        if ($tmpName === '' || !is_file($tmpName) || !is_readable($tmpName)) {
            return false;
        }

        return is_uploaded_file($tmpName) && move_uploaded_file($tmpName, $target);
    }

    private function isCandidateWithinRoot(string $rootPath, string $candidatePath): bool
    {
        $rootRealPath = realpath($rootPath);
        if ($rootRealPath === false) {
            return false;
        }

        $root = rtrim(str_replace('\\', '/', $rootRealPath), '/') . '/';
        $candidate = rtrim(str_replace('\\', '/', $candidatePath), '/');

        return $candidate === rtrim($root, '/') || str_starts_with($candidate . '/', $root);
    }

    private function relativeCandidateIsSymlink(string $rootPath, string $relativePath): bool
    {
        $rootRealPath = realpath($rootPath);
        if ($rootRealPath === false) {
            return false;
        }

        $candidatePath = rtrim($rootRealPath, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($relativePath, '/'));
        return is_link($candidatePath);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function redirectParamsFromInput(array $input, string $rootKey, string $relativePath): array
    {
        $params = [
            'operation' => trim((string)($input['operation'] ?? 'files.write')),
            'project_id' => trim((string)($input['project_id'] ?? '')),
            'domain' => trim((string)($input['domain'] ?? '')),
            'project_type' => trim((string)($input['project_type'] ?? '')),
            'root' => trim($rootKey),
            'path' => trim($relativePath, '/'),
        ];

        return array_filter($params, static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectToFileManager(array $params, string $fragment = '#browser'): void
    {
        if ($this->shouldKeepEmbeddedMode()) {
            $params['embedded'] = '1';
        }

        $this->redirect($this->request->getUrlBuilder()->getBackendUrl($this->fileManagerRouteForFragment($fragment), $params));
    }

    private function fileManagerRouteForFragment(string $fragment): string
    {
        return match (ltrim(trim($fragment), '#')) {
            'roots' => 'weline_filemanager/backend/wls-file-manager/roots',
            'path-policy' => 'weline_filemanager/backend/wls-file-manager/policy-page',
            'write-operations' => 'weline_filemanager/backend/wls-file-manager/write-page',
            'queue-operations' => 'weline_filemanager/backend/wls-file-manager/queue-page',
            'operation-log' => 'weline_filemanager/backend/wls-file-manager/log-page',
            'capabilities' => 'weline_filemanager/backend/wls-file-manager/capabilities-page',
            default => 'weline_filemanager/backend/wls-file-manager/browser',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function appendOperationLog(
        string $action,
        string $result,
        string $rootKey,
        string $relativePath,
        string $target,
        string $messageCode,
        array $context
    ): void {
        $path = $this->operationLogPath();
        if ($path === '') {
            return;
        }

        $logDir = dirname($path);
        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            return;
        }

        $record = [
            'time' => date('Y-m-d H:i:s'),
            'action' => $action,
            'result' => $result,
            'root' => $rootKey,
            'relative_path' => trim($relativePath, '/'),
            'target' => $target,
            'message' => $messageCode,
            'project_id' => trim((string)($context['project_id'] ?? '')),
            'domain' => trim((string)($context['domain'] ?? '')),
            'project_type' => trim((string)($context['project_type'] ?? '')),
            'ip' => $this->clientIp(),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line) || $line === '') {
            return;
        }

        file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array{items: array<int, array<string, string>>, summary: array<string, int|string|bool>, trash_history: array<int, array<string, string>>, trash_summary: array<string, int|string|bool>}
     */
    private function queueOperationData(): array
    {
        $summary = [
            'shown' => 0,
            'scanned' => 0,
            'pending' => 0,
            'running' => 0,
            'done' => 0,
            'error' => 0,
            'available' => true,
            'message' => '',
        ];
        $trashSummary = [
            'shown' => 0,
            'total' => 0,
            'available_restore' => 0,
            'waiting' => 0,
            'unavailable' => 0,
            'purged' => 0,
            'available' => true,
            'message' => '',
        ];

        try {
            $typeId = (int)\w_query('queue', 'getTypeIdByClass', [
                'class' => WlsFileManagerLargeOperationQueue::class,
            ]);
        } catch (\Throwable) {
            $typeId = 0;
            $summary['available'] = false;
            $summary['message'] = 'queue_query_failed';
            $trashSummary['available'] = false;
            $trashSummary['message'] = 'queue_query_failed';
        }

        $params = [
            'module' => 'Weline_FileManager',
            'q' => 'wls_file_manager_large',
            'page' => 1,
            'page_size' => max(self::QUEUE_OPERATION_LIMIT, self::TRASH_HISTORY_LIMIT),
        ];
        if ($typeId > 0) {
            $params['type_id'] = $typeId;
            unset($params['q']);
        }

        try {
            $result = \w_query('queue', 'list', $params);
        } catch (\Throwable) {
            $summary['available'] = false;
            $summary['message'] = 'queue_query_failed';

            return [
                'items' => [],
                'summary' => $summary,
                'trash_history' => [],
                'trash_summary' => $trashSummary,
            ];
        }

        $items = [];
        $trashHistory = [];
        foreach ((array)($result['items'] ?? []) as $item) {
            $row = is_object($item) && method_exists($item, 'getData') ? (array)$item->getData() : (array)$item;
            if ($row === []) {
                continue;
            }

            $payload = json_decode((string)($row['content'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $status = trim((string)($row['status'] ?? ''));
            $operation = trim((string)($payload['operation'] ?? ''));
            $sourcePath = trim((string)($payload['source_path'] ?? ''));
            $trashPath = trim((string)($payload['trash_path'] ?? ''));
            $trashPurgedAt = trim((string)($payload['trash_purged_at'] ?? ''));
            $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? ''));
            $targetRelativePath = trim((string)($payload['target_relative_path'] ?? $payload['trash_relative_path'] ?? ''));
            $restoreState = '';
            $restoreReason = '';
            $canRestore = false;
            $canPurge = false;
            if (in_array($operation, [WlsFileManagerLargeOperationQueue::OPERATION_TRASH_ENTRY, WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_TRASH_ENTRY], true)) {
                [$restoreState, $restoreReason, $canRestore] = $this->trashRestoreState($status, $trashPath, $sourcePath, $trashPurgedAt);
                $canPurge = $operation === WlsFileManagerLargeOperationQueue::OPERATION_TRASH_ENTRY
                    && $this->trashPurgeAvailable($status, $trashPath, $trashPurgedAt);
            }
            if (array_key_exists($status, $summary) && is_int($summary[$status])) {
                $summary[$status]++;
            }
            $summary['scanned']++;

            $itemData = [
                'queue_id' => (string)((int)($row['queue_id'] ?? $row['id'] ?? 0)),
                'name' => trim((string)($row['name'] ?? '')),
                'operation' => $operation,
                'operation_label' => $this->queueOperationLabel($operation),
                'status' => $status,
                'status_label' => $this->queueStatusLabel($status),
                'status_class' => (string)preg_replace('/[^a-z_]/', '', $status),
                'source' => $sourceRelativePath,
                'target' => $targetRelativePath,
                'result' => mb_substr(trim((string)($row['result'] ?? '')), 0, 180),
                'process' => mb_substr(trim((string)($row['process'] ?? '')), 0, 180),
                'start_at' => trim((string)($row['start_at'] ?? '')),
                'end_at' => trim((string)($row['end_at'] ?? '')),
                'biz_key' => trim((string)($row['biz_key'] ?? '')),
                'can_restore' => $canRestore ? '1' : '0',
                'can_purge' => $canPurge ? '1' : '0',
                'trash_purged_at' => $trashPurgedAt,
                'restore_state' => $restoreState,
                'restore_state_label' => $this->trashRestoreStateLabel($restoreState),
                'restore_state_class' => $this->trashRestoreStateClass($restoreState),
                'restore_reason' => $restoreReason !== '' ? ($this->operationMessageLabel($restoreReason) ?: $restoreReason) : '',
            ];

            if (count($items) < self::QUEUE_OPERATION_LIMIT) {
                $items[] = $itemData;
            }

            if (in_array($operation, [WlsFileManagerLargeOperationQueue::OPERATION_TRASH_ENTRY, WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_TRASH_ENTRY], true)) {
                $trashSummary['total']++;
                if ($canRestore) {
                    $trashSummary['available_restore']++;
                } elseif (in_array($restoreState, ['waiting'], true)) {
                    $trashSummary['waiting']++;
                } elseif ($restoreState === 'purged') {
                    $trashSummary['purged']++;
                } else {
                    $trashSummary['unavailable']++;
                }

                if (count($trashHistory) < self::TRASH_HISTORY_LIMIT) {
                    $trashHistory[] = $itemData;
                }
            }
        }

        $summary['shown'] = count($items);
        $trashSummary['shown'] = count($trashHistory);

        return [
            'items' => $items,
            'summary' => $summary,
            'trash_history' => $trashHistory,
            'trash_summary' => $trashSummary,
        ];
    }

    /**
     * @return array{filters: array<string, string>, summary: array<string, int|bool|string>, logs: array<int, array<string, string>>}
     */
    private function operationAuditData(array $roots = []): array
    {
        $filters = $this->operationLogFilters($roots);
        $records = $this->rawOperationLogRecords();
        $summary = [
            'scanned' => count($records),
            'shown' => 0,
            'success' => 0,
            'denied' => 0,
            'failed' => 0,
            'filtered' => $this->hasOperationLogFilters($filters),
            'latest_time' => '',
        ];

        foreach ($records as $record) {
            $result = (string)($record['result'] ?? '');
            if (isset($summary[$result]) && is_int($summary[$result])) {
                $summary[$result]++;
            }
            if ($summary['latest_time'] === '') {
                $summary['latest_time'] = (string)($record['time'] ?? '');
            }
        }

        $logs = [];
        foreach ($records as $record) {
            if (!$this->operationLogMatchesFilters($record, $filters)) {
                continue;
            }

            $logs[] = $this->formatOperationLogRecord($record);
            if (count($logs) >= self::OPERATION_LOG_LIMIT) {
                break;
            }
        }

        $summary['shown'] = count($logs);

        return [
            'filters' => $filters,
            'summary' => $summary,
            'logs' => $logs,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function operationLogFilters(array $roots = []): array
    {
        $action = trim((string)$this->request->getGet('log_action', ''));
        $result = trim((string)$this->request->getGet('log_result', ''));
        $root = trim((string)$this->request->getGet('log_root', ''));
        $query = trim((string)$this->request->getGet('log_query', ''));

        $allowedActions = ['create_directory', 'save_text', 'save_source', 'source_create', 'source_rename', 'source_trash', 'source_trash_queue', 'source_archive_queue', 'source_archive_tree_queue', 'source_archive_selection_queue', 'upload_file', 'rename_entry', 'delete_entry', 'delete_tree', 'compress_entry', 'compress_queue', 'trash_queue', 'trash_restore', 'trash_purge', 'path_policy_save', 'path_policy_reset'];
        $allowedResults = ['success', 'denied', 'failed'];
        $allowedRoots = ['project', 'local_project', 'project_var', 'project_pub', 'app_code', 'var', 'pub', 'policy'];
        if ($roots !== []) {
            $allowedRoots = ['policy'];
            foreach ($roots as $rootCard) {
                $rootKey = trim((string)($rootCard['key'] ?? ''));
                if ($rootKey !== '') {
                    $allowedRoots[] = $rootKey;
                }
            }
        }

        return [
            'action' => in_array($action, $allowedActions, true) ? $action : '',
            'result' => in_array($result, $allowedResults, true) ? $result : '',
            'root' => in_array($root, $allowedRoots, true) ? $root : '',
            'query' => mb_substr($query, 0, 80),
        ];
    }

    /**
     * @param array<string, string> $filters
     */
    private function hasOperationLogFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if (trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rawOperationLogRecords(): array
    {
        $path = $this->operationLogPath();
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $result = [];
        foreach (array_reverse(array_slice($lines, -self::OPERATION_LOG_SCAN_LIMIT)) as $line) {
            $decoded = json_decode((string)$line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $result[] = $decoded;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, string> $filters
     */
    private function operationLogMatchesFilters(array $record, array $filters): bool
    {
        if ($filters['action'] !== '' && (string)($record['action'] ?? '') !== $filters['action']) {
            return false;
        }

        if ($filters['result'] !== '' && (string)($record['result'] ?? '') !== $filters['result']) {
            return false;
        }

        if ($filters['root'] !== '' && (string)($record['root'] ?? '') !== $filters['root']) {
            return false;
        }

        if ($filters['query'] !== '') {
            $needle = mb_strtolower($filters['query']);
            $haystack = mb_strtolower(implode(' ', [
                (string)($record['relative_path'] ?? ''),
                (string)($record['target'] ?? ''),
                (string)($record['message'] ?? ''),
                (string)($record['project_id'] ?? ''),
                (string)($record['domain'] ?? ''),
                (string)($record['ip'] ?? ''),
            ]));
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function formatOperationLogRecord(array $record): array
    {
        $action = (string)($record['action'] ?? '');
        $result = (string)($record['result'] ?? '');

        return [
            'time' => (string)($record['time'] ?? ''),
            'action_code' => $action,
            'action' => $this->operationActionLabel($action),
            'result_code' => $result,
            'result' => $this->operationResultLabel($result),
            'root' => (string)($record['root'] ?? ''),
            'relative_path' => (string)($record['relative_path'] ?? ''),
            'target' => (string)($record['target'] ?? ''),
            'message' => $this->operationMessageLabel((string)($record['message'] ?? '')),
            'project_id' => (string)($record['project_id'] ?? ''),
            'domain' => (string)($record['domain'] ?? ''),
            'ip' => (string)($record['ip'] ?? ''),
        ];
    }

    private function operationLogPath(): string
    {
        $varPath = defined('VAR_PATH') ? VAR_PATH : ((defined('BP') ? BP : '') . 'var' . DIRECTORY_SEPARATOR);
        $varPath = trim((string)$varPath);
        if ($varPath === '') {
            return '';
        }

        return rtrim($varPath, "\\/") . DIRECTORY_SEPARATOR . self::OPERATION_LOG_RELATIVE_PATH;
    }

    private function clientIp(): string
    {
        return trim((string)(
            $this->request->getServer('HTTP_X_FORWARDED_FOR')
            ?: $this->request->getServer('REMOTE_ADDR')
            ?: ''
        ));
    }

    private function resolveNotice(string $code): string
    {
        return match (trim($code)) {
            'file_uploaded' => (string)__('File uploaded.'),
            'entry_renamed' => (string)__('Entry renamed.'),
            'entry_deleted' => (string)__('Entry deleted.'),
            'tree_deleted' => (string)__('Directory tree deleted.'),
            'archive_created' => (string)__('Archive created.'),
            'queue_created' => (string)__('Large-file compression queue created.'),
            'trash_queue_created' => (string)__('Recoverable trash queue created.'),
            'trash_restored' => (string)__('Trash entry restored.'),
            'trash_purged' => (string)__('回收条目已永久清理。'),
            'path_policy_saved' => (string)__('路径策略已保存。'),
            'path_policy_reset' => (string)__('路径策略已恢复默认继承。'),
            'directory_created' => (string)__('目录已创建。'),
            'text_saved' => (string)__('文本文件已保存。'),
            'source_saved' => (string)__('源码文件已保存。'),
            'source_created' => (string)__('源码文件已创建。'),
            'source_renamed' => (string)__('源码文件已重命名。'),
            'source_trashed' => (string)__('源码文件已移入可恢复回收站。'),
            'source_archive_queue_created' => (string)__('Source archive queue created.'),
            'source_archive_tree_queue_created' => (string)__('Source directory archive queue created.'),
            'source_archive_selection_queue_created' => (string)__('Source selection archive queue created.'),
            'source_archive_too_large' => (string)__('Source archive only allows one source file smaller than 128 KB.'),
            default => '',
        };
    }

    private function resolveError(string $code): string
    {
        return $this->operationMessageLabel($code);
    }

    private function operationActionLabel(string $action): string
    {
        return match ($action) {
            'upload_file' => (string)__('Upload file'),
            'rename_entry' => (string)__('Rename entry'),
            'delete_entry' => (string)__('Delete entry'),
            'delete_tree' => (string)__('Delete directory tree'),
            'compress_entry' => (string)__('Compress entry'),
            'compress_queue' => (string)__('Queued compression'),
            'trash_queue' => (string)__('Queued trash move'),
            'trash_restore' => (string)__('Restore trash entry'),
            'trash_purge' => (string)__('Purge trash entry'),
            'path_policy_save' => (string)__('Save path policy'),
            'path_policy_reset' => (string)__('Reset path policy'),
            'create_directory' => (string)__('创建目录'),
            'save_text' => (string)__('保存文本'),
            'save_source' => (string)__('保存源码'),
            'source_create' => (string)__('创建源码文件'),
            'source_rename' => (string)__('重命名源码文件'),
            'source_trash' => (string)__('回收源码文件'),
            'source_trash_queue' => (string)__('队列回收源码文件'),
            'source_archive_queue' => (string)__('Queued source archive'),
            'source_archive_tree_queue' => (string)__('Queued source directory archive'),
            'source_archive_selection_queue' => (string)__('Queued source selection archive'),
            default => $action,
        };
    }

    private function operationResultLabel(string $result): string
    {
        return match ($result) {
            'success' => (string)__('成功'),
            'denied' => (string)__('已拒绝'),
            'failed' => (string)__('失败'),
            default => $result,
        };
    }

    private function operationMessageLabel(string $code): string
    {
        return match (trim($code)) {
            'invalid_upload_file' => (string)__('Upload file name or extension is not allowed.'),
            'uploaded_file_invalid' => (string)__('No valid uploaded file was detected.'),
            'uploaded_file_too_large' => (string)__('Uploaded file must be larger than 0 B and no larger than 5 MB.'),
            'entry_not_found' => (string)__('Target file or directory does not exist.'),
            'entry_not_writable' => (string)__('Target parent directory is not writable.'),
            'upload_failed' => (string)__('Uploaded file could not be saved.'),
            'rename_failed' => (string)__('Rename operation failed.'),
            'delete_root_forbidden' => (string)__('Deleting a root directory is not allowed.'),
            'directory_not_empty' => (string)__('This directory is not empty. Enable recursive delete and type DELETE_TREE to delete it.'),
            'recursive_delete_confirmation_required' => (string)__('Recursive directory delete requires enabling recursive delete and typing DELETE_TREE.'),
            'recursive_delete_entry_limit' => (string)__('Directory tree contains too many entries for recursive delete.'),
            'recursive_delete_source_too_large' => (string)__('Directory tree exceeds the 10 MB recursive delete limit.'),
            'delete_symlink_unsupported' => (string)__('Symbolic links cannot be deleted from the panel.'),
            'delete_failed' => (string)__('Delete operation failed.'),
            'compress_root_forbidden' => (string)__('Compressing a root directory is not allowed.'),
            'invalid_archive_name' => (string)__('Archive name must be a safe .zip file name.'),
            'archive_target_exists' => (string)__('Archive target already exists.'),
            'queue_created' => (string)__('Large-file compression queue created.'),
            'queue_create_failed' => (string)__('Large-file compression queue could not be created.'),
            'queue_already_pending' => (string)__('A pending or running queue already targets this archive.'),
            'queue_query_failed' => (string)__('Large-file queue status could not be loaded.'),
            'trash_queue_created' => (string)__('Recoverable trash queue created.'),
            'trash_queue_already_pending' => (string)__('A pending or running queue already targets this trash move.'),
            'trash_source_forbidden' => (string)__('Root entries and existing trash entries cannot be queued for trash.'),
            'trash_symlink_unsupported' => (string)__('Symbolic links cannot be moved to trash from the panel.'),
            'trash_entry_limit' => (string)__('Trash source contains too many entries for this stage.'),
            'trash_source_too_large' => (string)__('Trash source exceeds the 512 MB queue limit.'),
            'trash_directory_create_failed' => (string)__('Trash directory could not be created.'),
            'trash_directory_not_writable' => (string)__('Trash directory is not writable.'),
            'trash_target_exists' => (string)__('Trash target already exists.'),
            'trash_move_failed' => (string)__('Trash move failed.'),
            'trash_scan_failed' => (string)__('Trash source could not be scanned.'),
            'trash_entry_not_found' => (string)__('Trash entry does not exist.'),
            'trash_entry_invalid' => (string)__('Trash entry is outside the recoverable trash directory.'),
            'invalid_trash_queue' => (string)__('Trash queue record is invalid.'),
            'trash_queue_not_done' => (string)__('Trash queue is not complete yet.'),
            'restore_target_exists' => (string)__('Restore target already exists.'),
            'restore_failed' => (string)__('Trash entry could not be restored.'),
            'trash_restored' => (string)__('Trash entry restored.'),
            'purge_confirmation_required' => (string)__('永久清理前请勾选确认并输入 PURGE_TRASH。'),
            'trash_already_purged' => (string)__('回收条目已永久清理。'),
            'trash_root_purge_forbidden' => (string)__('不能永久清理整个 .wls-trash 根目录。'),
            'trash_purge_failed' => (string)__('回收条目永久清理失败。'),
            'trash_purged' => (string)__('回收条目已永久清理。'),
            'trash_purged_audit_update_failed' => (string)__('回收条目已清理，但队列审计状态更新失败。'),
            'source_trash_queue_created' => (string)__('源码回收队列已创建。'),
            'source_trash_queue_already_pending' => (string)__('已有待处理或运行中的源码回收队列。'),
            'zip_extension_missing' => (string)__('PHP ZipArchive extension is not available.'),
            'entry_not_readable' => (string)__('Target file or directory is not readable.'),
            'compress_symlink_unsupported' => (string)__('Symbolic links cannot be compressed from the panel.'),
            'compress_entry_limit' => (string)__('Archive source contains too many entries for this stage.'),
            'compress_source_too_large' => (string)__('Archive source exceeds the 10 MB compression limit.'),
            'compress_failed' => (string)__('Archive could not be created.'),
            'file_uploaded' => (string)__('File uploaded.'),
            'entry_renamed' => (string)__('Entry renamed.'),
            'entry_deleted' => (string)__('Entry deleted.'),
            'tree_deleted' => (string)__('Directory tree deleted.'),
            'archive_created' => (string)__('Archive created.'),
            'path_policy_saved' => (string)__('路径策略已保存。'),
            'path_policy_reset' => (string)__('路径策略已恢复默认继承。'),
            'path_policy_confirmation_required' => (string)__('保存路径策略前请勾选确认并输入 SAVE_PATH_POLICY。'),
            'path_policy_invalid_root' => (string)__('路径策略包含不支持的写入根目录。'),
            'path_policy_invalid_source_root' => (string)__('路径策略包含不支持的源码编辑根目录。'),
            'path_policy_source_root_required' => (string)__('启用源码编辑时至少选择一个源码根目录。'),
            'path_policy_write_failed' => (string)__('路径策略写入失败。'),
            'path_policy_reset_confirmation_required' => (string)__('恢复默认路径策略前请勾选确认并输入 RESET_PATH_POLICY。'),
            'path_policy_reset_failed' => (string)__('路径策略恢复默认失败。'),
            'method_not_allowed' => (string)__('请求方式错误。'),
            'missing_confirmation' => (string)__('请先完成写入确认。'),
            'invalid_name' => (string)__('名称无效。仅允许字母、数字、点、下划线和短横线，且不能以点开头。'),
            'invalid_text_file' => (string)__('文本文件名无效或扩展名不在允许列表内。'),
            'invalid_source_file' => (string)__('源码文件名无效或扩展名不在允许列表内。'),
            'invalid_text_content' => (string)__('文本内容超过 128 KB 或包含二进制空字节。'),
            'invalid_root' => (string)__('没有可写入的根目录。'),
            'readonly_root' => (string)__('所选根目录当前为只读。'),
            'invalid_write_path' => (string)__('写入目录无效或不在允许的根目录内。'),
            'source_edit_policy_disabled' => (string)__('源码编辑策略未允许当前根目录。'),
            'source_edit_protected_path' => (string)__('源码编辑路径被策略保护。'),
            'source_edit_existing_file_required' => (string)__('源码编辑只允许修改已存在文件。'),
            'source_rename_same_name' => (string)__('源码改名需要提供不同的新名称。'),
            'source_rename_target_exists' => (string)__('目标源码文件已存在。'),
            'source_trash_too_large' => (string)__('源码回收仅允许小于 128 KB 的单个源码文件。'),
            'directory_not_writable' => (string)__('当前目录不可写。'),
            'path_escape' => (string)__('目标路径不在允许的根目录内。'),
            'target_exists' => (string)__('目标已存在。'),
            'target_not_writable' => (string)__('目标文件不可写。'),
            'overwrite_required' => (string)__('目标文件已存在，请勾选覆盖确认。'),
            'write_failed' => (string)__('文件系统写入失败。'),
            'directory_created' => (string)__('目录已创建。'),
            'text_saved' => (string)__('文本文件已保存。'),
            'source_saved' => (string)__('源码文件已保存。'),
            'source_created' => (string)__('源码文件已创建。'),
            'source_renamed' => (string)__('源码文件已重命名。'),
            'source_trashed' => (string)__('源码文件已移入可恢复回收站。'),
            'source_archive_queue_created' => (string)__('Source archive queue created.'),
            'source_archive_tree_queue_created' => (string)__('Source directory archive queue created.'),
            'source_archive_tree_queue_already_pending' => (string)__('A pending or running queue already targets this source directory archive.'),
            'source_archive_selection_queue_created' => (string)__('Source selection archive queue created.'),
            'source_archive_selection_queue_already_pending' => (string)__('A pending or running queue already targets this source selection archive.'),
            'invalid_source_selection' => (string)__('Source selection is invalid.'),
            'source_archive_selection_too_many' => (string)__('Source selection archive accepts up to 20 entries.'),
            'source_archive_too_large' => (string)__('Source archive only allows one source file smaller than 128 KB.'),
            'source_archive_tree_too_large' => (string)__('Source directory archive exceeds the 10 MB source queue limit.'),
            default => '',
        };
    }

    private function queueOperationLabel(string $operation): string
    {
        return match (trim($operation)) {
            WlsFileManagerLargeOperationQueue::OPERATION_COMPRESS_ZIP => (string)__('Queued compression'),
            WlsFileManagerLargeOperationQueue::OPERATION_TRASH_ENTRY => (string)__('Queued trash move'),
            WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_TRASH_ENTRY => (string)__('Queued source trash move'),
            WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_ARCHIVE_FILE => (string)__('Queued source archive'),
            WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_ARCHIVE_TREE => (string)__('Queued source directory archive'),
            WlsFileManagerLargeOperationQueue::OPERATION_SOURCE_ARCHIVE_SELECTION => (string)__('Queued source selection archive'),
            default => $operation !== '' ? $operation : (string)__('Queue operation'),
        };
    }

    private function queueStatusLabel(string $status): string
    {
        return match (trim($status)) {
            'pending' => (string)__('Pending'),
            'running' => (string)__('Running'),
            'done' => (string)__('Done'),
            'error' => (string)__('Error'),
            'stop' => (string)__('Stopped'),
            default => $status,
        };
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function trashRestoreState(string $status, string $trashPath, string $sourcePath, string $purgedAt = ''): array
    {
        $status = trim($status);
        if (trim($purgedAt) !== '') {
            return ['purged', 'trash_purged', false];
        }

        if (in_array($status, ['pending', 'running'], true)) {
            return ['waiting', '', false];
        }

        if ($status === 'error' || $status === 'stop') {
            return ['failed', '', false];
        }

        if ($status !== 'done') {
            return ['waiting', '', false];
        }

        if ($trashPath === '' || $sourcePath === '') {
            return ['unavailable', 'invalid_trash_queue', false];
        }

        if (!file_exists($trashPath)) {
            return ['unavailable', 'trash_entry_not_found', false];
        }

        if (file_exists($sourcePath)) {
            return ['blocked', 'restore_target_exists', false];
        }

        return ['available', '', true];
    }

    private function trashPurgeAvailable(string $status, string $trashPath, string $purgedAt): bool
    {
        if (trim($purgedAt) !== '' || trim($status) !== 'done' || trim($trashPath) === '') {
            return false;
        }

        return file_exists($trashPath);
    }

    private function trashRestoreStateLabel(string $state): string
    {
        return match (trim($state)) {
            'available' => (string)__('可恢复回收项'),
            'waiting' => (string)__('等待队列完成'),
            'blocked' => (string)__('目标已存在'),
            'purged' => (string)__('已永久清理'),
            'unavailable' => (string)__('回收项不可用'),
            'failed' => (string)__('队列失败'),
            default => '',
        };
    }

    private function trashRestoreStateClass(string $state): string
    {
        return match (trim($state)) {
            'available' => 'ok',
            'purged',
            'failed',
            'unavailable' => 'danger',
            'blocked' => 'warning',
            default => '',
        };
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function capabilityCards(array $context = []): array
    {
        $projectWriteScope = (string)($context['project_lookup'] ?? '') === 'found'
            ? (string)__('Project context is resolved; guarded writes can target child project var/pub when those directories exist, are writable, and are enabled by the path policy.')
            : (string)__('Local context only; guarded writes target the current panel instance var/pub roots and can be narrowed by the path policy.');

        return [
            [
                'title' => (string)__('路径白名单'),
                'state' => (string)__('策略受控'),
                'description' => $projectWriteScope,
            ],
            [
                'title' => (string)__('文件浏览'),
                'state' => (string)__('已启用'),
                'description' => (string)__('目录列表、文本预览和受限下载已开放，下载仍限制在 20 MB 内。'),
            ],
            [
                'title' => (string)__('Change Operations'),
                'state' => (string)__('Guarded'),
                'description' => (string)__('Directory creation, small text saves, guarded uploads, same-folder rename, file, empty-directory, bounded recursive directory delete, bounded ZIP compression, and queued large ZIP compression require ACL, confirmation, and operation logs.'),
            ],
        ];
    }

    private function pathPolicyService(): WlsFileManagerPathPolicyService
    {
        if (!$this->pathPolicyService instanceof WlsFileManagerPathPolicyService) {
            $this->pathPolicyService = ObjectManager::getInstance(WlsFileManagerPathPolicyService::class);
        }

        return $this->pathPolicyService;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $realPath = realpath($path);
        $path = $realPath !== false ? $realPath : $path;

        return rtrim(str_replace('\\', '/', $path), '/') . '/';
    }

    private function useStandaloneLayout(): void
    {
        $this->layoutType = 'fullscreen.default';

        $meta = $this->getTemplate()->getData('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['showHeader'] = false;
        $meta['showSidebar'] = false;
        $meta['showFooter'] = false;
        $meta['showRightSidebar'] = false;
        $meta['showPageHeader'] = false;
        $meta['showMessages'] = false;
        $meta['class'] = trim((string)($meta['class'] ?? '') . ' wls-file-manager-fullscreen');

        $this->assign('meta', $meta);
        $this->assign('layoutShowPageHeader', false);
        $this->assign('layoutShowMessages', false);
    }
}
