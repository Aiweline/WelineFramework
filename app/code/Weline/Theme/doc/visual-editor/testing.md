# 可视化编辑器测试规范

## 单元测试

### SlotValidator 测试

位置: `GuoLaiRen\PageBuilder\test\SlotValidatorTest.php`

| 测试用例 | 描述 | 预期结果 |
|----------|------|----------|
| testRegionIsolation_HeaderComponent | header 组件区域隔离 | header 组件只能放入 header 区域 |
| testRegionIsolation_FooterComponent | footer 组件区域隔离 | footer 组件只能放入 footer 区域 |
| testRegionIsolation_ContentComponent | content 组件区域隔离 | content 组件只能放入 content 区域 |
| testComponentNotFound | 不存在的组件 | 返回 COMPONENT_NOT_FOUND 错误 |
| testSlotPlacement_Valid | 有效的 slot 放置 | 验证通过 |
| testGetRegionAccepts | 获取区域接受类别 | 返回正确的类别列表 |
| testIsContainer | 检查容器组件 | 正确识别容器组件 |
| testGetComponentInfo | 获取组件信息 | 返回完整的组件信息 |
| testValidationResult | ValidationResult 类 | 正确处理成功/失败结果 |

### ComponentRenderer 测试

位置: `GuoLaiRen\PageBuilder\test\ComponentRendererTest.php`

| 测试用例 | 描述 | 预期结果 |
|----------|------|----------|
| testRenderResult_Success | 成功渲染结果 | isSuccess=true, HTML 不为空 |
| testRenderResult_Fail | 失败渲染结果 | isSuccess=false, 有错误消息 |
| testGeneratePlaceholder | 生成占位符 | HTML 包含加载动画 |
| testRenderSingle_ComponentNotFound | 渲染不存在组件 | 返回 TEMPLATE_NOT_FOUND 错误 |
| testRenderBatch | 批量渲染 | 返回正确数量的结果 |

## E2E 测试用例

### 1. 区域隔离测试

```javascript
describe('区域隔离', () => {
    it('header 组件不能拖入 content 区域', async () => {
        // 1. 打开可视化编辑器
        await page.goto('/backend/theme/visual?page_id=1');
        
        // 2. 从组件库拖拽 header-nav 组件
        const headerComponent = await page.$('[data-component-code="header-nav"]');
        const contentArea = await page.$('[data-section="content"]');
        
        // 3. 尝试拖入 content 区域
        await headerComponent.dragTo(contentArea);
        
        // 4. 验证显示错误提示
        const toast = await page.$('.toast-error');
        expect(await toast.textContent()).toContain('不能放入');
        
        // 5. 验证组件未添加
        const contentComponents = await contentArea.$$('.vb-component');
        expect(contentComponents).toHaveLength(0);
    });
    
    it('footer 组件不能拖入 header 区域', async () => {
        // 类似测试逻辑
    });
    
    it('content 组件可以拖入 content 区域', async () => {
        // 1. 拖拽 slider 组件到 content 区域
        // 2. 验证组件成功添加
        // 3. 验证显示成功提示
    });
});
```

### 2. 局部刷新测试

```javascript
describe('局部刷新', () => {
    it('添加组件时只更新目标区域', async () => {
        // 1. 记录 header 区域的 HTML
        const headerBefore = await page.$eval('[data-section="header"]', el => el.innerHTML);
        
        // 2. 添加组件到 content 区域
        await addComponent('slider', 'content');
        
        // 3. 验证 header 区域未变化
        const headerAfter = await page.$eval('[data-section="header"]', el => el.innerHTML);
        expect(headerAfter).toEqual(headerBefore);
        
        // 4. 验证 content 区域有新组件
        const newComponent = await page.$('[data-component="slider"]');
        expect(newComponent).toBeTruthy();
    });
    
    it('添加组件时显示加载占位符', async () => {
        // 1. 开始添加组件（使用 slow network）
        await page.setOfflineMode(true);
        
        // 2. 触发添加
        // 3. 验证出现加载占位符
        const placeholder = await page.$('.vb-component-loading');
        expect(placeholder).toBeTruthy();
    });
});
```

