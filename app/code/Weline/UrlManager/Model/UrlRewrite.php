<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\UrlManager\Model;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'URL重写表')]
#[Index(name: 'idx_website_id', columns: ['website_id'])]
#[Index(name: 'UNQ_WEBSITE_URL_IDENTIFY', columns: ['website_id', 'url_identify'], type: 'UNIQUE')]
#[Index(name: 'idx_website_rewrite_latest', columns: ['website_id', 'rewrite', 'rewrite_id'])]
#[Index(name: 'idx_website_path_latest', columns: ['website_id', 'path', 'rewrite_id'])]
class UrlRewrite extends Model
{
    public const schema_table = 'url_rewrite';
    public const schema_primary_key = 'rewrite_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '重写ID')]
    public const schema_fields_ID = 'rewrite_id';
    #[Col('varchar', 255, comment: 'URL ID')]
    public const schema_fields_URL_ID = 'url_id';
    #[Col('varchar', 255, comment: 'URL 指纹')]
    public const schema_fields_URL_IDENTIFY = 'url_identify';
    #[Col('text', nullable: false, comment: 'URL路径')]
    public const schema_fields_PATH = 'path';
    #[Col('varchar', 255, nullable: false, comment: 'URL重写路径')]
    public const schema_fields_REWRITE = 'rewrite';
    #[Col('int', 11, nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
/**
     * 获取当前请求的网站ID
     * 
     * @return int 网站ID，默认为0
     */
    public static function getCurrentWebsiteId(): int
    {
        $websiteId = WelineEnv::get('website_id', null);
        if ($websiteId === null || $websiteId === '') {
            $websiteId = $_SERVER['WELINE_WEBSITE_ID'] ?? '';
        }
        if ($websiteId === '' || $websiteId === null) {
            return 0;
        }
        return (int)$websiteId;
    }
}
