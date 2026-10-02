<?php
declare(strict_types=1);

namespace Weline\Theme\Test;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
use Weline\Theme\Taglib\Slot;

/**
 * Weline_Theme 模块测试基类。
 *
 * 存在原因（2026-10-02 审查结论，见 dev/audit/theme-legacy-audit-20261002.md §17）：
 * 模块测试在 PHPUnit 单进程（processIsolation=false）下运行，**跨文件共享静态状态**
 * （Slot 注册表、Taglib 静态缓存、RequestContext 键）会残留到后续测试，
 * 导致 Slot / Taglib / 模板编译簇出现 16 个"单独跑通过、混跑失败"的不稳定用例。
 *
 * 因此**所有**涉及这些共享态的测试都应继承本类，由基类在 setUp/tearDown 统一复位；
 * 不要再各自零散地写复位代码。
 */
abstract class ThemeTestCase extends TestCore
{
    protected function setUp(): void
    {
        parent::setUp();
        // 沙箱库缺 Theme 表会导致大量失败（见 dev/audit §19）；仅在表缺失时建表
        ThemeTestSchema::ensureCoreTables();
        $this->resetThemeSharedState();
    }

    protected function tearDown(): void
    {
        $this->resetThemeSharedState();
        parent::tearDown();
    }

    /**
     * 复位 Theme / Taglib 层的共享静态状态。
     *
     * 新增共享态时请在此处集中登记，避免再次出现跨文件污染。
     */
    protected function resetThemeSharedState(): void
    {
        // Slot 注册表：SlotTaglibCompileStateTest 等依赖"注册表为空"的初始态
        Slot::clearRegisteredSlots();

        // Taglib 静态缓存：ThemeCss / ThemeJs / ThemeTemplate 等标签解析结果会缓存
        Taglib::clearStaticCaches();

        // RequestContext 中的 Theme 插槽相关键
        RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE);
        RequestContext::remove(ThemeLayoutEntityPublishedSlotHost::CTX_FRAGMENTS);

        // ObjectManager 单例（Theme 域）如被测试替换，也应在此复位
        ObjectManager::removeInstance(Slot::class);
    }
}