### 3. 嵌套组件跟随移动测试

```javascript
describe('嵌套组件跟随移动', () => {
    beforeEach(async () => {
        // 添加一个 FAQ 组件（容器）
        await addComponent('faq', 'content');
        // 在 FAQ 的 items slot 中添加子组件
        await addComponent('faq-item', 'content', {
            parentInstanceId: 'faq-instance-1',
            slot: 'items'
        });
    });
    
    it('拖拽父组件时子组件跟随移动', async () => {
        // 1. 获取子组件
        const childBefore = await page.$('[data-component="faq-item"]');
        const childParentBefore = await childBefore.$eval('.', el => el.closest('[data-component="faq"]'));
        
        // 2. 拖拽 FAQ 组件到新位置
        const faqComponent = await page.$('[data-component="faq"]');
        await faqComponent.dragTo(targetPosition);
        
        // 3. 验证子组件仍在父组件内
        const childAfter = await page.$('[data-component="faq-item"]');
        const childParentAfter = await childAfter.$eval('.', el => el.closest('[data-component="faq"]'));
        
        expect(childParentAfter).toBeTruthy();
    });
    
    it('删除父组件时子组件一起删除', async () => {
        // 1. 删除 FAQ 组件
        await removeComponent('faq-instance-1');
        
        // 2. 验证子组件也被删除
        const child = await page.$('[data-component="faq-item"]');
        expect(child).toBeFalsy();
    });
});
```

### 4. 智能筛选测试

```javascript
describe('智能筛选', () => {
    it('选中 header 区域时只显示 header 组件', async () => {
        // 1. 点击 header 区域
        await page.click('[data-section="header"]');
        
        // 2. 验证 header 组件可见
        const headerNav = await page.$('.component-card[data-component-code="header-nav"]');
        expect(await headerNav.isVisible()).toBe(true);
        
        // 3. 验证 content 组件被隐藏
        const slider = await page.$('.component-card[data-component-code="slider"]');
        expect(await slider.isVisible()).toBe(false);
    });
    
    it('选中 slot 时只显示兼容组件', async () => {
        // 1. 点击 FAQ 组件的 items slot
        await page.click('[data-component="faq"] [data-slot="items"]');
        
        // 2. 验证显示筛选提示
        const hint = await page.$('.component-filter-hint');
        expect(await hint.textContent()).toContain('items');
        
        // 3. 验证只显示兼容组件
    });
    
    it('点击清除筛选恢复全部显示', async () => {
        // 1. 先触发筛选
        await page.click('[data-section="header"]');
        
        // 2. 点击清除筛选
        await page.click('.clear-filter-btn');
        
        // 3. 验证所有组件可见
        const allCards = await page.$$('.component-card');
        for (const card of allCards) {
            expect(await card.isVisible()).toBe(true);
        }
    });
});
```

## 运行测试

### 单元测试

```bash
# 运行 SlotValidator 测试
php bin/phpunit app/code/GuoLaiRen/PageBuilder/test/SlotValidatorTest.php

# 运行 ComponentRenderer 测试
php bin/phpunit app/code/GuoLaiRen/PageBuilder/test/ComponentRendererTest.php

# 运行所有 PageBuilder 测试
php bin/phpunit app/code/GuoLaiRen/PageBuilder/test/
```

### E2E 测试

```bash
# 使用 Playwright 运行 E2E 测试
npx playwright test visual-editor.spec.js

# 使用 Cypress 运行 E2E 测试
npx cypress run --spec "cypress/e2e/visual-editor.cy.js"
```

## 测试覆盖率目标

| 模块 | 目标覆盖率 |
|------|-----------|
| SlotValidator | >= 80% |
| ComponentRenderer | >= 70% |
| Component API | >= 75% |
| 前端筛选逻辑 | >= 60% |
