# 优惠券扩展示例

本文档演示如何通过 WeShop_Coupon 模块扩展购物车的优惠券功能。

## 概述

优惠券功能通过以下方式与购物车集成：

1. 实现 `WeShop_Cart::frontend::cart::coupon-input` Hook
2. 监听 `WeShop_Cart::totals_collect` 事件
3. 创建优惠券 Widget 并注册到 `cart-coupon` Slot

## 1. 实现 Hook

创建文件 `WeShop/Coupon/view/hooks/WeShop_Cart/frontend/cart/coupon-input.phtml`:

```phtml
<?php
/**
 * 优惠券输入框 Hook 实现
 * @hook WeShop_Cart::frontend::cart::coupon-input
 * @hook-priority 100
 * @hook-solo true
 */
use Weline\Framework\Manager\ObjectManager;
use WeShop\Coupon\Service\CouponService;
use WeShop\Customer\Session\CustomerSession;

$session = ObjectManager::getInstance(CustomerSession::class);
$customer = $session->getCustomer();

// 获取已应用的优惠券
$appliedCoupon = null;
if ($customer) {
    $couponService = ObjectManager::getInstance(CouponService::class);
    $appliedCoupon = $couponService->getAppliedCoupon($customer->getId());
}
?>

<div class="coupon-section" data-coupon-section>
    <?php if ($appliedCoupon): ?>
        <!-- 已应用优惠券 -->
        <div class="applied-coupon">
            <span class="coupon-code"><?= htmlspecialchars($appliedCoupon['code']) ?></span>
            <span class="coupon-discount">-<?= $this->formatPrice($appliedCoupon['discount']) ?></span>
            <button type="button" 
                    class="remove-coupon-btn" 
                    data-action="remove-coupon"
                    aria-label="移除优惠券">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
    <?php else: ?>
        <!-- 优惠券输入框 -->
        <div class="coupon-input-wrapper">
            <input type="text" 
                   id="coupon-code" 
                   class="coupon-input" 
                   placeholder="<?= __('Enter coupon code') ?>"
                   data-coupon-input/>
            <button type="button" 
                    class="coupon-apply-btn" 
                    data-action="apply-coupon"
                    disabled>
                <?= __('Apply') ?>
            </button>
        </div>
        <div class="coupon-message" data-coupon-message style="display:none;"></div>
    <?php endif; ?>
</div>

<script>
(function() {
    const section = document.querySelector('[data-coupon-section]');
    if (!section) return;
    
    const input = section.querySelector('[data-coupon-input]');
    const applyBtn = section.querySelector('[data-action="apply-coupon"]');
    const message = section.querySelector('[data-coupon-message]');
    
    // 输入时启用/禁用按钮
    if (input) {
        input.addEventListener('input', () => {
            applyBtn.disabled = !input.value.trim();
        });
        
        // 回车键提交
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && input.value.trim()) {
                applyBtn.click();
            }
        });
    }
    
    // 应用优惠券
    if (applyBtn) {
        applyBtn.addEventListener('click', async () => {
            const code = input.value.trim();
            if (!code) return;
            
            applyBtn.disabled = true;
            applyBtn.textContent = '<?= __('Applying...') ?>';
            
            try {
                const CouponApi = await Weline.Api.resource('coupon');
                const result = await CouponApi.apply({ code });
                
                if (result.success) {
                    // 刷新页面以显示折扣
                    location.reload();
                } else {
                    showMessage(result.message, 'error');
                    applyBtn.disabled = false;
                    applyBtn.textContent = '<?= __('Apply') ?>';
                }
            } catch (error) {
                showMessage('<?= __('Failed to apply coupon') ?>', 'error');
                applyBtn.disabled = false;
                applyBtn.textContent = '<?= __('Apply') ?>';
            }
        });
    }
    
    // 移除优惠券
    const removeBtn = section.querySelector('[data-action="remove-coupon"]');
    if (removeBtn) {
        removeBtn.addEventListener('click', async () => {
            try {
                const CouponApi = await Weline.Api.resource('coupon');
                await CouponApi.remove({});
                location.reload();
            } catch (error) {
                console.error('Failed to remove coupon:', error);
            }
        });
    }
    
    function showMessage(text, type) {
        if (message) {
            message.textContent = text;
            message.className = `coupon-message ${type}`;
            message.style.display = '';
        }
    }
})();
</script>

<style>
.coupon-section {
    margin-bottom: 1rem;
}

.coupon-input-wrapper {
    display: flex;
    gap: 0.5rem;
}

.coupon-input {
    flex: 1;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
    font-size: 0.875rem;
}

.coupon-apply-btn {
    padding: 0.75rem 1.25rem;
    background: var(--primary, #3b82f6);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.coupon-apply-btn:disabled {
    background: var(--bg-secondary, #f5f5f5);
    color: var(--text-muted, #999);
    cursor: not-allowed;
}

.applied-coupon {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: #dcfce7;
    border-radius: 8px;
}

.applied-coupon .coupon-code {
    font-weight: 600;
    color: #166534;
}

.applied-coupon .coupon-discount {
    margin-left: auto;
    font-weight: 600;
    color: #166534;
}

.remove-coupon-btn {
    padding: 0.25rem;
    background: transparent;
    border: none;
    cursor: pointer;
    color: #166534;
}

.coupon-message {
    margin-top: 0.5rem;
    font-size: 0.8rem;
    padding: 0.5rem;
    border-radius: 4px;
}

.coupon-message.error {
    background: #fee2e2;
    color: #991b1b;
}

.coupon-message.success {
    background: #dcfce7;
    color: #166534;
}
</style>
```

