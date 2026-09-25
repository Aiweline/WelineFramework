<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * 静态发布排除规则（唯一权威）。
 *
 * `pub/static` 位于 Web 根之下，凡被铺进去的文件浏览器都能直接取到：文档会被读取，
 * `*.php` 更会被执行。因此发布树只允许含运行时资源，文档、测试、版本库与工具链
 * 元数据必须留在源目录。
 *
 * 本类给出统一的「相对发布根」路径判定，供以下发布链路复用，避免两处规则漂移：
 * - Deploy\Upgrade（模块 view/statics 的 overlay + 扁平双写）
 * - Theme\Console\Theme\Upgrade（app/design/{theme} 设计覆盖搬迁）
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

        return $extension !== '' && in_array($extension, self::EXCLUDED_EXTENSIONS, true);
    }
}
