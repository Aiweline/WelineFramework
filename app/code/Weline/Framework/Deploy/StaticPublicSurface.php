<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * 静态资源「公共面」统一口径（唯一权威）。
 *
 * # 为什么必须有这一类
 *
 * 历史上「什么能作为静态字节对外提供」被写在四处，且强度互不相同：
 *
 * | # | 位置 | 强度 | 现状 |
 * |---|------|------|------|
 * | 1 | `Server/bin/worker_ssl.php` `handleStaticFile()` 的 `$staticExtensions` | 白名单，**正确** | 但命中与否只是「是否走快路径」——不匹配时 `return null`，请求继续进入框架 |
 * | 2 | `Router\Core::StaticFile()` | 无策略 | 唯一守卫是「路径含 `view`」，随后 `file_get_contents()` 原样回吐 |
 * | 3 | nginx 站点配置 | 残缺 | `^~` 前缀 location 会跳过所有正则 deny；`/s/{version}/` 无任何过滤 |
 * | 4 | {@see StaticPublishExclusion}（发布侧） | 排除规则 | 只管「什么不该被铺进 `pub/static`」 |
 *
 * 因此真实事故是：**传输层正确地「不接管」，却被框架回退层当作「允许」**。
 * 实测（纯 WLS、不经 nginx）：`GET /static/.../view/statics/_probe.php`
 * → `HTTP 200` `Content-Type: text/x-php`，PHP 源码原文回吐。
 *
 * # 本类确立的架构原则
 *
 * **「不接管」≠「允许」。** 快路径列表只是**性能优化**，不是**安全边界**；
 * 安全边界必须是一条被**每一层**都执行的判定，且回退层的默认动作是**拒绝**。
 *
 * 判定由两部分组成：
 * - **拒绝规则（权威）**：复用 {@see StaticPublishExclusion}。
 *   凡发布侧不允许落进 Web 根的，服务侧一律不得回吐 ——
 *   「发布不了的东西，绝不可能是给人下载的东西」。
 * - **允许规则（纵深防御）**：对**编译产物树**（`static` / `s`）额外要求扩展名在
 *   {@see self::SERVABLE_EXTENSIONS} 内，一举挡掉 `.scss` / `.less` / `.ts` /
 *   `.hbs` / `.sql` / `.lock` / `Makefile` 这类构建残留。
 *
 * # 三个消费点（都必须走这里）
 *
 * - `Router\Core::StaticFile()` —— 纯 WLS / 无 nginx 时的服务侧执行点
 * - `Server/bin/worker_ssl.php` `handleStaticFile()` —— WLS 传输层快路径
 * - nginx 站点配置（见仓库根 `nginx.static-surface.conf`）—— 由 {@see self::servableExtensionPattern()} 生成/断言
 *
 * 三者的一致性由 `Framework/Test/Unit/Deploy/StaticPublicSurfaceContractTest` 锁定。
 */
final class StaticPublicSurface
{
    /**
     * 「编译产物树」的 URL 前缀（相对 Web 根，不含斜杠）。
     *
     * 这些前缀下的每个合法产物都**有扩展名**，因此可以套用严格的扩展名白名单。
     */
    private const ALLOWLIST_ROOTS = [
        'static', // pub/static —— 发布树
        's',      // /s/{deploy_version}/ —— 版本化对外前缀（映射到 pub/static）
    ];

    /**
     * 只套拒绝规则、不套扩展白名单的公共前缀。
     *
     * 原因：这些空间里的文件**可能没有扩展名**（`.well-known/acme-challenge/xxx`
     * 就没有），套白名单会打断 ACME 校验；但 `.php` 之类仍必须拒绝。
     */
    private const DENY_ONLY_ROOTS = [
        'media',
        'sitemaps',
        'errors',
        '.well-known',
        'theme_previews',
    ];

    /**
     * 允许作为静态字节直接回给客户端的扩展名（小写、不含点）。
     *
     * 刻意**不含**任何服务端可执行/模板扩展名，也不含构建期扩展名
     * （`scss` / `less` / `ts` / `hbs` / `sql` / `lock` / `bak`）。
     *
     * 取值依据：对 `pub/static` 全量实测，白名单外的 764 个文件**全部**是构建
     * 残留（`.scss` 600 / `.less` 70 / `.lock` 42 / `.hbs` 8 / `Makefile` 等），
     * 无任何运行时资源；且全仓无任何代码以 HTTP 方式取 `.scss` / `.less` / `.hbs`。
     *
     * @var list<string>
     */
    public const SERVABLE_EXTENSIONS = [
        // 样式与脚本
        'css', 'js', 'mjs', 'map',
        // 数据 / 文本
        'json', 'xml', 'txt', 'webmanifest', 'html', 'htm',
        // 位图
        'jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        // 字体
        'woff', 'woff2', 'eot', 'ttf', 'otf',
        // 音视频
        'mp4', 'mp3', 'm4a', 'aac', 'webm', 'ogg', 'm3u8', 'wav',
        // 文档
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        // 归档
        'zip', 'rar', '7z', 'gz', 'tar',
        // 历史遗留（旧版 nginx 模板与存量资源仍在用）
        'swf',
    ];

