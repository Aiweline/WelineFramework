<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DataTable\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '测试用户表')]
#[Index(name: 'idx_email', columns: ['email'], type: 'UNIQUE')]
class TestUser extends Model
{

    public const schema_table = 'datatable_test_users';
    public const schema_primary_key = 'id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '用户ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 100, nullable: false, comment: '用户姓名')]
    public const schema_fields_name = 'name';
    #[Col('varchar', 255, nullable: false, comment: '用户邮箱')]
    public const schema_fields_email = 'email';
    #[Col('varchar', 20, comment: '用户电话')]
    public const schema_fields_phone = 'phone';
    #[Col('int', 1, default: 1, comment: '用户状态')]
    public const schema_fields_status = 'status';
    #[Col('varchar', 20, default: 'male', comment: '性别')]
    public const schema_fields_gender = 'gender';
    #[Col('date', comment: '出生日期')]
    public const schema_fields_birth_date = 'birth_date';
    #[Col('varchar', 255, comment: '头像路径')]
    public const schema_fields_avatar = 'avatar';
    #[Col('text', comment: '个人简介')]
    public const schema_fields_bio = 'bio';
    #[Col('varchar', 255, comment: '密码')]
    public const schema_fields_password = 'password';
    #[Col('varchar', 255, comment: '个人网站URL')]
    public const schema_fields_website_url = 'website_url';
    #[Col('varchar', 100, comment: '搜索关键词')]
    public const schema_fields_search_keyword = 'search_keyword';
    #[Col('int', 3, comment: '年龄')]
    public const schema_fields_age = 'age';
    #[Col('decimal', '10,2', comment: '价格')]
    public const schema_fields_price = 'price';
    #[Col('decimal', '10,2', comment: '金额')]
    public const schema_fields_amount = 'amount';
    #[Col('int', default: 0, comment: '数量')]
    public const schema_fields_count = 'count';
    #[Col('text', comment: '详细描述')]
    public const schema_fields_description = 'description';
    #[Col('text', comment: '内容')]
    public const schema_fields_content = 'content';
    #[Col('text', comment: '详细信息')]
    public const schema_fields_detail = 'detail';
    #[Col('text', comment: '备注')]
    public const schema_fields_remark = 'remark';
    #[Col('text', comment: '备注说明')]
    public const schema_fields_note = 'note';
    #[Col('text', comment: '评论')]
    public const schema_fields_comment = 'comment';
    #[Col('datetime', comment: '创建日期时间')]
    public const schema_fields_created_datetime = 'created_datetime';
    #[Col('datetime', comment: '登录时间')]
    public const schema_fields_login_time = 'login_time';
    #[Col('date', comment: '出生月份')]
    public const schema_fields_birth_month = 'birth_month';
    #[Col('date', comment: '工作周')]
    public const schema_fields_work_week = 'work_week';
    #[Col('varchar', 255, comment: '照片')]
    public const schema_fields_photo = 'photo';
    #[Col('varchar', 255, comment: '附件文件')]
    public const schema_fields_attachment = 'attachment';
    #[Col('varchar', 7, default: '#000000', comment: '主题颜色')]
    public const schema_fields_theme_color = 'theme_color';
    #[Col('int', 3, default: 50, comment: '评分范围')]
    public const schema_fields_score_range = 'score_range';
    #[Col('varchar', 20, default: 'user', comment: '用户类型')]
    public const schema_fields_user_type = 'user_type';
    #[Col('int', 1, default: 1, comment: '用户状态')]
    public const schema_fields_user_state = 'user_state';
    #[Col('int', 1, default: 0, comment: '是否VIP')]
    public const schema_fields_is_vip = 'is_vip';
    #[Col('varchar', 20, default: 'free', comment: '订阅类型')]
    public const schema_fields_subscription_type = 'subscription_type';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';
