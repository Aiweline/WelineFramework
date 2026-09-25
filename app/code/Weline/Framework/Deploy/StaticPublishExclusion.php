<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * 静态发布排除规则（唯一权威）。
 *
 * `pub/static` 位于 Web 根之下，凡被铺进去的文件浏览器都能直接取到：文档会被读取，
 * `*.php` 更会被执行（`.phtml` 等在常见 Web 服务器配置下同样按 PHP 处理）。
 * 因此发布树只允许含运行时资源，文档、测试、版本库与工具链元数据、以及服务端
 * 可执行/模板源码必须留在源目录。
 *
 * 本类给出统一的「相对发布根」路径判定，供以下发布链路复用，避免多处规则漂移：
 * - Deploy\Upgrade（模块 view/statics 的 overlay + 扁平双写）
 * - Theme\Console\Theme\Upgrade（app/design/{theme} 设计覆盖搬迁）
 * - Theme\Service\ThemeResourceGateway（按请求即时补发 /static 资源）
 */
final class StaticPublishExclusion
{
    /**
     * 任意层级命中即排除（连同整棵子树）。与用户命名空间无关，可全局判定。
     *
     * @var list<string>
     */
    private const ALWAYS_EXCLUDED_SEGMENTS = [
        // 版本库 / 工具链元数据
        '.git', '.github', '.gitlab', '.circleci', '.husky', '.idea', '.vscode',
        'node_modules', 'bower_components', 'nuget',
        // 文档
        'doc', 'docs', 'documentation',
    ];

    /**
     * 仅在「用户可自命名空间」之外生效的段。
     *
     * `layouts` / `partials` / `widgets` 下是布局与部件的自命名目录，真实存在名为
     * `test` 的布局（如 Theme/view/theme/frontend/layouts/test），故这些段在用户
     * 命名空间内不套用，避免误伤运行时资源。
     *
     * @var list<string>
     */
    private const LIBRARY_EXCLUDED_SEGMENTS = [
        'test', 'tests', '__tests__', 'cypress', 'e2e', 'spec', 'specs',
    ];

    /** @var list<string> */
    private const AUTHORED_NAMESPACE_SEGMENTS = [
        'layouts', 'partials', 'widgets',
    ];

    /**
     * 包管理 / 构建 / Lint 清单：绝无运行时价值，且常含内部依赖与脚本路径。
     *
     * @var list<string>
     */
    private const EXCLUDED_FILE_NAMES = [
        'package.json', 'package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml',
        'composer.json', 'composer.lock', 'bower.json',
        'gruntfile.js', 'gulpfile.js', 'webpack.config.js', 'rollup.config.js', 'vite.config.js',
        'karma.conf.js', 'jest.config.js', 'cypress.json', 'tsconfig.json',
        '.editorconfig', '.gitignore', '.npmignore', '.eslintignore', '.stylelintignore',
        '.travis.yml', '.browserslistrc',
        // Web 服务器 / PHP 运行期配置文件：落在 Web 根里可以改变「谁能访问、什么会被执行」
        // （`.htaccess` 的 `AddHandler`、`php.ini` 的指令、`web.config` 的 handler 映射），
        // 且从不属于运行时静态资源。
        '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config',
    ];

    /** @var list<string> */
    private const EXCLUDED_FILE_NAME_PREFIXES = [
        '.eslintrc', '.stylelintrc', '.babelrc', '.prettierrc',
    ];

    /**
     * 文档类文件名主干（忽略扩展名）：同时覆盖 `README.md` 与无扩展名的 `LICENSE`。
     *
     * @var list<string>
     */
    private const EXCLUDED_DOC_STEMS = [
        'readme', 'changelog', 'contributing', 'license', 'licence', 'copying',
        'notice', 'authors', 'contributors', 'code_of_conduct', 'code-of-conduct',
    ];

    /**
     * 纯文档扩展名（小写、不含点）。刻意不含 `txt`：`robots.txt` 等是合法运行时资源。
     *
     * @var list<string>
     */
    private const EXCLUDED_EXTENSIONS = [
        'md', 'markdown', 'mdown', 'rst', 'adoc', 'asciidoc',
    ];