    /**
     * WLS 传输层快路径扩展名（性能优化，非安全边界）。
     *
     * 必须是 {@see self::SERVABLE_EXTENSIONS} 的子集：快路径只负责「值不值得
     * 在传输层直接回」，不匹配的请求会交给框架，框架仍会执行完整口径。
     * 子集关系由契约测试锁定。
     *
     * @var list<string>
     */
    public const FAST_PATH_EXTENSIONS = [
        'css', 'js', 'map',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'eot', 'ttf', 'otf',
        'mp4', 'mp3', 'm4a', 'aac', 'webm', 'ogg', 'm3u8',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'json', 'xml',
        'zip', 'rar', '7z', 'gz', 'tar',
    ];

    /**
     * 判定一个 URL 路径能否作为静态字节对外提供。
     *
     * @param string $urlPath 形如 `/static/...`、`/s/20260914/...`、`/media/...`；
     *                        可带 query / fragment，容错 `//`、`\` 与首尾斜杠
     * @return bool true 表示允许；false 表示必须 404/403（**默认拒绝**）
     */
    public static function isServableUrlPath(string $urlPath): bool
    {
        $normalized = self::normalize($urlPath);
        if ($normalized === '') {
            return false;
        }

        $segments = explode('/', $normalized);
        $root = $segments[0];
        if (!self::isPublicRoot($root)) {
            return false;
        }

        // 相对「公共根」的路径：/static/a/b.css → a/b.css；/s/{ver}/a/b.css → a/b.css
        $relative = self::relativeToPublicRoot($segments, $root);
        if ($relative === '') {
            return false;
        }

        // 1) 拒绝规则（权威）：发布侧不允许落地的，服务侧一律不得回吐。
        if (StaticPublishExclusion::isExcluded($relative)) {
            return false;
        }

        // 2) 允许规则（纵深防御）：只对编译产物树套扩展名白名单。
        if (!in_array($root, self::ALLOWLIST_ROOTS, true)) {
            return true;
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::SERVABLE_EXTENSIONS, true);
    }

    /**
     * 判定一个**已解析出的物理文件**能否作为静态字节对外提供。
     *
     * 服务侧执行点（如 `Router\Core::StaticFile()`）最终拿到的是磁盘路径，
     * 而口径是按「公共面相对路径」定义的，这里负责把磁盘路径映射回去：
     *
     * - 位于 `pub/` 之下 → 直接按 URL 同构的相对路径判定；
     * - 位于 `pub/` 之外（DEV 的 `app/code/**`、`vendor/**` 直取）→ 不可能是
     *   公共面路径，按「文件名 + 扩展名」判定。DEV 下 `view/templates/*.phtml`
     *   同样含 `view` 段、会被 `StaticFile()` 的旧守卫放行，此处一并挡掉。
     *
     * @param string      $absolutePath 已确认存在的物理文件路径
     * @param string|null $publicRoot   公共根（默认 `{BP}/pub`）
     */
    public static function isServableFile(string $absolutePath, ?string $publicRoot = null): bool
    {
        $path = str_replace('\\', '/', $absolutePath);

        if ($publicRoot === null) {
            $base = defined('BP') ? (string)\constant('BP') : '';
            $publicRoot = $base === '' ? '' : rtrim(str_replace('\\', '/', $base), '/') . '/pub';
        }
        $publicRoot = rtrim(str_replace('\\', '/', (string)$publicRoot), '/');

        if ($publicRoot !== '' && str_starts_with($path, $publicRoot . '/')) {
            return self::isServableUrlPath(substr($path, strlen($publicRoot) + 1));
        }

        return self::isServableAssetName(basename($path));
    }

    /**
     * 按「文件名 + 扩展名」判定（用于不在公共根之下的文件）。
     */
    private static function isServableAssetName(string $baseName): bool
    {
        if ($baseName === '') {
            return false;
        }

        if (StaticPublishExclusion::isExcluded($baseName)) {
            return false;
        }

        $extension = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::SERVABLE_EXTENSIONS, true);
    }

    /**
     * nginx / 文档用的扩展名正则片段（已转义、可直接放进 `~* \.(...)$`）。
     *
     * 生成而非手写，避免 nginx 与 PHP 两处漂移。
     */
    public static function servableExtensionPattern(): string
    {
        $escaped = array_map(static fn(string $ext): string => preg_quote($ext, '/'), self::SERVABLE_EXTENSIONS);

        return implode('|', $escaped);
    }

    /**
     * 归一化 URL 路径：去 query/fragment、去 `..`、统一分隔符与斜杠。
     *
     * @return string 相对 Web 根的路径（无首尾斜杠）；非法输入返回 ''
     */
    private static function normalize(string $urlPath): string
    {
        $path = str_replace('\\', '/', $urlPath);
        $path = (string)parse_url($path, PHP_URL_PATH);
        $path = str_replace('//', '/', $path);
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        // 目录穿越：任何 `..` 段直接拒绝，不做「消解」以免与真实资源混淆。
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return '';
            }
        }

        return $path;
    }

    private static function isPublicRoot(string $root): bool
    {
        return in_array($root, self::ALLOWLIST_ROOTS, true)
            || in_array($root, self::DENY_ONLY_ROOTS, true);
    }

    /**
     * @param list<string> $segments
     */
    private static function relativeToPublicRoot(array $segments, string $root): string
    {
        // /s/{deploy_version}/... → 版本目录只是对外前缀，映射到同一棵 pub/static
        if ($root === 's' && count($segments) > 1) {
            $segments = array_slice($segments, 2);
        } else {
            $segments = array_slice($segments, 1);
        }

        return implode('/', $segments);
    }
}
