<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DataTable\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class TestUser extends Model
{
    public const table = 'datatable_test_users';

    public const fields_ID = 'id';
    public const fields_name = 'name';
    public const fields_email = 'email';
    public const fields_phone = 'phone';
    public const fields_status = 'status';
    public const fields_gender = 'gender';
    public const fields_birth_date = 'birth_date';
    public const fields_avatar = 'avatar';
    public const fields_bio = 'bio';
    public const fields_password = 'password';
    public const fields_website_url = 'website_url';
    public const fields_search_keyword = 'search_keyword';
    public const fields_age = 'age';
    public const fields_price = 'price';
    public const fields_amount = 'amount';
    public const fields_count = 'count';
    public const fields_description = 'description';
    public const fields_content = 'content';
    public const fields_detail = 'detail';
    public const fields_remark = 'remark';
    public const fields_note = 'note';
    public const fields_comment = 'comment';
    public const fields_created_datetime = 'created_datetime';
    public const fields_login_time = 'login_time';
    public const fields_birth_month = 'birth_month';
    public const fields_work_week = 'work_week';
    public const fields_photo = 'photo';
    public const fields_attachment = 'attachment';
    public const fields_theme_color = 'theme_color';
    public const fields_score_range = 'score_range';
    public const fields_user_type = 'user_type';
    public const fields_user_state = 'user_state';
    public const fields_is_vip = 'is_vip';
    public const fields_subscription_type = 'subscription_type';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('测试用户表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '用户ID')
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 100, 'not null', '用户姓名')
                ->addColumn(self::fields_email, TableInterface::column_type_VARCHAR, 255, 'not null unique', '用户邮箱')
                ->addColumn(self::fields_phone, TableInterface::column_type_VARCHAR, 20, '', '用户电话')
                ->addColumn(self::fields_status, TableInterface::column_type_INTEGER, 1, 'default 1', '用户状态：1-启用，0-禁用')
                ->addColumn(self::fields_gender, TableInterface::column_type_VARCHAR, 20, "default 'male'", '性别：male-男，female-女，other-其他')
                ->addColumn(self::fields_birth_date, TableInterface::column_type_DATE, 0, '', '出生日期')
                ->addColumn(self::fields_avatar, TableInterface::column_type_VARCHAR, 255, '', '头像路径')
                ->addColumn(self::fields_bio, TableInterface::column_type_TEXT, 0, '', '个人简介')
                ->addColumn(self::fields_password, TableInterface::column_type_VARCHAR, 255, '', '密码')
                ->addColumn(self::fields_website_url, TableInterface::column_type_VARCHAR, 255, '', '个人网站URL')
                ->addColumn(self::fields_search_keyword, TableInterface::column_type_VARCHAR, 100, '', '搜索关键词')
                ->addColumn(self::fields_age, TableInterface::column_type_INTEGER, 3, '', '年龄')
                ->addColumn(self::fields_price, TableInterface::column_type_DECIMAL, '10,2', '', '价格')
                ->addColumn(self::fields_amount, TableInterface::column_type_DECIMAL, '10,2', '', '金额')
                ->addColumn(self::fields_count, TableInterface::column_type_INTEGER, 0, 'default 0', '数量')
                ->addColumn(self::fields_description, TableInterface::column_type_TEXT, 0, '', '详细描述')
                ->addColumn(self::fields_content, TableInterface::column_type_TEXT, 0, '', '内容')
                ->addColumn(self::fields_detail, TableInterface::column_type_TEXT, 0, '', '详细信息')
                ->addColumn(self::fields_remark, TableInterface::column_type_TEXT, 0, '', '备注')
                ->addColumn(self::fields_note, TableInterface::column_type_TEXT, 0, '', '备注说明')
                ->addColumn(self::fields_comment, TableInterface::column_type_TEXT, 0, '', '评论')
                ->addColumn(self::fields_created_datetime, TableInterface::column_type_DATETIME, 0, '', '创建日期时间')
                ->addColumn(self::fields_login_time, TableInterface::column_type_TIMESTAMP, 0, '', '登录时间')
                ->addColumn(self::fields_birth_month, TableInterface::column_type_DATE, 0, '', '出生月份')
                ->addColumn(self::fields_work_week, TableInterface::column_type_DATE, 0, '', '工作周')
                ->addColumn(self::fields_photo, TableInterface::column_type_VARCHAR, 255, '', '照片')
                ->addColumn(self::fields_attachment, TableInterface::column_type_VARCHAR, 255, '', '附件文件')
                ->addColumn(self::fields_theme_color, TableInterface::column_type_VARCHAR, 7, "default '#000000'", '主题颜色')
                ->addColumn(self::fields_score_range, TableInterface::column_type_INTEGER, 3, 'default 50', '评分范围')
                ->addColumn(self::fields_user_type, TableInterface::column_type_VARCHAR, 20, "default 'user'", '用户类型：admin-管理员，user-普通用户，guest-访客')
                ->addColumn(self::fields_user_state, TableInterface::column_type_INTEGER, 1, 'default 1', '用户状态')
                ->addColumn(self::fields_is_vip, TableInterface::column_type_INTEGER, 1, 'default 0', '是否VIP：1-是，0-否')
                ->addColumn(self::fields_subscription_type, TableInterface::column_type_VARCHAR, 20, "default 'free'", '订阅类型：free-免费，basic-基础，premium-高级')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', '更新时间')
                ->create();
        }
    }

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
                'login_time' => '09:30:00',
                'birth_month' => '1990-01',
                'work_week' => '2024-W01',
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
                'login_time' => '10:15:00',
                'birth_month' => '1992-03',
                'work_week' => '2024-W02',
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
