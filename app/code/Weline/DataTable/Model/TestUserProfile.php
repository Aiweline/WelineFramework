<?php

declare(strict_types=1);

namespace Weline\DataTable\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '测试用户资料表')]
#[Index(name: 'idx_user_id', columns: ['user_id'])]
class TestUserProfile extends Model
{
    public const schema_table = 'datatable_test_user_profiles';
    public const schema_primary_key = 'id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '资料ID')]
    public const schema_fields_ID = 'id';

    #[Col('int', nullable: false, comment: '用户ID')]
    public const schema_fields_user_id = 'user_id';

    #[Col('varchar', 100, comment: '真实姓名')]
    public const schema_fields_real_name = 'real_name';

    #[Col('varchar', 18, comment: '身份证号')]
    public const schema_fields_id_card = 'id_card';

    #[Col('varchar', 30, default: 'college', comment: '学历')]
    public const schema_fields_education = 'education';

    #[Col('varchar', 100, comment: '职业')]
    public const schema_fields_occupation = 'occupation';

    #[Col('varchar', 200, comment: '公司名称')]
    public const schema_fields_company = 'company';

    #[Col('decimal', '10,2', comment: '薪资')]
    public const schema_fields_salary = 'salary';

    #[Col('text', comment: '兴趣爱好')]
    public const schema_fields_hobby = 'hobby';

    #[Col('text', comment: '个人介绍')]
    public const schema_fields_introduction = 'introduction';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';

    public function getEducationOptions(): array
    {
        return [
            ['value' => 'primary', 'label' => '小学'],
            ['value' => 'middle', 'label' => '初中'],
            ['value' => 'high', 'label' => '高中'],
            ['value' => 'college', 'label' => '大专'],
            ['value' => 'university', 'label' => '本科'],
            ['value' => 'graduate', 'label' => '研究生'],
        ];
    }

    public function getTestData(): array
    {
        return [
            [
                'id' => 1,
                'user_id' => 1,
                'real_name' => '张三',
                'id_card' => '110101199001150011',
                'education' => 'university',
                'occupation' => 'Software Engineer',
                'company' => 'Weline Lab',
                'salary' => 28000.00,
                'hobby' => 'Coding, running, reading',
                'introduction' => 'Backend-focused engineer used in join and cascade tests.',
                'created_at' => '2024-01-01 10:00:00',
                'updated_at' => '2024-01-01 10:00:00',
            ],
            [
                'id' => 2,
                'user_id' => 2,
                'real_name' => '李四',
                'id_card' => '310101199203200022',
                'education' => 'graduate',
                'occupation' => 'Product Designer',
                'company' => 'Weline Studio',
                'salary' => 24000.00,
                'hobby' => 'Design systems, photography',
                'introduction' => 'Profile seed for direct relation coverage.',
                'created_at' => '2024-01-02 11:30:00',
                'updated_at' => '2024-01-02 11:30:00',
            ],
            [
                'id' => 3,
                'user_id' => 3,
                'real_name' => '王五',
                'id_card' => '440101198807100033',
                'education' => 'college',
                'occupation' => 'Product Manager',
                'company' => 'Weline Product',
                'salary' => 22000.00,
                'hobby' => 'Planning, writing',
                'introduction' => 'Profile seed for relation-based cleanup tests.',
                'created_at' => '2024-01-03 09:15:00',
                'updated_at' => '2024-01-03 09:15:00',
            ],
        ];
    }
}
