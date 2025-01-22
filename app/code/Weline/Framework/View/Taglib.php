<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block\Csrf;
use Weline\Framework\View\Cache\ViewCache;
use Weline\Framework\View\Exception\TemplateException;

class Taglib
{
    public const operators_symbols = [
        # 比较
        '>',
        '<',
        '!==',
        '===',
        '==',
        '!=',
        '<>',
        '>=',
        '<=>',
        '<=',
        # 逻辑
        '&&',
        '||',
        '|',
        '!',
        ' and ',
        ' or ',
        ' xor ',
        # 算数运算
        '**',
        '%',
        '/',
        '*',
        '-',
        '+',
        # 位运算
        '<<',
        '>>',
        '&',
        '^^',
        '^',
        '|',
        # 赋值运算
        '=',
        '+=',
        '-=',
        '*=',
        '/=',
        '%=',
        '<<=',
        '>>=',
        '&=',
        '^^=',
        '^=',
        '|='
    ];

    public const operators_symbols_to_lang = [
        '||' => ' or ',
        '&&' => ' and ',
//        '|'=>' or ', 已当做过滤器使用
        '&' => ' and ',
        'xor' => ' xor ',
        ' neq ' => ' !== ',
        ' eq ' => ' == ',
        ' gt ' => ' > ',
        ' lt ' => ' < ',
        ' gte ' => ' >= ',
        ' lte ' => ' <= '
    ];

    public const special_lang_symbols = [
        'null', 'and', 'or', 'xor', '||', 'neq', 'eq', 'gt', 'lt', 'gte', 'lte'
    ];

    public function checkFilter(string $name, string $filter = '|', $default = '\'\''): array
    {
        if (str_contains($name, PHP_EOL)) {
            $name = str_replace(array("\r\n", "\r", "\n", "\t", ' '), '', $name);
        }
        if (str_contains($name, $filter)) {
            $name_arr = explode('|', $name);
            $name = $name_arr[0];
            if (w_get_string_between_quotes($name_arr[1])) {
                $default = $name_arr[1];
            } else {
                $default = $this->varParser($name_arr[1]);
            }
        }
        return [$name, $default];
    }

    public function checkVar(string $name): string
    {
        if (str_starts_with($name, '$')) {
            //            return '('.$name.'??"")';
            return $name;
        }
        # 有字母的，且不是字符串，不存在特殊字符内的，可以加$
        if (preg_match('/^[a-zA-Z|\|\|]/', $name)) {
            if (!in_array($name, self::special_lang_symbols) and !str_starts_with($name, '"') and !str_starts_with($name, "'")) {
                $name = $name ? '$' . $name : $name;
            }
        }
        return $name;
    }

    public function varParser(string $name): string
    {
        $name_str = '';
        # 处理过滤器
        list($name, $default) = $this->checkFilter($name);
        # 去除空白以及空格
        $name = $this->checkVar($name);


        # 处理转行变量
        //        $name = str_replace('    ', '', $name);
        $name = preg_replace('/ {4,}/', '', $name);

        # 单双引号包含的字符串不解析
        $exclude_names = w_get_string_between_quotes($name);

        foreach ($exclude_names as $key => $exclude_name) {
            $name = str_replace($exclude_name, 'w_var_str' . $key, $name);
        }

        $pattern = '/(?<![\-\>()\s])\s*([><=!]={1,3}+|&&|\|\|)\s*(?![()\s])/';
        $name = preg_replace($pattern, ' $1 ', $name);

        //        $name = $newString;
        //        d($name);
        foreach ($exclude_names as $key => $exclude_name) {
            $name = str_replace('w_var_str' . $key, $exclude_name, $name);
        }
        $names = explode(' ', $name);
        foreach ($names as $name_key => $var) {
            # 排除字符串
            if (!str_contains($var, '"') && !str_contains($var, '\'')) {
                $var = $this->checkVar($var);
            }
            $pieces = explode('.', $var);
            $has_piece = false;
            if (count($pieces) > 1) {
                //                if (PROD) {
                //                    $name_str .= '(';
                //                }
                $name_str .= '(';
                $has_piece = true;
            }
            foreach ($pieces as $key => $piece) {
                if (0 !== $key) {
                    if (str_contains($piece, '$')) {
                        $piece = '[' . $this->varParser(implode('.', $pieces)) . ']';
                        $name_str .= $piece;
                        break;
                    } else {
                        $piece = '[\'' . $piece . '\']';
                    }
                }
                $name_str .= $piece;
                unset($pieces[$key]);
            }
            $name_str = $has_piece ? "{$name_str}??{$default}) " : $name_str . ' ';
        }

        // 替换操作符
        foreach (self::operators_symbols_to_lang as $item) {
            if (str_contains($name_str, $item)) {
                $name_str = str_replace($item, ' ' . $item . ' ', $name_str);
            }
        }

        return $name_str;
    }

