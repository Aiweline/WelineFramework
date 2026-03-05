<?php
declare(strict_types=1);
namespace Weline\Taglib\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '用户作用域数据表')]
class UserScope extends Model
{
    public const schema_table = 'user_scope';
    public const schema_primary_key = 'id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', 11, nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col('varchar', 60, nullable: false, comment: '数据作用域')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('text', comment: '作用域数据JSON')]
    public const schema_fields_DATA = 'data';
public function getScopeData( int $userId, string $scope,string $key = ''): array
    {
        $data = $this->where('scope', $scope)->where('user_id', $userId)->find()->fetchArray();
        if ($key) {
            $data = json_decode($data['data'], true);
            return $data[$key] ?? [];
        }
        return $data;
    }
    public function setScopeData( int $userId, string $scope, array $data): static
    {
        $this->setData('user_id', $userId, true)
            ->setData('data', json_encode($data))
            ->setData('scope', $scope, true)
            ->save();
        return $this;
    }
}
