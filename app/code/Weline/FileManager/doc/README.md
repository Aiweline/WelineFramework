# Weline FileManager 文件管理模块

## 模块概述

Weline FileManager 是系统的文件管理模块，提供了完整的文件上传、下载、存储、管理功能，支持多种存储方式和文件类型。

## 主要功能

### 1. 文件上传
- 多文件上传
- 文件类型验证
- 文件大小限制
- 上传进度显示

### 2. 文件存储
- 本地存储
- 云存储支持
- 分布式存储
- 存储策略管理

### 3. 文件管理
- 文件列表
- 文件搜索
- 文件分类
- 文件权限控制

### 4. 文件处理
- 图片处理
- 文件压缩
- 格式转换
- 水印添加

### 5. 文件安全
- 文件扫描
- 病毒检测
- 访问控制
- 防盗链保护

## 使用方法

### 文件上传
```php
use Weline\FileManager\Helper\FileUpload;

$uploader = new FileUpload();

// 单文件上传
$file = $_FILES['file'];
$result = $uploader->upload($file, [
    'allowed_types' => ['jpg', 'png', 'gif'],
    'max_size' => 5 * 1024 * 1024, // 5MB
    'upload_path' => 'uploads/images'
]);

if ($result['success']) {
    $filePath = $result['file_path'];
    $fileUrl = $result['file_url'];
} else {
    $error = $result['error'];
}
```

### 批量文件上传
```php
use Weline\FileManager\Helper\FileUpload;

$uploader = new FileUpload();

// 批量上传
$files = $_FILES['files'];
$results = $uploader->uploadMultiple($files, [
    'allowed_types' => ['jpg', 'png', 'gif'],
    'max_size' => 5 * 1024 * 1024,
    'upload_path' => 'uploads/images'
]);

foreach ($results as $result) {
    if ($result['success']) {
        // 处理成功上传的文件
        $filePath = $result['file_path'];
    } else {
        // 处理上传失败的文件
        $error = $result['error'];
    }
}
```

### 文件下载
```php
use Weline\FileManager\Helper\FileDownload;

$downloader = new FileDownload();

// 文件下载
$filePath = 'uploads/documents/file.pdf';
$result = $downloader->download($filePath, [
    'filename' => '自定义文件名.pdf',
    'force_download' => true
]);

if (!$result['success']) {
    $error = $result['error'];
}
```

### 文件管理
```php
use Weline\FileManager\Helper\FileManager;

$manager = new FileManager();

// 获取文件列表
$files = $manager->getFiles('uploads/images', [
    'recursive' => true,
    'filter' => ['jpg', 'png', 'gif']
]);

// 删除文件
$result = $manager->deleteFile('uploads/images/file.jpg');

// 移动文件
$result = $manager->moveFile('uploads/temp/file.jpg', 'uploads/images/file.jpg');

// 复制文件
$result = $manager->copyFile('uploads/images/file.jpg', 'uploads/backup/file.jpg');
```

## 配置说明

### 文件管理配置
在 `app/etc/file_manager.php` 中配置文件管理相关设置：

```php
'file_manager' => [
    'upload' => [
        'max_size' => 10 * 1024 * 1024, // 10MB
        'allowed_types' => ['jpg', 'png', 'gif', 'pdf', 'doc', 'docx'],
        'upload_path' => 'uploads',
        'create_thumbnails' => true,
        'watermark' => false
    ],
    'storage' => [
        'driver' => 'local', // local, s3, oss, cos
        'path' => 'uploads',
        'url' => 'https://example.com/uploads'
    ],
    'security' => [
        'scan_virus' => true,
        'check_mime_type' => true,
        'prevent_hotlinking' => true
    ]
]
```

### 云存储配置
```php
'storage' => [
    's3' => [
        'key' => 'your_access_key',
        'secret' => 'your_secret_key',
        'region' => 'us-east-1',
        'bucket' => 'your_bucket'
    ],
    'oss' => [
        'access_key_id' => 'your_access_key_id',
        'access_key_secret' => 'your_access_key_secret',
        'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
        'bucket' => 'your_bucket'
    ]
]
```

## 依赖关系

- Weline_Framework

## 版本信息

- 当前版本：1.0.0
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 文件处理功能

### 图片处理
```php
use Weline\FileManager\Helper\ImageProcessor;

$processor = new ImageProcessor();

// 生成缩略图
$result = $processor->createThumbnail('uploads/images/large.jpg', [
    'width' => 200,
    'height' => 200,
    'quality' => 80
]);

// 添加水印
$result = $processor->addWatermark('uploads/images/photo.jpg', [
    'watermark' => 'uploads/watermark.png',
    'position' => 'bottom-right',
    'opacity' => 0.5
]);

// 图片压缩
$result = $processor->compress('uploads/images/large.jpg', [
    'quality' => 70,
    'max_width' => 1920,
    'max_height' => 1080
]);
```