    public function getTags(Template $template, string $fileName = '', $content = ''): array
    {
        $tags = [
            'php' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return match ($tag_key) {
                            'tag-start' => '<?php ',
                            'tag-end' => '?>',
                            default => "<?php {$tag_data[1]} ?>"
                        };
                    }
            ],
            'include' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return match ($tag_key) {
                            'tag-start' => '<?php include(',
                            'tag-end' => ');?>',
                            default => "<?php include({$tag_data[1]});?>"
                        };
                    }
            ],
            'var' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case '@tag()':
                            case '@tag{}':
                                $var_name = $this->varParser($tag_data[1]);
                                return "<?=$var_name?>";
                            default:
                                $var_name = $this->varParser($this->checkVar($tag_data[2]));
                                return "<?=$var_name?>";
                        }
                    }
            ],
            'pp' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case '@tag{}':
                            case '@tag()':
                                $var_name = $tag_data[1];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name .= '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=p({$var_name})?>";
                            default:
                                $var_name = $tag_data[2];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name = '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=p({$var_name})?>";
                        }
                    }
            ],
            'dd' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        if ($attributes) {
                            return $tag_data[0];
                        }
                        switch ($tag_key) {
                            case '@tag{}':
                            case '@tag()':
                                $var_name = $tag_data[1];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name .= '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=dd({$var_name})?>";
                            default:
                                $var_name = $tag_data[2];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name = '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=dd({$var_name})?>";
                        }
                    }
            ],
            'count' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        if ($attributes) {
                            return $tag_data[0];
                        }
                        switch ($tag_key) {
                            case '@tag{}':
                            case '@tag()':
                                $var_name = $tag_data[1];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name .= '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=$var_name?count({$var_name}):0?>";
                            default:
                                $var_name = $tag_data[2];
                                if (!str_starts_with($var_name, '$')) {
                                    $var_name = '$' . $var_name;
                                }
                                $var_name = $this->varParser($var_name);
                                return "<?=$var_name?count({$var_name}):0?>";
                        }
                    }
            ],
            'if' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'attr' => ['condition' => 1],
                'callback' => function ($tag_key, $config, $tag_data, $attributes) {
                    $result = '';
                    switch ($tag_key) {
                        // @if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            foreach ($content_arr as $key => $item) {
                                $content_arr[$key] = explode('=>', $item);
                            }
                            if (1 === count($content_arr)) {
                                $condition = $this->varParser($content_arr[0][0]);
                                $result = "<?php if({$condition}):echo {$content_arr[0][1]};endif;?>";
                            } else {
                                foreach ($content_arr as $key => $data) {
                                    if (0 === $key) {
                                        $condition = $this->varParser($data[0]);
                                        $result = "<?php if($condition):echo " . $data[1] . ';';
                                    } else {
                                        if (count($data) > 1) {
                                            $condition = $this->varParser($data[0]);
                                            $result .= " elseif($condition):echo " . $data[1] . ';';
                                        } else {
                                            $result .= ' else: echo ' . $data[0] . ';';
                                        }
                                    }
                                    if (end($content_arr) === $data) {
                                        $result .= ' endif;?>';
                                    }
                                }
                            }
                            break;
                        case 'tag-self-close-with-attrs':
                            $template_html = htmlentities($tag_data[0]);
                            throw new TemplateException(__("if没有自闭合标签:[{$template_html}]。示例：%1", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                        case 'tag-start':
                            # 排除非if和属性标签的情况
                            if (str_starts_with($tag_data[0], '<if ') || str_starts_with($tag_data[0], '<w:if ')) {
                                if (!isset($attributes['condition'])) {
                                    if (str_starts_with($tag_data[0], '<if ')) {
                                        $template_html = htmlentities($tag_data[0]);
                                        throw new TemplateException(__("if标签缺少condition条件属性:[{$template_html}]，示例：%1", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                                    }
                                }
                                $condition = $this->varParser($attributes['condition']);
                                $result = "<?php if({$condition}):?>";
                                break;
                            }
                            $result = $tag_data[0];
                            break;
                        case 'tag-end':
                            $result = '<?php endif;?>';
                            break;
                        default:
                    }
                    return $result;
                }
            ],
            'elseif' => [
                'attr' => ['condition' => 1],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        $result = '';
                        switch ($tag_key) {
                            // @if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
                            case '@tag{}':
                            case '@tag()':
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("elseif没有@elseif()和@elseif{}用法:[{$template_html}]。示例：%1", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                            case 'tag-self-close-with-attrs':
                                $condition = $this->varParser($this->checkVar($attributes['condition']));
                                $result = "<?php elseif({$condition}):?>";
                                break;
                            default:
                        }
                        return $result;
                    }],
            'else' => [
                'tag-self-close' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        $result = '';
                        switch ($tag_key) {
                            // @if{$a === 1=><li><var>$a</var></li>|$a===2=><li><var>$a</var></li>}
                            case '@tag{}':
                            case '@tag()':
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("elseif没有@elseif()和@elseif{}用法:[{$template_html}]。示例：%1", htmlentities('<if condition="$a>$b"><var>a</var><elseif condition="$b>$a"/><var>b</var><else/><var>a</var><var>b</var></if>')));
                            // <else/>
                            case 'tag-self-close':
                                $result = '<?php else:?>';
                                break;
                            default:
                        }
                        return $result;
                    }],
            'empty' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @empty{$name|<li>空的</li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            $name = $this->varParser($this->checkVar($content_arr[0]));
                            return "<?php if(empty({$name}))echo '" . $template->tmp_replace(trim($content_arr[1] ?? '')) . "'?>";
                        case 'tag-start':
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("empty标签需要设置name属性:[{$template_html}] 例如：%1", htmlentities('<empty name="catalogs"><li>没有数据</li></empty>')));
                            }
                            $name = $this->varParser($this->checkVar($attributes['name']));
                            return '<?php if(empty(' . $name . ')): ?>';
                        case 'tag-end':
                            return '<?php endif; ?>';
                        default:
                            return '';
                    }
                }
            ],
            'notempty' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @empty{$name|<li>空的</li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            $name = $this->varParser($this->checkVar($content_arr[0]));
                            return "<?php if(!empty({$name}))echo '" . $template->tmp_replace(trim($content_arr[1] ?? '')) . "'?>";
                        case 'tag-start':
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("empty标签需要设置name属性:[$template_html]例如：%1", htmlentities('<empty name="catalogs"><li>没有数据</li></empty>')));
                            }
                            $name = $this->varParser($this->checkVar($attributes['name']));
                            return '<?php if(!empty(' . $name . ') ): ?>';
                        case 'tag-end':
                            return '<?php endif; ?>';
                        default:
                            return '';
                    }
                }
            ],
            'has' => [
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @empty{$name|<li>空的</li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            foreach ($content_arr as $key => $item) {
                                $content_arr[$key] = explode('=>', $item);
                            }
                            if (1 === count($content_arr)) {
                                $name = $this->varParser($content_arr[0][0]);
                                $result = "<?php if(!empty({$name})):echo {$content_arr[0][1]};endif;?>";
                            } else {
                                foreach ($content_arr as $key => $data) {
                                    if (0 === $key) {
                                        $name = $this->varParser($data[0]);
                                        $result = "<?php if(!empty($name)):echo " . $data[1] . ';';
                                    } else {
                                        if (count($data) > 1) {
                                            $name = $this->varParser($data[0]);
                                            $result .= " elseif(!empty($name)):echo " . $data[1] . ';';
                                        } else {
                                            $result .= ' else: echo ' . $data[0] . ';';
                                        }
                                    }
                                    if (end($content_arr) === $data) {
                                        $result .= ' endif;?>';
                                    }
                                }
                            }
                            return $result;
                        //                            $content_arr = explode('|', $tag_data[1]);
                        //                            $name        = $this->varParser($this->checkVar($content_arr[0]));
                        /*                            return "<?php if(!empty({$name}))echo '" . $template->tmp_replace(trim($content_arr[1] ?? '')) . "'?>";*/
                        case 'tag-start':
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("has标签需要设置name属性:[$template_html]例如：%1", htmlentities('<has name="catalogs"><li>有数据</li><else/>没数据</has>')));
                            }
                            $name = $this->varParser($this->checkVar($attributes['name']));
                            return '<?php if(!empty(' . $name . ') ): ?>';
                        case 'tag-end':
                            return '<?php endif; ?>';
                        default:
                            return '';
                    }
                }
            ],
            'block' => [
                'doc' => '@block{Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml}或者@block(Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml)或者' . htmlentities('<block class="Weline\Admin\Block\Demo" template="Weline_Admin::block/demo.phtml"/>') . '或者' . htmlentities('<block>Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml</block>'),
                'tag' => 1,
                'attr' => ['class' => 0, 'template' => 0, 'cache' => 0],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            //<block>Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml|cache=300</block>
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $data = array_merge($data, $attributes);
                                $result = '<?php echo framework_view_process_block(' . w_var_export($data, true) . ');?>';
                                break;
                            // @block{Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml}
                            case '@tag{}':
                            case '@tag()':
                                $data = explode('|', $tag_data[1]);
                                if (!isset($data[0]) || !$data[0]) {
                                    $template_html = htmlentities($tag_data[0]);
                                    throw new TemplateException(
                                        __(
                                            "@block标签语法使用错误：未指定block类:[{$template_html}]。示例：%1或者%2",
                                            [htmlentities('@block(Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml)'), htmlentities('@block{Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml}')]
                                        )
                                    );
                                }
                                $result = '<?php echo framework_view_process_block(' . w_var_export($data, true) . ');?>';
                                break;
                            // <block class='Weline\Demo\Block\Demo' template='Weline_Demo::templates/demo.phtml'/>
                            case 'tag-self-close-with-attrs':
                                if (!isset($attributes['class']) || !$attributes['class']) {
                                    $template_html = htmlentities($tag_data[0]);
                                    throw new TemplateException(__("block标签语法使用错误:[{$template_html}]：未指定block类。示例：%1", htmlentities("<block class='Weline\Demo\Block\Demo' template='Weline_Demo::templates/demo.phtml' vars='item|pageSize|page'/>")));
                                }
                                // 变量导入
                                $vars_string = '[';
                                if (isset($attributes['vars'])) {
                                    $vars = explode(',', $attributes['vars']);
                                    foreach ($vars as $key => $var) {
                                        $var_name = trim($var);
                                        $var = '$' . $var_name;
                                        $vars_string .= "'$var_name'=>&$var,";
                                    }
                                }
                                $vars_string .= ']';
                                $result = '<?php echo framework_view_process_block(' . w_var_export($attributes, true) . ',$vars=' . $vars_string . ');?>';
                                break;
                            default:
                        }
                        return $result;
                    }
            ],
            'foreach' => [
                'attr' => ['name' => 1, 'key' => 0, 'item' => 0],
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' => function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                    switch ($tag_key) {
                        // @foreach{$name as $key=>$v|<li><var>$k</var>:<var>$v</var></li>}
                        case '@tag{}':
                        case '@tag()':
                            $content_arr = explode('|', $tag_data[1]);
                            $foreach_str = $this->varParser($this->checkVar($content_arr[0]));
                            return "<?php
                        foreach({$foreach_str}){
                        ?>
                            {$template->tmp_replace($content_arr[1] ?? '')}
                            <?php
                        }
                        ?>";
                        case 'tag-self-close-with-attrs':
                            $template_html = htmlentities($tag_data[0]);
                            throw new TemplateException(__("foreach没有自闭合标签:[{$template_html}]。示例：%1", htmlentities('<foreach name="catalogs" key="key" item="v"><li><var>name</var></li></foreach>')));
                        case 'tag-start':
                            if (!isset($attributes['item'])) {
                                $attributes['item'] = 'v';
                            }
                            if (!isset($attributes['name'])) {
                                $template_html = htmlentities($tag_data[0]);
                                throw new TemplateException(__("foreach标签需要指定要循环的变量name属性:[{$template_html}]。例如：需要循环catalogs变量则%1", htmlentities('<foreach name="catalogs" key="key" item="v"><li><var>name</var></li></foreach>')));
                            }
                            foreach ($attributes as $key => $attribute) {
                                $attributes[$key] = $this->checkVar($attribute);
                            }
                            $vars = $this->varParser($this->checkVar($attributes['name']));
                            $k_i = isset($attributes['key']) ? $attributes['key'] . ' => ' . $attributes['item'] : $attributes['item'];
                            return "<?php foreach($vars as $k_i):?>";
                        case 'tag-end':
                            return '<?php endforeach;?>';
                        default:
                            return '';
                    }
                }
            ],
            'static' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        return match ($tag_key) {
                            'tag' => $template->fetchTagSource('statics', trim($tag_data[2])),
                            default => $template->fetchTagSource('statics', trim($tag_data[1]))
                        };
                    }
            ],
            'template' => [
                'tag' => 1,
                'attr' => ['enable' => 0],
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $enable = $attributes['enable'] ?? 1;
                        if (!$enable or ($enable ==='false')) {
                            $template_string  = $tag_data[0]??'';
                            $target_template  = $tag_data[2]??'';
                            return "<!-- 模块被禁用：{$target_template} 原始模板：{$template_string}-->";
                        }
                        return match ($tag_key) {
                            'tag' => file_get_contents($template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_TEMPLATE, trim($tag_data[2]))),
                            default => file_get_contents($template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_TEMPLATE, trim($tag_data[1])))
                        };
                    }
            ],
            'js' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        return match ($tag_key) {
                            'tag' => "<script {$tag_data[1]} src='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[2]))}'></script>",
                            default => "<script src='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[1]))}'></script>"
                        };
                    }
            ],
            'css' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        return match ($tag_key) {
                            'tag' => "<link {$tag_data[1]} href='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[2]))}' rel=\"stylesheet\" type=\"text/css\"/>",
                            default => "<link href='{$template->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, trim($tag_data[1]))}' rel=\"stylesheet\" type=\"text/css\"/>"
                        };
                    }
            ],
            'lang' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return match ($tag_key) {
                            'tag' => __($tag_data[2]),
                            default => __($tag_data[1])
                        };
                    }
            ],
            'url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $result = '';
                        switch ($tag_key) {
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $var = $data[0] ?? '';
                                $var = trim($var, "'\"");
                                $var = str_replace(' ', '', $var);
                                if (isset($data[1]) && $arr_str = $data[1]) {
                                    $result .= "<?=\$this->getUrl('{$var}',{$arr_str})?>";
                                } else {
                                    $result .= "<?=\$this->getUrl('{$var}')?>";
                                }
                                break;
                            case  'tag-start':
                                $result .= "<?=\$this->getUrl(";
                                break;
                            case 'tag-end':
                                $result .= ')?>';
                                break;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                $result .= "<?=\$this->getUrl({$data})?>";
                        };
                        return $result;
                    }
            ],
            'api' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        $result = '';
                        switch ($tag_key) {
                            case 'tag':
                                $data = explode('|', $tag_data[2]);
                                $var = $data[0] ?? '';
                                $var = trim($var, "'\"");
                                $var = str_replace(' ', '', $var);
                                if (isset($data[1]) && $arr_str = $data[1]) {
                                    $result .= "<?=\$this->getApi('{$var}',{$arr_str})?>";
                                } else {
                                    $result .= "<?=\$this->getApi('{$var}')?>";
                                }
                                break;
                            case  'tag-start':
                                $result .= "<?=\$this->getApi(";
                                break;
                            case 'tag-end':
                                $result .= ')?>';
                                break;
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                $result .= "<?=\$this->getApi({$data})?>";
                        };
                        return $result;
                    }
            ],
            'admin-url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        switch ($tag_key) {
                            case 'tag':
                                $data = $this->varParser(str_replace(' ', '', $tag_data[2]));
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendUrl({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendUrl({$this->varParser($data)})?>";
                                }
                            // no break
                            case 'tag-start':
                                return "<?=\$this->getBackendUrl(";
                            case 'tag-end':
                                return ')?>';
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendUrl({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendUrl({$this->varParser($data)})?>";
                                }
                        }
                    }
            ],
            'backend-url' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        switch ($tag_key) {
                            case 'tag':
                                $data = $this->varParser(str_replace(' ', '', $tag_data[2]));
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendUrl({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendUrl({$this->varParser($data)})?>";
                                }
                            // no break
                            case 'tag-start':
                                return "<?=\$this->getBackendUrl(";
                            case 'tag-end':
                                return ')?>';
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendUrl({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendUrl({$this->varParser($data)})?>";
                                }
                        }
                    }
            ],
            'backend-api' => [
                'tag' => 1,
                'tag-start' => 1,
                'tag-end' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) use ($template) {
                        switch ($tag_key) {
                            case 'tag':
                                $data = $this->varParser(str_replace(' ', '', $tag_data[2]));
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendApi({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendApi({$this->varParser($data)})?>";
                                }
                            // no break
                            case 'tag-start':
                                return "<?=\$this->getBackendApi(";
                            case 'tag-end':
                                return ')?>';
                            default:
                                $data = str_replace(' ', '', $tag_data[1]);
                                if (str_starts_with($data, '"') || str_starts_with($data, "'")) {
                                    return "<?=\$this->getBackendApi({$data})?>";
                                } else {
                                    return "<?=\$this->getBackendApi({$this->varParser($data)})?>";
                                }
                        }
                    }
            ],
            'hook' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return match ($tag_key) {
                            'tag' => "<?=\$this->getHook('" . trim($tag_data[2]) . "')?>",
                            default => "<?=\$this->getHook('" . trim($tag_data[1]) . "')?>"
                        };
                    }
            ],
            'string' => [
                'tag' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case 'tag':
                                $string = $tag_data[2];
                                $str_arr = explode('|', $string);
                                $str_var = $this->varParser($this->checkVar(array_shift($str_arr)));
                                $str_len = intval(array_shift($str_arr));

                                return "<?php if(!empty({$str_var})&&$str_len>0 && strlen({$str_var})>{$str_len}){
                                    echo mb_substr({$str_var},0,{$str_len},'UTF8').'...';
                                }else{
                                echo {$str_var};
                                }?>";
                            default:
                                $string = $tag_data[1];
                                $str_arr = explode('|', $string);
                                $str_var = $this->checkVar(array_shift($str_arr));
                                $str_len = intval(array_shift($str_arr));

                                return "<?php if($str_len>0 && strlen({$str_var})>{$str_len}){
                                    echo mb_substr({$str_var},0,{$str_len},'UTF8').'...';
                                }else{
                                echo {$str_var};
                                }?>";
                        }
                    }
            ],
            'csrf' => [
                'tag' => 1,
                'doc' => '@csrf{demo}或者@csrf(demo)或者' . htmlentities('<csrf name="demo"/>') . '或者' . htmlentities('<csrf>demo</csrf>') . ' 协助在form表单中设置csrf令牌，防止跨站请求伪造（CSRF）攻击',
                'tag' => 1,
                'attr' => [],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        switch ($tag_key) {
                            case 'tag':
                                $name = $tag_data[2] ?? '';
                            // no break
                            case 'tag-self-close-with-attrs':
                                $name = $attributes['name'] ?? '';
                            // no break
                            default:
                                if (empty($name)) {
                                    $name = $tag_data[1] ?? 'csrf';
                                }
                                /**@var Csrf $csrf */
                                $csrf = ObjectManager::getInstance(Csrf::class);
                                return $csrf->render($name);
                        }
                    }
            ],
            'message' => [
                'tag' => 1,
                'doc' => '@message{}或者@message()或者' . htmlentities('<message/>') . '或者' . htmlentities('<message></message>') . ' 显示消息提示：有来自后端渲染型消息会提示到此处！',
                'tag' => 1,
                'attr' => [],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return ObjectManager::getInstance(\Weline\Framework\Manager\MessageManager::class)->__toString();
                    }
            ],
            'msg' => [
                'tag' => 1,
                'doc' => '@msg{}或者@msg()或者' . htmlentities('<msg/>') . '或者' . htmlentities('<msg></msg>') . ' 显示消息提示：有来自后端渲染型消息会提示到此处！',
                'tag' => 1,
                'attr' => [],
                'tag-self-close-with-attrs' => 1,
                'callback' =>
                    function ($tag_key, $config, $tag_data, $attributes) {
                        return ObjectManager::getInstance(\Weline\Framework\Manager\MessageManager::class)->__toString();
                    }
            ],
        ];
        # 兼容自定义tag
        /**@var EventsManager $event */
        $event = ObjectManager::getInstance(EventsManager::class);
        $data = (new DataObject(['template' => $template, 'tags' => $tags]));
        $event->dispatch('Framework_Template::after_tags_config', ['data' => $data, 'Taglib' => $this]);
        $tags = $data->getData('tags');
        # 构造w:tag
        foreach ($tags as $tag => $tag_data) {
            $tags["w:$tag"] = $tag_data;
        }
        return $tags;
    }

    public function tagReplace(Template &$template, string &$content, string &$fileName = ''): array|string
    {
        # 替换{{key}}标签
        preg_match_all('/\{\{([\s\S]*?)\}\}/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $key => $value) {
            $content = str_replace($value[0], "<?={$this->varParser(trim($value[1]))};?>", $content);
        }
        # 非开发环境清除所有注释
        if (PROD) {
            preg_match_all('/\<!--([\s\S]*?)-->/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $key => $value) {
                $content = str_replace($value[0], '', $content);
            }
        }

        # 系统自带的标签
        $tags = $this->getTags($template, $fileName, $content);
        foreach ($tags as $tag => $tag_configs) {
            $tag_patterns = [
                'tag-self-close-with-attrs' => '/<' . $tag . '([\s\S]*?)\/>/m',
                'tag' => '/<' . $tag . '([\s\S]*?)>([\s\S]*?)<\/' . $tag . '>/m',
                'tag-start' => '/<' . $tag . '([\s\S]*?)>/m',
                'tag-end' => '/<\/' . $tag . '>/m',
                'tag-self-close' => '/<' . $tag . '\s*\/>/m',
                '@tag()' => '/\@' . $tag . '\(([\s\S]*?)\)/m',
                '@tag{}' => '/\@' . $tag . '\{([\s\S]*?)\}/m',
            ];
            # 检测标签所需要的元素，不需要的就跳过
            foreach ($tag_patterns as $tag_key => $tag_pattern) {
                if (str_starts_with($tag_key, 'tag') && !isset($tag_configs[$tag_key])) {
                    unset($tag_patterns[$tag_key]);
                }
            }
            # 匹配标签所需处理的tag
            $tag_config_patterns = [];
            foreach ($tag_configs as $config_name => $tag_config) {
                if (str_starts_with($config_name, 'tag') && $tag_config) {
                    $tag_config_patterns[$config_name] = $tag_patterns[$config_name];
                }
            }
            # 默认匹配@tag()和@tag{}
            $tag_config_patterns['@tag()'] = $tag_patterns['@tag()'];
            $tag_config_patterns['@tag{}'] = $tag_patterns['@tag{}'];

            # 标签验证测试
            //            if('var'===$tag){
            //                foreach ($tag_config_patterns as &$tag_config_pattern) {
            //                    $tag_config_pattern = htmlentities($tag_config_pattern);
            //                }
            //                p($tag_config_patterns);
            //            }
            # 匹配处理
            $format_function = $tag_configs['callback'];
            foreach ($tag_config_patterns as $tag_key => $tag_pattern) {
                preg_match_all($tag_pattern, $content, $customTags, PREG_SET_ORDER);
                foreach ($customTags as $customTag) {
                    $originalTag = $customTag[0];
                    if (isset($customTag[1])) {
                        if ($tag_key == 'tag' or $tag_key == 'tag-self-close-with-attrs' or $tag_key == 'tag-start') {
                            // 替换操作符
                            foreach (self::operators_symbols_to_lang as $operator => $symbol) {
                                if (str_contains($customTag[1], $operator)) {
                                    $customTag[1] = str_replace($operator, ' ' . $symbol . ' ', $customTag[1]);
                                }
                            }
                        }
                        $customTag[1] = str_replace(PHP_EOL, '', $customTag[1]);
                    }

                    $rawAttributes = $customTag[1] ?? '';
                    # 如果有属性接下来的字母就不会和标签紧贴着，而如果没有属性那么应该是>括号和标签紧贴着，如果都不是说明并非tag标签
                    if ($rawAttributes && ('tag' === $tag_key || 'tar-start' === $tag_key || 'tag-self-close-with-attrs' === $tag_key || 'tag-self-close' === $tag_key) && !str_starts_with($rawAttributes, ' ')) {
                        continue;
                    }

                    if (isset($customTag[2])) {
                        $customTag[2] = str_replace(PHP_EOL, '', $customTag[2]);
                        $customTag[2] = str_replace(array("\r\n", "\r", "\n", "\t"), '', $customTag[2]);
                    }
                    # 标签支持匹配->
                    $customTag[1] = $rawAttributes;
                    $formatedAttributes = array();
                    # 兼容：属性值单双引号
                    preg_match_all("/(\S*?)='([\s\S]*?)'/", $rawAttributes, $attributes, PREG_SET_ORDER);
                    foreach ($attributes as $attribute) {
                        if (isset($attribute[2])) {
                            $attr = trim($attribute[1]);
                            $formatedAttributes[$attr] = trim($attribute[2]);
                        }
                    }
                    preg_match_all('/(\S*?)="([\s\S]*?)"/', $rawAttributes, $attributes, PREG_SET_ORDER);
                    foreach ($attributes as $attribute) {
                        if (isset($attribute[2])) {
                            $attr = trim($attribute[1]);
                            $formatedAttributes[$attr] = trim($attribute[2]);
                        }
                    }

                    //                    if($tag_key==='tag-self-close-with-attrs'&&$tag==='block') {
                    //                        p( $rawAttributes,1);
                    //                        p( $attributes);
                    //                        if(str_contains($rawAttributes, "item='sub_menu'")){
                    //                            p( $formatedAttributes,1);
                    //                            p( $attributes);
                    //                        };
                    //                    }
                    # 验证标签属性
                    $attrs = $tag_configs['attr'] ?? [];
                    if ($attrs && ('tar-start' === $tag_key || 'tag-self-close-with-attrs' === $tag_key || 'tag' === $tag_key)) {
                        $attributes_keys = array_keys($formatedAttributes);
                        foreach ($attrs as $attr => $required) {
                            if ($required && !in_array($attr, $attributes_keys)) {
                                $provide_attr = implode(',', $attributes_keys);
                                $template_html = htmlentities($attr);
                                throw new TemplateException(__("代码：[{$template_html}] %1:标签必须设置属性%2, 提供的属性：3% 文件：%4", [$tag, $attr, $provide_attr, $fileName]));
                            }
                        }
                    }

                    $result = $format_function($tag_key, $tag_configs, $customTag, $formatedAttributes);
                    //                    if (DEV) {
                    //                        $origin_tag = htmlspecialchars($customTag[0]) ?? '';
                    //                        $result    = "<!-- {$origin_tag} START -->" . $result . "<!-- {$origin_tag} -->";
                    //                    }
                    $content = str_replace($originalTag, $result, $content);
                }
            }
        }
        return $content;
    }
}
