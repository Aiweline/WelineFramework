<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/21 22:05:23
 */
namespace Weline\I18n\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Transaction\Exception\UnsupportedAsyncTransactionConnectionException;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\I18n\Service\I18nResourceChangePublisher;
#[Table(comment: '地区/语言包')]
#[Index(name: 'idx_code_target', columns: ['code', 'target_code'], type: 'UNIQUE', comment: '区码+目标区码唯一索引')]
#[Index(name: 'idx_name', columns: ['name'], comment: '名字索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '状态索引')]
#[Index(name: 'idx_is_install', columns: ['is_install'], comment: '安装索引')]
class Locals extends Model
{
    public const schema_table = 'i18n_locals';
    public const schema_primary_keys = ['code', 'target_code'];
    #[Col('varchar', 10, nullable: false, comment: '地方代码')]
    public const schema_fields_ID = 'code';
    #[Col('varchar', 10, nullable: false, comment: '地方代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 10, nullable: false, comment: '展示的地方代码')]
    public const schema_fields_TARGET_CODE = 'target_code';
    #[Col('varchar', 128, nullable: false, comment: '展示的地方代码对应地方代码名称')]
    public const schema_fields_NAME = 'name';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '启用状态')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否安装')]
    public const schema_fields_IS_INSTALL = 'is_install';
    #[Col('mediumtext', comment: 'SVG国旗')]
    public const schema_fields_FLAG = 'flag';
    public array $_unit_primary_keys = ['code', 'target_code'];


    /** 外层事务覆盖父类保存与 changed，避免 save_after 在外层提交前清理缓存。 */
    public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        $connection = $this->getConnection();
        $publisher = ObjectManager::getInstance(I18nResourceChangePublisher::class);
        if (TransactionContext::logicalConnectionKey($connection->getConnector())
            !== TransactionContext::logicalConnectionKey($publisher->connection()->getConnector())) {
            throw new UnsupportedAsyncTransactionConnectionException(__('语言目录写入与资源变更必须使用同一逻辑数据库连接'));
        }
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        return $transactions->run($connection, function () use ($data, $sequence, $publisher, $transactions, $connection): bool|int {
            $result = parent::save($data, $sequence);
            // 父类保存失败会抛异常；部分驱动成功 UPDATE 的返回值仍可能为 false。
            $publisher->publishAction('localization-save', [
                'code' => (string)$this->getData(self::schema_fields_CODE),
                'locale_code' => (string)$this->getData(self::schema_fields_TARGET_CODE),
            ]);
            $transactions->afterCommit($connection, 'i18n.locals.website-parser', static function (): void {
                \Weline\Framework\Http\Url::bumpWebsiteParserSitesVersion();
            });
            return $result;
        });
    }
}
