window.WelineWidgetAssets.register('theme-product-cross-sell-default-0', function (widgetScript) {
(function() {
    const root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;

    const crossSellMessages = {
        selectOne: JSON.parse(widgetScript.dataset.v1 || 'null'),
        added: JSON.parse(widgetScript.dataset.v2 || 'null'),
        addAll: JSON.parse(widgetScript.dataset.v3 || 'null')
    };
    
    const checkboxes = root.querySelectorAll('.cross-checkbox');
    const totalEl = root.querySelector('.total-price .value');

    function showCrossSellNotice(message, type) {
        if (window.Weline && window.Weline.UI.toast && typeof window.Weline.UI.toast.show === 'function') {
            window.Weline.UI.toast.show(message, {tone: type || 'info'});
            return;
        }
        const status = root.querySelector('.cross-sell-status');
        if (status) {
            status.textContent = message;
            status.className = 'cross-sell-status is-' + (type || 'info');
            return;
        }
        console.info(message);
    }

    function setAddAllButtonState(button, state, label) {
        button.dataset.state = state;
        const labelNode = button.querySelector('[data-cross-sell-label]');
        if (labelNode) labelNode.textContent = label;
    }
    
    function updateTotal() {
        if (!totalEl) return;
        
        let total = 0;
        checkboxes.forEach(function(cb) {
            if (cb.checked) {
                total += parseFloat(cb.dataset.price);
            }
        });
        
        totalEl.textContent = '¥' + total.toFixed(2);
    }
    
    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', updateTotal);
    });
    
    // 全部加入购物车
    const addAllBtn = root.querySelector('.btn-add-all');
    if (addAllBtn) {
        addAllBtn.addEventListener('click', function() {
            const selectedIds = [];
            checkboxes.forEach(function(cb) {
                if (cb.checked) {
                    const product = cb.closest('.cross-product');
                    selectedIds.push(product.dataset.productId);
                }
            });
            
            if (selectedIds.length === 0) {
                showCrossSellNotice(crossSellMessages.selectOne, 'warning');
                return;
            }
            
            console.log('Add to cart:', selectedIds);
            
            // 显示成功状态
            showCrossSellNotice(crossSellMessages.added, 'success');
            setAddAllButtonState(this, 'saved', crossSellMessages.added);
            this.style.background = 'var(--weline-theme-success)';
            
            setTimeout(() => {
                setAddAllButtonState(this, 'idle', crossSellMessages.addAll);
                this.style.background = '';
            }, 2000);
        });
    }
})();
});
