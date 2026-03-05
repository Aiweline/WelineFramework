<?php
declare(strict_types=1);
namespace Weline\Backend\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

#[Table(comment: '后台用户数据表')]
class BackendUserData extends Model
{
    public const schema_table = 'weline_backend_user_data';
    public const schema_primary_key = 'backend_user_data_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '后台用户数据ID')]
    public const schema_fields_ID = 'backend_user_data_id';
    #[Col(type: 'int', nullable: false, comment: '后台用户ID')]
    public const schema_fields_BACKEND_USER_ID = 'backend_user_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '作用域')]
    public const schema_fields_scope = 'scope';
    #[Col(type: 'text', nullable: false, comment: 'json数据')]
    public const schema_fields_JSON = 'json';

    public function getScope(string $scope): array
    {
        /** @var AuthenticatedSessionInterface $session */
        $session = SessionFactory::getInstance()->createBackendSession();
        if (!$session->getUserId()) {
            return [];
        }
        $this->where(self::schema_fields_BACKEND_USER_ID, $session->getUserId())
            ->where(self::schema_fields_scope, $scope)
            ->find()
            ->fetch();
        $json = $this->getData(self::schema_fields_JSON);
        if (!$json) {
            return [];
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function deleteScope(string $scope): self
    {
        /** @var AuthenticatedSessionInterface $session */
        $session = SessionFactory::getInstance()->createBackendSession();
        if ($session->getUserId()) {
            $this->where(self::schema_fields_BACKEND_USER_ID, $session->getUserId())
                ->where(self::schema_fields_scope, $scope)
                ->delete();
        }
        return $this;
    }
}

