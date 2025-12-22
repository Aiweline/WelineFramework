<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\UnitTest\TestCore;
use Weline\Seo\Model\SeoSubject;

/**
 * SeoSubject 模型测试
 * 
 * @package Weline_Seo
 */
class SeoSubjectTest extends TestCore
{
    /**
     * 测试模型实例化
     */
    public function testModelInstantiation(): void
    {
        $model = new SeoSubject();
        $this->assertInstanceOf(SeoSubject::class, $model);
    }

    /**
     * 测试 findOrCreate 方法
     */
    public function testFindOrCreate(): void
    {
        $model = new SeoSubject();
        $subject = $model->findOrCreate(SeoSubject::SUBJECT_TYPE_STORE, 1);
        
        $this->assertInstanceOf(SeoSubject::class, $subject);
        $this->assertEquals(SeoSubject::SUBJECT_TYPE_STORE, $subject->getSubjectType());
        $this->assertEquals(1, $subject->getSubjectId());
    }

    /**
     * 测试 Getters 和 Setters
     */
    public function testGettersAndSetters(): void
    {
        $model = new SeoSubject();
        
        $model->setSubjectType(SeoSubject::SUBJECT_TYPE_STORE)
            ->setSubjectId(1)
            ->setUrl('https://example.com')
            ->setTitle('测试标题')
            ->setDescription('测试描述')
            ->setLocale('zh-CN')
            ->setStatus(SeoSubject::STATUS_ENABLED);

        $this->assertEquals(SeoSubject::SUBJECT_TYPE_STORE, $model->getSubjectType());
        $this->assertEquals(1, $model->getSubjectId());
        $this->assertEquals('https://example.com', $model->getUrl());
        $this->assertEquals('测试标题', $model->getTitle());
        $this->assertEquals('测试描述', $model->getDescription());
        $this->assertEquals('zh-CN', $model->getLocale());
        $this->assertTrue($model->isEnabled());
    }
}

