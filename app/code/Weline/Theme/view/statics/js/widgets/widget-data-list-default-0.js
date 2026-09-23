window.WelineWidgetAssets.register('theme-data-list-default-0', function (widgetScript) {
(function() {
    'use strict';
    
    var widgetId = (widgetScript.dataset.v0);
    var container = document.getElementById(widgetId);
    if (!container) return;
    
    var selectedIds = [];
    
    // 搜索功能
    var searchInput = container.querySelector('[data-search-input]');
    if (searchInput) {
        var debounceTimer = null;
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() {
                var keyword = searchInput.value.toLowerCase().trim();
                var rows = container.querySelectorAll('tbody tr');
                rows.forEach(function(row) {
                    var text = row.textContent.toLowerCase();
                    row.style.display = text.includes(keyword) ? '' : 'none';
                });
            }, 300);
        });
    }
    
    // 批量选择功能
    var selectAllCheckbox = container.querySelector('[data-select-all]');
    var rowCheckboxes = container.querySelectorAll('[data-row-checkbox]');
    var batchBar = container.querySelector('[data-batch-bar]');
    var selectedCountEl = container.querySelector('[data-selected-count]');
    var clearSelectionBtn = container.querySelector('[data-clear-selection]');
    
    function updateBatchBar() {
        if (!batchBar) return;
        batchBar.style.display = selectedIds.length > 0 ? 'flex' : 'none';
        if (selectedCountEl) {
            selectedCountEl.textContent = selectedIds.length;
        }
    }
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            var isChecked = this.checked;
            rowCheckboxes.forEach(function(cb) {
                cb.checked = isChecked;
                var id = cb.value;
                var index = selectedIds.indexOf(id);
                if (isChecked && index === -1) {
                    selectedIds.push(id);
                } else if (!isChecked && index !== -1) {
                    selectedIds.splice(index, 1);
                }
            });
            updateBatchBar();
        });
    }
    
    rowCheckboxes.forEach(function(cb) {
        cb.addEventListener('change', function() {
            var id = this.value;
            var index = selectedIds.indexOf(id);
            if (this.checked && index === -1) {
                selectedIds.push(id);
            } else if (!this.checked && index !== -1) {
                selectedIds.splice(index, 1);
            }
            
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = selectedIds.length === rowCheckboxes.length;
            }
            updateBatchBar();
        });
    });
    
    if (clearSelectionBtn) {
        clearSelectionBtn.addEventListener('click', function() {
            selectedIds = [];
            rowCheckboxes.forEach(function(cb) { cb.checked = false; });
            if (selectAllCheckbox) selectAllCheckbox.checked = false;
            updateBatchBar();
        });
    }
    
    // 暴露 API
    window.BackendDataList = window.BackendDataList || {};
    window.BackendDataList[widgetId] = {
        getSelectedIds: function() { return selectedIds.slice(); },
        clearSelection: function() {
            if (clearSelectionBtn) clearSelectionBtn.click();
        }
    };
    
})();
});
