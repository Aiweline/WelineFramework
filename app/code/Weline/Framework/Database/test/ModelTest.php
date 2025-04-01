<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\test;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\Database\Model\ModelManager;
use Weline\Framework\Database\test\Model\WelineModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Setup\Data\Context as SetupContext;
use Weline\Framework\UnitTest\TestCore;

class ModelTest extends TestCore
{
    private WelineModel $model;

    public function setUp(): void
    {
        $this->model = ObjectManager::getInstance(WelineModel::class);
        /** @var ModelManager $modelManager */
        $modelManager = ObjectManager::getInstance(ModelManager::class);
        $devModule = Env::getInstance()->getModuleInfo('Weline_Framework');
        $module = new Module($devModule);
        $setup_context = ObjectManager::make(SetupContext::class, [
            'module_name' => $module->getName(),
            'module_version' => $module->getVersion(),
            'module_description' => $module->getDescription()
        ], '__construct');
        $modelManager->setupModel($this->model, 'install', $setup_context);
        // 插入一条数据
        $this->model->save(['id' => 1, 'stores' => 441114, 'name' => 'test']);
    }

    public function testProcessTable()
    {
        self::assertTrue($this->model->getTable() == 'test_weline_model');
    }

    public function testLoad()
    {
        self::assertTrue('test' == $this->model->load(1)->getData('name'));
    }

    /**
     * @throws \ReflectionException
     * @throws \Weline\Framework\Exception\Core
     */
    public function testSave()
    {
        # 测试插入数据 setData
        $this->model->setData('id', 2)
            ->setData('stores', 666)
            ->setData('name', 'test2')
            ->save();
        self::assertTrue(2 == $this->model->load(2)->getData('id'));

        # 测试插入数据 save
        $this->model->save(['id' => 3, 'stores' => 441114, 'name' => 'test3']);
        self::assertTrue(3 == $this->model->load(3)->getData('id'));

        # 查询数据
        self::assertTrue('test' == $this->model->load(1)->getData('name'));

        # 更新数据
        $this->model->load(1)->setData('name', 'test-update')->save();
        self::assertTrue('test-update' == $this->model->clearData()->load(1)->getData('name'));

        # 删除数据
        $this->model->load(1)->delete()->fetch();
        self::assertTrue(null == $this->model->load(1)->getData('name'));

        # 再次插入第一条数据
        $this->model->save(['id' => 1, 'stores' => 441114, 'name' => 'test']);
        self::assertTrue('test' == $this->model->load(1)->getData('name'));
    }

    public function testWhere()
    {
        self::assertTrue('666' == $this->model->where([['id', 2], ['stores', 666]])->find('stores')->fetch());
        self::assertTrue([
                'stores' => 666,
                'name' => 'test2'
            ] == $this->model->where([['id', 2], ['stores', 666]])->find('stores,name')->fetch());
        $this->model->where([['id', 2], ['stores', 666]])->find('stores,name')->fetch();
        self::assertTrue(666 == $this->model->getData('stores'));
        self::assertTrue('test2' == $this->model->getData('name'));
        self::assertTrue(null == $this->model->getData('id'));
    }

    public function testUpdate()
    {
        $this->model->load('3');
        # save 保存
        Debug::env('save');
        $this->model->setData(['name' => 'test-update1'])->save();
        $this->model->setData(['name' => 'test-update11'])->save();
        self::assertTrue('test-update11' == $this->model->load(3)->getData('name'));
        # update 查询保存
        $this->model->where($this->model::fields_ID, 3)
            ->update(['name' => 'test-update2'])
            ->fetch();
        self::assertTrue('test-update2' == $this->model->getData('name'));
    }
}
