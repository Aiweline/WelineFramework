<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/26 22:20:13
 */
namespace Weline\Cron\Model;
use Weline\Cron\Helper\CronStatus;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '定时任务表')]
#[Index(name: 'UNIQUE_EXECUTE_NAME', columns: ['execute_name'], type: 'UNIQUE')]
#[Index(name: 'UNIQUE_TASK_NAME', columns: ['name'], type: 'UNIQUE')]
class CronTask extends Model
{
    public const schema_table = 'weline_cron_task';
    public const schema_primary_key = 'id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 255, nullable: false, comment: '调度任务名')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 255, nullable: false, comment: '执行名')]
    public const schema_fields_EXECUTE_NAME = 'execute_name';
    #[Col('varchar', 128, nullable: false, comment: '模组')]
    public const schema_fields_MODULE = 'module';
    #[Col('varchar', 255, nullable: false, comment: 'PHP调度类')]
    public const schema_fields_CLASS = 'class';
    #[Col('text', comment: '任务描述')]
    public const schema_fields_TIP = 'tip';
    #[Col('varchar', 64, nullable: false, comment: '调度频率')]
    public const schema_fields_CRON_TIME = 'cron_time';
    #[Col('varchar', 8, default: 'pending', comment: '任务状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('float', default: 0, comment: '运行时长')]
    public const schema_fields_RUNTIME = 'runtime';
    #[Col('float', default: 0, comment: '阻塞时长')]
    public const schema_fields_BLOCK_TIME = 'block_time';
    #[Col('int', default: 0, comment: '阻塞次数')]
    public const schema_fields_BLOCK_TIMES = 'block_times';
    #[Col('int', default: 30, comment: '阻塞超时解锁时长')]
    public const schema_fields_BLOCK_UNLOCK_TIMEOUT = 'block_unlock_timeout';
    #[Col('varchar', 20, default: '0', comment: '运行时间戳')]
    public const schema_fields_RUN_TIME = 'run_time';
    #[Col('datetime', comment: '运行日期')]
    public const schema_fields_RUN_DATE = 'run_date';
    #[Col('datetime', comment: '下次运行时间')]
    public const schema_fields_NEXT_RUN_DATE = 'next_run_date';
    #[Col('datetime', comment: '最大下次运行时间')]
    public const schema_fields_MAX_NEXT_RUN_DATE = 'max_next_run_date';
    #[Col('datetime', comment: '上次运行时间')]
    public const schema_fields_PRE_RUN_DATE = 'pre_run_date';
    #[Col('int', default: 0, comment: '运行次数')]
    public const schema_fields_RUN_TIMES = 'run_times';
    #[Col('text', comment: '运行时错误')]
    public const schema_fields_RUNTIME_ERROR = 'runtime_error';
    #[Col('datetime', comment: '运行时错误发生时间')]
    public const schema_fields_RUNTIME_ERROR_DATE = 'runtime_error_date';
    #[Col('int', comment: '运行时进程ID')]
    public const schema_fields_PID = 'pid';
}