    /**
     * 服务端可执行 / 模板源码扩展名（小写、不含点）。
     *
     * `pub/static` 在 Web 根之下，被铺进去的文件浏览器都能直接取到：
     * - `*.php` 会被 Web 服务器交给 PHP **执行**（本类 docblock 早已声明的风险）；
     * - `.phtml` / `.pht` / `.phar` / `.php-dist` 在 Apache / LiteSpeed 常见
     *   `AddHandler` 配置下同样按 PHP 处理；
     * - 即使服务器不执行，`Framework\Router\Core::StaticFile()` 也会用
     *   `file_get_contents()` 把**源码原文**回给客户端（源码泄露）。
     *
     * 真实污染：elFinder 的 `view/statics/php/**` 连接器（95 个 `.php` 的三份副本）
     * 与 `theme:upgrade` 误搬的设计主题源码模板（301 个 `.phtml`）。二者均无运行时
     * 消费方：elFinder 由 `VENDOR_PATH . '/autoload.php'` 装载，模板由
     * `ThemePathResolver` 从模块/主题**源目录**解析。
     *
     * @var list<string>
     */
    private const EXCLUDED_SCRIPT_EXTENSIONS = [
        // PHP 家族
        'php', 'phtml', 'pht', 'phps', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
        // 其它 CGI / 脚本语言（静态树里出现即为工具链残留，非运行时资源）
        'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
        // 其它服务端模板
        'asp', 'aspx', 'jsp', 'jspx', 'shtml',
    ];

    /**
     * 判定一个「相对发布根」的路径是否应被排除。
     *
     * @param string $relativePath 相对发布根的路径，`/` 或 `\` 分隔均可
     * @param bool   $isDirectory  该路径是否为目录；目录命中段规则即剪枝整棵子树
     */
    public static function isExcluded(string $relativePath, bool $isDirectory = false): bool
    {
        $normalized = trim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '') {
            return false;
        }

        $segments = explode('/', $normalized);

        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), self::ALWAYS_EXCLUDED_SEGMENTS, true)) {
                return true;
            }
        }

        // 目录段（不含末段文件名）用于判定「是否位于用户命名空间内」。
        $directorySegments = $isDirectory ? $segments : array_slice($segments, 0, -1);
        $authoredNamespace = (bool)array_intersect(
            array_map('strtolower', $directorySegments),
            self::AUTHORED_NAMESPACE_SEGMENTS
        );

        if (!$authoredNamespace) {
            foreach ($directorySegments as $segment) {
                if (in_array(strtolower($segment), self::LIBRARY_EXCLUDED_SEGMENTS, true)) {
                    return true;
                }
            }
        }

        if ($isDirectory) {
            return false;
        }

        return self::isExcludedFileName($segments[count($segments) - 1]);
    }

    private static function isExcludedFileName(string $fileName): bool
    {
        $baseName = strtolower($fileName);
        if (in_array($baseName, self::EXCLUDED_FILE_NAMES, true)) {
            return true;
        }

        foreach (self::EXCLUDED_FILE_NAME_PREFIXES as $prefix) {
            if (str_starts_with($baseName, $prefix)) {
                return true;
            }
        }

        if (in_array(pathinfo($baseName, PATHINFO_FILENAME), self::EXCLUDED_DOC_STEMS, true)) {
            return true;
        }

        $extension = pathinfo($baseName, PATHINFO_EXTENSION);
        if ($extension === '') {
            return false;
        }

        if (in_array($extension, self::EXCLUDED_EXTENSIONS, true)
            || in_array($extension, self::EXCLUDED_SCRIPT_EXTENSIONS, true)
        ) {
            return true;
        }

        // `connector.minimal.php-dist` 这类「可执行扩展名 + 变体后缀」同样排除，
        // 否则 elFinder 的 php 目录会留下一半（`.php` 被排除、`.php-dist` 被铺出去）。
        return str_starts_with($extension, 'php');
    }
}
