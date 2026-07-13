<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Frontend\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '用户表')]
class FrontendUser extends Model
{
    public const schema_table = 'frontend_user';
    public const schema_primary_key = 'user_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '用户ID')]
    public const schema_fields_ID = 'user_id';
    #[Col('varchar', 60, comment: '用户名')]
    public const schema_fields_username = 'username';
    #[Col('varchar', 255, comment: '密码')]
    public const schema_fields_password = 'password';
    #[Col('varchar', 255, comment: '头像')]
    public const schema_fields_avatar = 'avatar';
    #[Col('varchar', 16, comment: '登录IP')]
    public const schema_fields_login_ip = 'login_ip';
    #[Col('varchar', 16, comment: '尝试登录IP')]
    public const schema_fields_attempt_ip = 'attempt_ip';
    #[Col('int', default: 0, comment: '尝试登录次数')]
    public const schema_fields_attempt_times = 'attempt_times';
    #[Col('varchar', 32, comment: '管理员Session ID')]
    public const schema_fields_sess_id = 'sess_id';
    #[Col('int', 1, default: 0, comment: '是否沙盒账户')]
    public const schema_fields_is_sandbox = 'is_sandbox';
    #[Col('decimal', '12,4', default: 0, comment: '账户余额')]
    public const schema_fields_BALANCE = 'balance';
    #[Col('decimal', '12,4', default: 0, comment: '累计充值')]
    public const schema_fields_TOTAL_RECHARGE = 'total_recharge';
    #[Col('decimal', '12,4', default: 0, comment: '累计消费')]
    public const schema_fields_TOTAL_CONSUMPTION = 'total_consumption';
    #[Col('varchar', 10, nullable: false, default: 'CNY', comment: '账户币种')]
    public const schema_fields_CURRENCY = 'currency';
    public array $_unit_primary_keys = ['user_id'];
public function getAttemptTimes()
    {
        return intval($this->getData(self::schema_fields_attempt_times));
    }
    public function addAttemptTimes(): static
    {
        $this->setData(self::schema_fields_attempt_times, intval($this->getData(self::schema_fields_attempt_times)) + 1);
        return $this;
    }
    public function getAttemptIp()
    {
        return $this->getData(self::schema_fields_attempt_ip);
    }
    public function setAttemptIp($ip)
    {
        return $this->setData(self::schema_fields_attempt_ip, $ip);
    }
    public function resetAttemptTimes(): static
    {
        $this->setData(self::schema_fields_attempt_times, 0);
        $this->save();
        return $this;
    }
    public function getUsername()
    {
        return $this->getData('username');
    }
    public function setUsername(string $username)
    {
        return $this->setData('username', $username);
    }
    public function getAvatar()
    {
        return $this->getData('avatar');
    }
    public function setAvatar(string $avatar)
    {
        return $this->setData('avatar', $avatar);
    }
    public function getPassword()
    {
        return $this->getData('password');
    }
    public function setPassword(string $password)
    {
        return $this->setData('password', password_hash($password, PASSWORD_DEFAULT));
    }
    public function getSessionId()
    {
        return $this->getData(self::schema_fields_sess_id);
    }
    public function setSessionId(string $sess_id): static
    {
        return $this->setData(self::schema_fields_sess_id, $sess_id);
    }
    public function getLoginIp()
    {
        return $this->getData(self::schema_fields_login_ip);
    }
    public function setLoginIp(string $ip): static
    {
        return $this->setData(self::schema_fields_login_ip, $ip);
    }
    public function isSandboxAccount(): bool
    {
        return (bool)$this->getData(self::schema_fields_is_sandbox);
    }
    public function setSandboxAccount(bool $flag): static
    {
        return $this->setData(self::schema_fields_is_sandbox, $flag ? 1 : 0);
    }
}
