<?php
declare(strict_types=1);
namespace Weline\Frontend\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 前端用户Token模型 - 用于"记住我"功能
 */
#[Table(comment: '前端用户Token表')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户ID索引')]
#[Index(name: 'idx_token', columns: ['token'], comment: 'Token索引')]
#[Index(name: 'idx_expire', columns: ['token_expire_time'], comment: '过期时间索引')]
class FrontendUserToken extends Model
{
    public const schema_table = 'frontend_user_token';
    public const schema_primary_key = 'token_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Token ID')]
    public const schema_fields_ID = 'token_id';
    #[Col('int', nullable: false, comment: '用户ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col('varchar', 64, nullable: false, unique: true, comment: 'Token字符串')]
    public const schema_fields_token = 'token';
    #[Col('varchar', 32, nullable: false, comment: 'Token类型')]
    public const schema_fields_type = 'type';
    #[Col('int', nullable: false, comment: '过期时间戳')]
    public const schema_fields_token_expire_time = 'token_expire_time';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col('timestamp', nullable: true, comment: '最后使用时间')]
    public const schema_fields_last_used_at = 'last_used_at';
public function getUserId(): int
    {
        return (int)$this->getData(self::schema_fields_user_id);
    }
    public function setUserId(int $userId): static
    {
        return $this->setData(self::schema_fields_user_id, $userId);
    }
    public function getToken(): string
    {
        return (string)$this->getData(self::schema_fields_token);
    }
    public function setToken(string $token): static
    {
        return $this->setData(self::schema_fields_token, $token);
    }
    public function getType(): string
    {
        return (string)$this->getData(self::schema_fields_type);
    }
    public function setType(string $type): static
    {
        return $this->setData(self::schema_fields_type, $type);
    }
    public function getTokenExpireTime(): int
    {
        return (int)$this->getData(self::schema_fields_token_expire_time);
    }
    public function setTokenExpireTime(int $time): static
    {
        return $this->setData(self::schema_fields_token_expire_time, $time);
    }
    public function updateLastUsedAt(): static
    {
        return $this->setData(self::schema_fields_last_used_at, date('Y-m-d H:i:s'));
    }
    public function isExpired(): bool
    {
        return time() >= $this->getTokenExpireTime();
    }
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
    public function cleanExpiredTokens(): int
    {
        return $this->reset()
            ->where(self::schema_fields_token_expire_time, time(), '<')
            ->delete();
    }
}