### 文件压缩
```php
use Weline\FileManager\Helper\FileCompressor;

$compressor = new FileCompressor();

// 压缩文件
$result = $compressor->compress('uploads/documents/file.pdf', [
    'quality' => 'high',
    'password' => 'optional_password'
]);

// 解压文件
$result = $compressor->extract('uploads/archives/file.zip', [
    'extract_path' => 'uploads/extracted',
    'password' => 'optional_password'
]);
```

## 文件安全

### 文件扫描
```php
use Weline\FileManager\Helper\FileScanner;

$scanner = new FileScanner();

// 扫描文件
$result = $scanner->scanFile('uploads/file.exe', [
    'check_virus' => true,
    'check_mime_type' => true,
    'check_file_content' => true
]);

if ($result['safe']) {
    // 文件安全
} else {
    // 文件不安全，记录日志并删除
    $scanner->logThreat($result);
    $manager->deleteFile('uploads/file.exe');
}
```

### 访问控制
```php
use Weline\FileManager\Helper\FileAccess;

$access = new FileAccess();

// 检查文件访问权限
if ($access->canAccess($userId, 'uploads/private/file.pdf')) {
    // 允许访问
    $downloader->download('uploads/private/file.pdf');
} else {
    // 拒绝访问
    throw new \Exception('无权限访问此文件');
}
```

## 文件管理界面

### 文件管理器
```php
namespace Your\Module\Controller\Admin;

use Weline\Admin\Controller\AbstractAdminController;
use Weline\FileManager\Helper\FileManager;

class FileController extends AbstractAdminController
{
    public function index()
    {
        $manager = new FileManager();
        $path = $this->getRequest()->getParam('path', 'uploads');
        
        $files = $manager->getFiles($path, [
            'recursive' => false,
            'include_directories' => true
        ]);
        
        $this->assign('files', $files);
        $this->assign('current_path', $path);
        return $this->fetch('file/index');
    }
    
    public function upload()
    {
        $uploader = new FileUpload();
        $result = $uploader->upload($_FILES['file']);
        
        if ($result['success']) {
            $this->success('文件上传成功');
        } else {
            $this->error('文件上传失败: ' . $result['error']);
        }
    }
}
```

### 文件管理模板
```html
<!-- 文件管理界面 -->
<div class="file-manager">
    <div class="toolbar">
        <button class="btn btn-primary" onclick="uploadFile()">上传文件</button>
        <button class="btn btn-secondary" onclick="createFolder()">新建文件夹</button>
        <button class="btn btn-danger" onclick="deleteSelected()">删除选中</button>
    </div>
    
    <div class="breadcrumb">
        {foreach $breadcrumbs as $crumb}
            <a href="?path={$crumb.path}">{$crumb.name}</a>
            {if !$crumb@last} / {/if}
        {/foreach}
    </div>
    
    <div class="file-list">
        {foreach $files as $file}
            <div class="file-item" data-path="{$file.path}">
                <input type="checkbox" class="file-checkbox">
                <div class="file-icon">
                    {if $file.is_directory}
                        <i class="fa fa-folder"></i>
                    {else}
                        <i class="fa fa-file"></i>
                    {/if}
                </div>
                <div class="file-name">{$file.name}</div>
                <div class="file-size">{$file.size}</div>
                <div class="file-date">{$file.modified}</div>
                <div class="file-actions">
                    <button onclick="downloadFile('{$file.path}')">下载</button>
                    <button onclick="deleteFile('{$file.path}')">删除</button>
                </div>
            </div>
        {/foreach}
    </div>
</div>
```

## 性能优化

### 1. 文件缓存
- 文件元数据缓存
- 缩略图缓存
- CDN 加速

### 2. 存储优化
- 文件分片上传
- 断点续传
- 异步处理

### 3. 数据库优化
- 文件信息索引
- 批量操作优化
- 查询语句优化

## 安全最佳实践

### 1. 文件验证
- 文件类型验证
- 文件大小限制
- 文件内容检查

### 2. 访问控制
- 基于角色的权限控制
- 文件访问日志
- 防盗链保护

### 3. 存储安全
- 敏感文件加密
- 定期备份
- 安全存储策略

## 调试和测试

### 文件上传测试
```php
// 文件上传功能测试
class FileUploadTest extends TestCase
{
    public function testFileUpload()
    {
        $uploader = new FileUpload();
        
        // 模拟文件上传
        $file = [
            'name' => 'test.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '/tmp/test.jpg',
            'error' => 0,
            'size' => 1024
        ];
        
        $result = $uploader->upload($file);
        $this->assertTrue($result['success']);
    }
}
```

