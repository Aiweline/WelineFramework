<?php

declare(strict_types=1);

namespace Agent\CursorBase\Helper;

/**
 * 文件模板助手
 * 
 * 职责：根据文件类型生成初始模板内容
 */
class FileTemplateHelper
{
    /**
     * 创建文件模板
     *
     * @param string $filePath 文件路径
     * @param array $task 任务信息
     * @return string 模板内容
     */
    public static function createTemplate(string $filePath, array $task): string
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        return match ($extension) {
            'php' => self::createPhpTemplate($filePath, $task),
            'phtml' => self::createPhtmlTemplate($task),
            'js' => self::createJsTemplate($task),
            'css' => self::createCssTemplate($task),
            'json' => self::createJsonTemplate($task),
            'md' => self::createMarkdownTemplate($task),
            default => self::createDefaultTemplate($task),
        };
    }

    /**
     * 创建 PHP 模板
     */
    public static function createPhpTemplate(string $filePath, array $task): string
    {
        $namespace = self::inferNamespace($filePath);
        $className = pathinfo($filePath, PATHINFO_FILENAME);
        $description = $task['description'] ?? 'TODO: 实现功能';

        $type = self::inferPhpType($filePath);

        $content = "<?php\n\ndeclare(strict_types=1);\n\n";
        $content .= "namespace {$namespace};\n\n";

        switch ($type) {
            case 'controller':
                $content .= "use Weline\\Framework\\App\\Controller\\BackendController;\n\n";
                $content .= "/**\n * {$description}\n */\n";
                $content .= "class {$className} extends BackendController\n{\n";
                $content .= "    public function index(): string\n    {\n";
                $content .= "        return 'Hello World';\n";
                $content .= "    }\n";
                $content .= "}\n";
                break;

            case 'model':
                $content .= "use Weline\\Framework\\Database\\Model;\n";
                $content .= "use Weline\\Framework\\Database\\Api\\Db\\TableInterface;\n";
                $content .= "use Weline\\Framework\\Setup\\Data\\Context;\n";
                $content .= "use Weline\\Framework\\Setup\\Db\\ModelSetup;\n\n";
                $content .= "/**\n * {$description}\n */\n";
                $content .= "class {$className} extends Model\n{\n";
                $content .= "    public const TABLE_NAME = '';\n";
                $content .= "    public const PRIMARY_KEY = 'id';\n";
                $content .= "}\n";
                break;

            case 'service':
                $content .= "/**\n * {$description}\n */\n";
                $content .= "class {$className}\n{\n";
                $content .= "    public function execute(): void\n    {\n";
                $content .= "        // TODO: 实现业务逻辑\n";
                $content .= "    }\n";
                $content .= "}\n";
                break;

            case 'interface':
                $content .= "/**\n * {$description}\n */\n";
                $content .= "interface {$className}\n{\n";
                $content .= "    // TODO: 定义接口方法\n";
                $content .= "}\n";
                break;

            default:
                $content .= "/**\n * {$description}\n */\n";
                $content .= "class {$className}\n{\n";
                $content .= "    // TODO: 实现类逻辑\n";
                $content .= "}\n";
        }

        return $content;
    }

    /**
     * 从文件路径推断命名空间
     */
    public static function inferNamespace(string $filePath): string
    {
        $relativePath = str_replace([BP, 'app/code/', 'app\\code\\'], '', $filePath);
        $dirPath = dirname($relativePath);
        $namespace = str_replace(['/', '\\'], '\\', $dirPath);
        return trim($namespace, '\\');
    }

    /**
     * 推断 PHP 文件类型
     */
    private static function inferPhpType(string $filePath): string
    {
        if (str_contains($filePath, 'Controller')) {
            return 'controller';
        }
        if (str_contains($filePath, 'Model')) {
            return 'model';
        }
        if (str_contains($filePath, 'Service')) {
            return 'service';
        }
        if (str_contains($filePath, 'Interface') || str_contains($filePath, 'Api')) {
            return 'interface';
        }
        return 'class';
    }

    /**
     * 创建 PHTML 模板
     */
    public static function createPhtmlTemplate(array $task): string
    {
        $description = $task['description'] ?? 'TODO: 实现视图';

        return <<<HTML
<?php
/**
 * {$description}
 */
?>
<div class="container">
    <h1><?= __('页面标题') ?></h1>
</div>
HTML;
    }

    /**
     * 创建 JS 模板
     */
    public static function createJsTemplate(array $task): string
    {
        $description = $task['description'] ?? 'TODO: 实现 JavaScript 逻辑';

        return <<<JS
/**
 * {$description}
 */
(function() {
    'use strict';

    // TODO: 实现 JavaScript 逻辑

})();
JS;
    }

    /**
     * 创建 CSS 模板
     */
    public static function createCssTemplate(array $task): string
    {
        $description = $task['description'] ?? 'TODO: 实现样式';

        return <<<CSS
/**
 * {$description}
 */

/* TODO: 实现样式 */
CSS;
    }

    /**
     * 创建 JSON 模板
     */
    public static function createJsonTemplate(array $task): string
    {
        return "{\n}\n";
    }

    /**
     * 创建 Markdown 模板
     */
    public static function createMarkdownTemplate(array $task): string
    {
        $description = $task['description'] ?? '文档';

        return "# {$description}\n\nTODO: 编写文档内容\n";
    }

    /**
     * 创建默认模板
     */
    public static function createDefaultTemplate(array $task): string
    {
        $description = $task['description'] ?? 'TODO';
        return "// {$description}\n";
    }
}
