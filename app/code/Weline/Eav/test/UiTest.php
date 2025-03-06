<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/5/9 13:56:57
 */

namespace Weline\Eav\test;

use Weline\Eav\Model\EavAttribute\Type;
use Weline\FileManager\Ui\EavModel\Select\File;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Template;

class UiTest extends TestCore
{
    function testUiSelect()
    {
//        $defaults = [
//            'target' => '#test-file',
//            'var' => '',
//            'path' => 'media/uploader/',
//            'value' => 'demo',
//            'title' => '从文件管理器选择',
//            'multi' => '0',
//            'ext' => 'xls,xlsx',
//            'w' => '50',
//            'h' => '50',
//            'size' => '102400',
//        ];
//        $attr = $defaults;
//        $attr_string = '';
//        foreach ($attr as $key => $val) {
//            $attr_string .= ' ' . $key . '="' . $val . '"';
//        }
//        $id = ltrim($attr['target'],'#');
//        $html = "
//            <label class='demo'>测试</label>
//            <input id='$id' type='hidden' name='demo' $attr_string>
//            <file-manager
//                    {$attr_string}
//            />";
//        $res =  Template::getInstance()->tmp_replace($html);
//        # 运行php
        # 安装选择文件属性
        /** @var Type $type */
        $type = ObjectManager::make(Type::class);
        $type->setFieldType(TableInterface::column_type_VARCHAR)
            ->setCode('select_file_test')
            ->setFrontendAttrs('type="file" data-parsley-minlength="3" required')
            ->setFieldLength(255)
            ->setIsSwatch(false)
            ->setElement('input')
            ->setModelClass(File::class)
            ->setModelClassData('')
            ->setRequired(true)
            ->setDefaultValue('')
            ->setName('选择文件')
            ->save();
        $type->load('code', 'select_file_test');
        $ok1 = $type->getCode() == 'select_file_test';
        $type->delete()->fetch();
        $type->load('code', 'select_file_test');
        $ok2 = $type->getCode() == '';
        self::assertTrue($ok1 and $ok2, 'ui模型测试！');
    }
}