## 2. 监听事件计算折扣

创建 Observer 监听 `WeShop_Cart::totals_collect` 事件：

`WeShop/Coupon/Observer/ApplyCouponDiscount.php`:

```php
<?php

namespace WeShop\Coupon\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Coupon\Service\CouponService;
use Weline\Framework\Manager\ObjectManager;

class ApplyCouponDiscount implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $customerId = $data['customer_id'] ?? 0;
        $totals = &$data['totals'];
        
        if (!$customerId) {
            return;
        }
        
        /** @var CouponService $couponService */
        $couponService = ObjectManager::getInstance(CouponService::class);
        $appliedCoupon = $couponService->getAppliedCoupon($customerId);
        
        if ($appliedCoupon) {
            // 计算折扣金额
            $discount = $couponService->calculateDiscount(
                $appliedCoupon,
                $totals['subtotal']
            );
            
            // 应用折扣
            $totals['discount'] = ($totals['discount'] ?? 0) + $discount;
            $totals['coupon_code'] = $appliedCoupon['code'];
            $totals['coupon_discount'] = $discount;
        }
    }
}
```

注册 Observer：

`WeShop/Coupon/etc/event.xml`:

```xml
<?xml version="1.0"?>
<events>
    <event name="WeShop_Cart::totals_collect">
        <observer name="apply_coupon_discount" 
                  class="WeShop\Coupon\Observer\ApplyCouponDiscount"/>
    </event>
</events>
```

## 3. 显示折扣金额

实现 `WeShop_Cart::frontend::cart::discount-display` Hook：

`WeShop/Coupon/view/hooks/WeShop_Cart/frontend/cart/discount-display.phtml`:

```phtml
<?php
/**
 * 折扣金额显示
 * @hook WeShop_Cart::frontend::cart::discount-display
 * @hook-priority 100
 */
$totals = $this->getData('totals') ?? [];
$couponDiscount = $totals['coupon_discount'] ?? 0;
$couponCode = $totals['coupon_code'] ?? '';
?>

<?php if ($couponDiscount > 0): ?>
<div class="summary-row discount-row">
    <span class="summary-label">
        <?= __('Discount') ?>
        <?php if ($couponCode): ?>
            <span class="coupon-badge"><?= htmlspecialchars($couponCode) ?></span>
        <?php endif; ?>
    </span>
    <span class="summary-value discount-value">
        -<?= $this->formatPrice($couponDiscount) ?>
    </span>
</div>
<?php endif; ?>
```

## 4. 创建 Widget（可选）

如果需要在 Widget Slot 中使用，可以创建 Widget：

`WeShop/Coupon/extends/module/Weline_Widget/WeShop_Coupon/widget.php`:

```php
<?php

return [
    [
        'name' => '优惠券输入框',
        'description' => '购物车优惠券输入框',
        'type' => 'coupon',
        'code' => 'coupon-input',
        'template' => 'WeShop_Coupon::widgets/coupon-input/default.phtml',
        'area' => 'frontend',
        'params' => [],
    ],
];
```

## 总结

通过以上步骤，优惠券模块可以无缝集成到购物车中，而无需修改购物车模块的任何代码。这遵循了模块解耦的原则：

- **Hook**: 提供 UI 扩展点
- **事件**: 提供业务逻辑扩展点
- **Widget Slot**: 提供可视化编辑支持