### 文件管理测试
```php
// 文件管理功能测试
class FileManagerTest extends TestCase
{
    public function testFileOperations()
    {
        $manager = new FileManager();
        
        // 测试文件操作
        $testFile = 'uploads/test.txt';
        file_put_contents($testFile, 'test content');
        
        $this->assertTrue($manager->fileExists($testFile));
        $this->assertTrue($manager->deleteFile($testFile));
    }
}
```

## 常见问题

### Q: 如何限制上传文件类型？
A: 在配置中设置 `allowed_types` 参数，或在代码中验证文件扩展名和 MIME 类型。

### Q: 如何处理大文件上传？
A: 使用分片上传功能，设置合适的 `chunk_size` 和 `max_size` 参数。

### Q: 如何实现文件预览？
A: 对于图片文件，生成缩略图；对于文档文件，使用在线预览服务。

### Q: 如何防止文件被恶意上传？
A: 启用文件扫描功能，验证文件类型和内容，限制上传目录权限。

# WLS Panel Integration

`Weline_FileManager` is also the first bundled WLS Panel plugin slice.

- Plugin meta lives in `etc/marketplace/meta.json`.
- Required WLS discovery tags: `module:wls` and
  `custom:wls-file-manager`.
- Additional typed tags: `feature:file-manager`,
  `capability:files-read`, `capability:files-write`,
  `capability:files-policy`, and `system:true`.
- WLS panel entry route:
  `weline_filemanager/backend/wls-file-manager`.
- Current implementation is a standalone shell with guarded directory browsing
  plus controlled file operations. It exposes these allowlisted roots:
  active project root, optional host panel root, module directory, runtime
  directory, public directory, and when a WLS managed project context is
  resolved, child project `var` and `pub` roots.
- Project context is resolved server-side through
  `w_query('server', 'wlsPanelProject', ...)`; the file manager does not depend
  directly on `Weline_Server` services or receive raw `project_path` in URLs.
- Browser requests accept `root` and relative `path`; paths are resolved through
  `realpath()` and must remain inside the selected root. Invalid or escaping
  paths return to the selected root.
- The browser lists directories first, then files, with name, type, size,
  modified time, readable status, preview availability, and download
  availability.
- Text preview is enabled for common text/config/code formats and reads at most
  the first 64 KB. Binary-looking content and unsupported types are rejected
  with an in-panel error state.
- Download is enabled for readable files inside the selected allowlisted root
  and is capped at 20 MB per request. Download responses use framework response
  headers so the behavior works under WLS instead of relying on raw `header()`
  or `readfile()`.
- Controlled write is enabled only for the current panel `var`/`pub` roots or,
  when project context resolves successfully, child project `project_var` and
  `project_pub` roots. The active `project`, `local_project`, and `app_code`
  roots remain read-only in this slice.
- Directory creation requires the `Weline_FileManager::wls_file_manager_write`
  ACL and a confirmation checkbox.
- Small text saves require the same ACL, a confirmation checkbox, and the
  `SAVE_TEXT` confirmation phrase. Content is capped at 128 KB, binary null
  bytes are rejected, and only safe text extensions such as `txt`, `md`,
  `json`, `csv`, `xml`, `yaml`, `yml`, `ini`, `conf`, `log`, and `css` are
  allowed.
- Text preview can now flow into the guarded save form when the previewed file
  is under a writable root, is not truncated, is writable on disk, and uses the
  same safe save extension allowlist.
- Source-code editing has a separate opt-in path policy. When the current
  project/domain policy enables source editing for `project`, `local_project`,
  or `app_code`, already existing small source files can enter a guarded
  `SAVE_SOURCE` form. Enabled source roots can also create one new small source
  file with the dedicated `SOURCE_CREATE_FILE` confirmation phrase when the
  target directory already exists, the file does not already exist, and the
  file name/extension passes the source allowlist. Enabled source roots can
  also rename one existing allowlisted source file inside the current directory
  with the dedicated `SOURCE_RENAME` confirmation phrase, and can move one
  existing allowlisted source file into the same-root `.wls-trash` recoverable
  folder with the dedicated `SOURCE_TRASH` confirmation phrase. Source paths
  still never enable source upload, hard delete, recursive delete, purge, or
  queue operations,
  reject symlinks, forbid overwrites/path escapes, require disk writability,
  cap content at 128 KB, and protect `.env`, `app/etc/env.php`, lock files,
  `.git`, `.wls-trash`, `generated`, `vendor`, `node_modules`, and `var`
  segments.
- Upload requires the same ACL, a confirmation checkbox, and the `UPLOAD_FILE`
  confirmation phrase. Uploads are capped at 5 MB and allow only safe text,
  document, archive, and web-asset extensions. Executable PHP/shell-style
  extensions remain blocked.
