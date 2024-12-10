<?php

namespace Weline\FileManager\Taglib;

use Weline\Taglib\TaglibInterface;

class FileVIew implements TaglibInterface
{

    /**
     * @inheritDoc
     */
    static public function name(): string
    {
        return 'file-view';
    }

    /**
     * @inheritDoc
     */
    static function tag(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function attr(): array
    {
        return ['type' => false, 'vars' => true, 'value' => true,'width' => false, 'height' => false];
    }

    /**
     * @inheritDoc
     */
    static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            # 分配Block
            $attributes['class'] = \Weline\FileManager\Block\Taglib\FileVIew::class;
            $attributes['width'] = $attributes['width'] ?? 50;
            $attributes['height'] = $attributes['height'] ?? 50;
            // 变量导入
            $vars_string = '[';
            if (isset($attributes['vars'])) {
                $vars = explode('|', $attributes['vars']);
                foreach ($vars as $key => $var) {
                    $var_name    = trim($var);
                    $var         = '$' . $var_name;
                    $vars_string .= "'$var_name'=>&$var,";
                }
            }
            $vars_string .= ']';
            return '<?php echo framework_view_process_block(' . w_var_export($attributes, true) . ',$vars=' . $vars_string . ');?>';
        };
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    static function document(): string
    {
        $doc = htmlentities(
            '<file-view type="image" vars="product" value="product.image"/>'
        );
        return <<<HTML
使用方法：
{$doc}
参数解释：
type：可选，文件类型，目前仅支持image。默认就是图片
vars：可选，变量名，用于变量导入。
value：必选，图片名。多张图片用,号隔开。所以图片地址中不能包含英文逗号“,”
HTML;
    }
}