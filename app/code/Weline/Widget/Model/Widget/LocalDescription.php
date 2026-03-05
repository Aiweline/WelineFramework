<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫科技 编写，所有解释权归 weline 所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Widget\Model\Widget;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '部件多语言翻译表')]
#[Index(name: 'pk_widget_locale', columns: ['widget_identify', 'locale_code'], type: 'UNIQUE')]
class LocalDescription extends AbstractModel
{
    public const schema_table = 'w_widget_local_description';
    public const schema_primary_keys = ['widget_identify', 'locale_code'];

    #[Col('varchar', 255, nullable: false, comment: '部件标识')]
    public const schema_fields_WIDGET_IDENTIFY = 'widget_identify';
    #[Col('varchar', 20, nullable: false, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col('varchar', 255, comment: '部件名称')]
    public const schema_fields_NAME = 'name';
    #[Col('text', comment: '部件描述')]
    public const schema_fields_DESCRIPTION = 'description';
    public array $_index_sort_keys = [self::schema_fields_WIDGET_IDENTIFY, self::schema_fields_LOCALE_CODE];
    public function __init()
    {
        parent::__init();
    }

    /**
     * 获取部件标识
     *
     * @param string $module 模块名
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @return string
     */
    public static function getWidgetIdentify(string $module, string $type, string $name): string
    {
        return $module . '_' . $type . '_' . $name;
    }

    /**
     * 获取部件的翻译名称
     *
     * @param string $module 模块名
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @param string|null $locale 语言代码，默认使用当前语言
     * @param string|null $default 默认值
     * @return string
     */
    public static function getTranslatedName(
        string $module,
        string $type,
        string $name,
        ?string $locale = null,
        ?string $default = null
    ): string {
        $identify = self::getWidgetIdentify($module, $type, $name);

        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }

        /** @var self $model */
        $model = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $model->reset()
            ->where(self::schema_fields_WIDGET_IDENTIFY, $identify)
            ->where(self::schema_fields_LOCALE_CODE, $locale)
            ->find()
            ->fetch();

        // 检查是否有翻译数据（通过检查 widget_identify 字段）
        if ($model->getData(self::schema_fields_WIDGET_IDENTIFY)) {
            $translatedName = $model->getData(self::schema_fields_NAME);
            if (!empty($translatedName)) {
                return $translatedName;
            }
        }

        return $default ?? '';
    }

    /**
     * 获取部件的翻译描述
     *
     * @param string $module 模块名
     * @param string $type 部件类型
     * @param string $name 部件名称
     * @param string|null $locale 语言代码，默认使用当前语言
     * @param string|null $default 默认值
     * @return string
     */
    public static function getTranslatedDescription(
        string $module,
        string $type,
        string $name,
        ?string $locale = null,
        ?string $default = null
    ): string {
        $identify = self::getWidgetIdentify($module, $type, $name);

        if ($locale === null) {
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';
        }

        /** @var self $model */
        $model = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $model->reset()
            ->where(self::schema_fields_WIDGET_IDENTIFY, $identify)
            ->where(self::schema_fields_LOCALE_CODE, $locale)
            ->find()
            ->fetch();

        // 检查是否有翻译数据（通过检查 widget_identify 字段）
        if ($model->getData(self::schema_fields_WIDGET_IDENTIFY)) {
            $translatedDesc = $model->getData(self::schema_fields_DESCRIPTION);
            if (!empty($translatedDesc)) {
                return $translatedDesc;
            }
        }

        return $default ?? '';
    }
}