- Rename requires the same ACL, a confirmation checkbox, and the
  `RENAME_ENTRY` confirmation phrase. The current slice only supports
  same-folder rename inside the selected writable root.
- Delete requires the same ACL and confirmation checkbox. Files and empty
  directories require the `DELETE_ENTRY` confirmation phrase.
- Non-empty directory delete is available only through bounded recursive mode:
  operators must explicitly enable recursive delete, type `DELETE_TREE`, stay
  inside the selected writable root, avoid symbolic links, and fit within the
  100-entry / 10 MB scan limit. The controller scans the tree before deleting
  from leaf nodes upward.
- Compress requires the same ACL, a confirmation checkbox, and the
  `COMPRESS_ENTRY` confirmation phrase. The current slice creates a sibling
  `.zip` archive beside the selected file or directory, rejects root-directory
  compression and symbolic links, caps sources at 200 entries and 10 MB, never
  overwrites an existing archive, and removes partial ZIP files on failure.
- Queued compression is available for larger ZIP work through
  `Weline\FileManager\Queue\WlsFileManagerLargeOperationQueue`. The panel
  requires the same write ACL, a confirmation checkbox, and the
  `QUEUE_COMPRESS` phrase, then creates a `Weline_Queue` task through
  `w_query('queue', 'create', ...)`. The worker re-checks root boundaries,
  refuses symbolic links/path escapes, caps sources at 2000 entries and
  512 MB, never overwrites an existing archive, and stores recent queue status
  in the standalone shell.
- Recoverable queued trash is available through the same large-operation queue
  worker. The panel requires the write ACL, confirmation checkbox, and
  `QUEUE_TRASH`; the worker re-checks the selected root, rejects symlinks,
  caps sources at 2000 entries and 512 MB, and moves the selected file or
  directory into the same-root `.wls-trash` folder. Completed trash jobs expose
  a Restore action in the recent queue list while the original path is still
  free; restore uses only the server-side queue payload plus `queue_id`, not a
  raw browser-submitted absolute path.
- The first richer restore-history slice is available in the same queue
  section. The panel now queries a wider queue window, keeps the general recent
  queue list compact, and renders a dedicated recoverable trash history showing
  up to 30 `trash_entry` jobs with restore availability, waiting/failed states,
  target-exists blockers, and missing-trash reasons. Restore still posts only
  `queue_id` plus the standard `RESTORE_TRASH` confirmation.
- The first permanent purge slice is available from the recoverable trash
  history. Purge is POST-only, requires the write ACL, a confirmation checkbox,
  and the `PURGE_TRASH` phrase, then reloads the queue payload by `queue_id`
  and deletes only the recorded `.wls-trash` entry after service-layer
  root-boundary, symlink, entry-count, and byte-limit checks. Successful purge
  updates the queue content with `trash_purged_at` so the history can
  distinguish intentionally purged entries from missing trash files.
- Operation attempts are appended to `var/log/wls_file_manager_operations.log`
  as JSON lines. The standalone plugin page scans the latest 200 entries,
  renders summary counters for scanned/shown/success/denied/failed events, and
  filters the latest operation list by action, result, root, and keyword.
- Project-level path policy editing is now available in the standalone plugin
  shell. The policy form saves only safe context (`project_id`, `domain`,
  `project_type`) plus enabled write-root keys to
  `var/wls-panel/file-manager-path-policy.json`; it never posts raw
  `project_path` values back to the browser. Saved policies immediately narrow
  all guarded write actions because `rootCards()` applies the policy before
  create/save/upload/rename/delete/compress resolution.
- The same path-policy section can reset the current project/domain policy
  after the operator confirms `RESET_PATH_POLICY`. Reset removes that saved
  context entry and returns controlled roots to the default inherited policy
  immediately.
- Safe-text editor ergonomics are now covered for the existing guarded
  `SAVE_TEXT` path: the panel shows wrap/font controls, dirty state, line/
  character/byte/cursor metrics, a safe revert action, and mobile-safe toolbar
  wrapping while still requiring the existing confirmation checkbox and phrase.
- Broader source-tree write policy remains layered; the current source-code
  capability is intentionally limited to existing small-file `SAVE_SOURCE`
  edits, single-file `SOURCE_CREATE_FILE` creation, and same-directory
  `SOURCE_RENAME` plus single-file recoverable `SOURCE_TRASH` under explicitly
  enabled source roots. Source queue operations remain future slices. The queue-backed destructive
  path for ordinary writable roots is recoverable by default, has a dedicated
  restore-history view, and now includes explicit permanent purge for
  queue-created trash entries only.
