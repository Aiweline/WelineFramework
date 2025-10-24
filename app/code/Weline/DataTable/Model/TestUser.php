<?php

namespace Weline\DataTable\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

class TestUser extends Model
{
    /**
     * 指定表名，避免与其他模块冲突
     */
    public string $table = 'datatable_test_users';

    /**
     * 主键
     */
    protected string $primary_key = 'id';

    /**
     * 字段定义
     */
    protected array $fields = [
        'id' => [
            'type' => 'int',
            'length' => 11,
            'auto_increment' => true,
            'primary_key' => true,
            'comment' => '用户ID'
        ],
        'name' => [
            'type' => 'varchar',
            'length' => 100,
            'not_null' => true,
            'comment' => '用户姓名'
        ],
        'email' => [
            'type' => 'varchar',
            'length' => 255,
            'not_null' => true,
            'unique' => true,
            'comment' => '用户邮箱'
        ],
        'phone' => [
            'type' => 'varchar',
            'length' => 20,
            'comment' => '用户电话'
        ],
        'status' => [
            'type' => 'tinyint',
            'length' => 1,
            'default' => 1,
            'comment' => '用户状态：1-启用，0-禁用'
        ],
        'gender' => [
            'type' => 'enum',
            'values' => ['male', 'female', 'other'],
            'default' => 'male',
            'comment' => '性别'
        ],
        'birth_date' => [
            'type' => 'date',
            'comment' => '出生日期'
        ],
        'avatar' => [
            'type' => 'varchar',
            'length' => 255,
            'comment' => '头像路径'
        ],
        'bio' => [
            'type' => 'text',
            'comment' => '个人简介'
        ],
        'created_at' => [
            'type' => 'datetime',
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '创建时间'
        ],
        'updated_at' => [
            'type' => 'datetime',
            'default' => 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'comment' => '更新时间'
        ]
    ];

    /**
     * 安装方法
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        $this->createTable();
    }

    /**
     * 升级方法
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * 设置方法
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->createTable();
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