/**
     * 获取测试数据
     */
    public function getTestData(): array
    {
        return [
            [
                'id' => 1,
                'name' => '张三',
                'email' => 'zhangsan@example.com',
                'phone' => '13800138001',
                'status' => 1,
                'gender' => 'male',
                'birth_date' => '1990-01-15',
                'avatar' => '/uploads/avatars/user1.jpg',
                'bio' => '热爱编程的软件工程师',
                'password' => 'hashed_password_123',
                'website_url' => 'https://zhangsan.example.com',
                'search_keyword' => 'PHP开发',
                'age' => 34,
                'price' => 99.99,
                'amount' => 1000.00,
                'count' => 10,
                'description' => '这是一段详细描述信息',
                'content' => '这是内容信息',
                'detail' => '这是详细信息',
                'remark' => '这是备注信息',
                'note' => '这是备注说明',
                'comment' => '这是评论内容',
                'created_datetime' => '2024-01-01 10:00:00',
                'login_time' => '2024-01-01 09:30:00',
                'birth_month' => '1990-01-01',
                'work_week' => '2024-01-01',
                'photo' => '/uploads/photos/user1.jpg',
                'attachment' => '/uploads/attachments/file1.pdf',
                'theme_color' => '#3498db',
                'score_range' => 85,
                'user_type' => 'admin',
                'user_state' => 1,
                'is_vip' => 1,
                'subscription_type' => 'premium',
                'created_at' => '2024-01-01 10:00:00',
                'updated_at' => '2024-01-01 10:00:00'
            ],
            [
                'id' => 2,
                'name' => '李四',
                'email' => 'lisi@example.com',
                'phone' => '13800138002',
                'status' => 1,
                'gender' => 'female',
                'birth_date' => '1992-03-20',
                'avatar' => '/uploads/avatars/user2.jpg',
                'bio' => 'UI/UX设计师，专注于用户体验',
                'password' => 'hashed_password_456',
                'website_url' => 'https://lisi.design.com',
                'search_keyword' => 'UI设计',
                'age' => 32,
                'price' => 199.99,
                'amount' => 2000.00,
                'count' => 5,
                'description' => 'UI/UX设计师的详细描述',
                'content' => '设计相关内容',
                'detail' => '设计详细信息',
                'remark' => '设计备注',
                'note' => '设计说明',
                'comment' => '设计评论',
                'created_datetime' => '2024-01-02 11:30:00',
                'login_time' => '2024-01-02 10:15:00',
                'birth_month' => '1992-03-01',
                'work_week' => '2024-01-08',
                'photo' => '/uploads/photos/user2.jpg',
                'attachment' => '/uploads/attachments/design.pdf',
                'theme_color' => '#e74c3c',
                'score_range' => 90,
                'user_type' => 'user',
                'user_state' => 1,
                'is_vip' => 0,
                'subscription_type' => 'basic',
                'created_at' => '2024-01-02 11:30:00',
                'updated_at' => '2024-01-02 11:30:00'
            ],
            [
                'id' => 3,
                'name' => '王五',
                'email' => 'wangwu@example.com',
                'phone' => '13800138003',
                'status' => 0,
                'gender' => 'male',
                'birth_date' => '1988-07-10',
                'avatar' => '/uploads/avatars/user3.jpg',
                'bio' => '产品经理，负责产品规划和设计',
                'created_at' => '2024-01-03 09:15:00',
                'updated_at' => '2024-01-03 09:15:00'
            ],
            [
                'id' => 4,
                'name' => '赵六',
                'email' => 'zhaoliu@example.com',
                'phone' => '13800138004',
                'status' => 1,
                'gender' => 'female',
                'birth_date' => '1995-12-05',
                'avatar' => '/uploads/avatars/user4.jpg',
                'bio' => '前端开发工程师，擅长Vue和React',
                'created_at' => '2024-01-04 14:20:00',
                'updated_at' => '2024-01-04 14:20:00'
            ],
            [
                'id' => 5,
                'name' => '孙七',
                'email' => 'sunqi@example.com',
                'phone' => '13800138005',
                'status' => 1,
                'gender' => 'male',
                'birth_date' => '1991-09-18',
                'avatar' => '/uploads/avatars/user5.jpg',
                'bio' => '后端开发工程师，专注于Java和Spring',
                'created_at' => '2024-01-05 16:45:00',
                'updated_at' => '2024-01-05 16:45:00'
            ],
            [
                'id' => 6,
                'name' => '周八',
                'email' => 'zhouba@example.com',
                'phone' => '13800138006',
                'status' => 0,
                'gender' => 'female',
                'birth_date' => '1993-05-25',
                'avatar' => '/uploads/avatars/user6.jpg',
                'bio' => '测试工程师，确保产品质量',
                'created_at' => '2024-01-06 08:30:00',
                'updated_at' => '2024-01-06 08:30:00'
            ],
            [
                'id' => 7,
                'name' => '吴九',
                'email' => 'wujiu@example.com',
                'phone' => '13800138007',
                'status' => 1,
                'gender' => 'male',
                'birth_date' => '1989-11-12',
                'avatar' => '/uploads/avatars/user7.jpg',
                'bio' => '运维工程师，负责系统维护和部署',
                'created_at' => '2024-01-07 13:10:00',
                'updated_at' => '2024-01-07 13:10:00'
            ],
            [
                'id' => 8,
                'name' => '郑十',
                'email' => 'zhengshi@example.com',
                'phone' => '13800138008',
                'status' => 1,
                'gender' => 'female',
                'birth_date' => '1994-02-28',
                'avatar' => '/uploads/avatars/user8.jpg',
                'bio' => '数据分析师，擅长数据挖掘和可视化',
                'created_at' => '2024-01-08 15:20:00',
                'updated_at' => '2024-01-08 15:20:00'
            ],
            [
                'id' => 9,
                'name' => '钱十一',
                'email' => 'qianshiyi@example.com',
                'phone' => '13800138009',
                'status' => 1,
                'gender' => 'male',
                'birth_date' => '1990-08-14',
                'avatar' => '/uploads/avatars/user9.jpg',
                'bio' => '算法工程师，专注于机器学习和AI',
                'created_at' => '2024-01-09 10:45:00',
                'updated_at' => '2024-01-09 10:45:00'
            ],
            [
                'id' => 10,
                'name' => '孙十二',
                'email' => 'sunshier@example.com',
                'phone' => '13800138010',
                'status' => 0,
                'gender' => 'female',
                'birth_date' => '1996-04-30',
                'avatar' => '/uploads/avatars/user10.jpg',
                'bio' => '移动端开发工程师，擅长iOS和Android',
                'created_at' => '2024-01-10 12:00:00',
                'updated_at' => '2024-01-10 12:00:00'
            ]
        ];
    }

    /**
     * 获取状态选项
     */
    public function getStatusOptions(): array
    {
        return [
            ['value' => 1, 'label' => '启用'],
            ['value' => 0, 'label' => '禁用']
        ];
    }

    /**
     * 获取性别选项
     */
    public function getGenderOptions(): array
    {
        return [
            ['value' => 'male', 'label' => '男'],
            ['value' => 'female', 'label' => '女'],
            ['value' => 'other', 'label' => '其他']
        ];
    }

    /**
     * 获取用户类型选项
     */
    public function getUserTypeOptions(): array
    {
        return [
            ['value' => 'admin', 'label' => '管理员'],
            ['value' => 'user', 'label' => '普通用户'],
            ['value' => 'guest', 'label' => '访客']
        ];
    }

    /**
     * 获取订阅类型选项
     */
    public function getSubscriptionTypeOptions(): array
    {
        return [
            ['value' => 'free', 'label' => '免费'],
            ['value' => 'basic', 'label' => '基础'],
            ['value' => 'premium', 'label' => '高级']
        ];
    }

    /**
     * 状态获取器
     */
    public function getStatusTextAttribute($value): string
    {
        return $value == 1 ? '启用' : '禁用';
    }

    /**
     * 性别获取器
     */
    public function getGenderTextAttribute($value): string
    {
        $options = [
            'male' => '男',
            'female' => '女',
            'other' => '其他'
        ];
        return $options[$value] ?? '未知';
    }
}

