/**
 * B2B ControlCenter：拖拽客户组手柄调整显示排序（写入 tier_rank）。
 * 仅手柄可拖；松手在 dragend 自动 POST 保存（不依赖不可靠的 drop 事件）。
 */
(function () {
  'use strict';

  var panel = document.querySelector('[data-testid="b2b-groups-panel"]');
  var list = document.querySelector('[data-testid="b2b-groups-accordion"]');
  if (!panel || !list) {
    return;
  }

  var reorderUrl = panel.getAttribute('data-reorder-url') || '';
  var draggingCard = null;
  var startOrder = [];
  var persistInFlight = false;
  var lastPersistedKey = '';

  function asElement(node) {
    if (!node) {
      return null;
    }
    if (node.nodeType === 1) {
      return node;
    }
    return node.parentElement || null;
  }

  function formKey() {
    var el = document.querySelector('input[name="form_key"]');
    return el ? String(el.value || '') : '';
  }

  function targetScope() {
    var el = document.getElementById('b2b-work-scope');
    return el ? String(el.value || '') : '';
  }

  function cards() {
    return Array.prototype.slice.call(list.querySelectorAll('[data-testid="b2b-group-accordion"]'));
  }

  function orderedIds() {
    return cards()
      .map(function (card) {
        return card.getAttribute('data-group-id') || '';
      })
      .filter(Boolean);
  }

  function orderKey(ids) {
    return (ids || orderedIds()).join(',');
  }

  function setDropStyle(card, on) {
    if (!card) {
      return;
    }
    if (on) {
      card.style.outline = '2px solid var(--color-primary, var(--weline-theme-primary, currentColor))';
      card.style.outlineOffset = '2px';
    } else {
      card.style.outline = '';
      card.style.outlineOffset = '';
    }
  }

  function clearDropStyles() {
    cards().forEach(function (card) {
      setDropStyle(card, false);
    });
  }

  function applyLocalRanks() {
    cards().forEach(function (card, index) {
      card.setAttribute('data-tier-rank', String(index));
      var badge = card.querySelector('[data-testid="b2b-group-sort-badge"]');
      if (badge) {
        badge.textContent = '排序 ' + index;
      }
      var editBtn = card.querySelector('[data-testid="b2b-group-row-edit"]');
      if (editBtn) {
        editBtn.setAttribute('data-tier-rank', String(index));
      }
    });
  }

  function setSaveHint(text, tone) {
    var hint = panel.querySelector('[data-testid="b2b-groups-reorder-status"]');
    if (!hint) {
      hint = document.createElement('div');
      hint.className = 'w-text';
      hint.setAttribute('data-size', 'sm');
      hint.setAttribute('data-testid', 'b2b-groups-reorder-status');
      hint.setAttribute('role', 'status');
      hint.style.marginBlockStart = 'var(--weline-space-2)';
      var alert = panel.querySelector('.w-alert');
      if (alert && alert.parentNode) {
        alert.parentNode.insertBefore(hint, alert.nextSibling);
      } else {
        panel.insertBefore(hint, panel.firstChild);
      }
    }
    hint.setAttribute('data-tone', tone || 'muted');
    hint.textContent = text || '';
  }

  function persistOrder(reason) {
    if (!reorderUrl || persistInFlight) {
      return Promise.resolve({ skipped: true });
    }
    var ids = orderedIds();
    var key = orderKey(ids);
    if (key === lastPersistedKey && reason !== 'force') {
      return Promise.resolve({ skipped: true, unchanged: true });
    }
    persistInFlight = true;
    setSaveHint('正在保存排序…', 'muted');
    var body = new URLSearchParams();
    body.set('group_ids', JSON.stringify(ids));
    var fk = formKey();
    if (fk) {
      body.set('form_key', fk);
    }
    var scope = targetScope();
    if (scope) {
      body.set('target_scope', scope);
    }
    return fetch(reorderUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'text/html',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
      redirect: 'follow',
    })
      .then(function (res) {
        if (!res.ok && res.status >= 400) {
          throw new Error('reorder_http_' + res.status);
        }
        lastPersistedKey = key;
        applyLocalRanks();
        setSaveHint('排序已保存', 'success');
        return { ok: true };
      })
      .catch(function () {
        setSaveHint('排序保存失败，请刷新后重试', 'danger');
        window.alert('排序保存失败，请刷新后重试');
        return { ok: false };
      })
      .finally(function () {
        persistInFlight = false;
      });
  }

  function ensureHandleDraggable() {
    list.querySelectorAll('[data-testid="b2b-group-drag-handle"]').forEach(function (handle) {
      handle.setAttribute('draggable', 'true');
    });
    cards().forEach(function (card) {
      card.removeAttribute('draggable');
      card.draggable = false;
    });
  }

  ensureHandleDraggable();
  lastPersistedKey = orderKey();

  list.addEventListener('dragstart', function (event) {
    var target = asElement(event.target);
    if (!target || !list.contains(target)) {
      return;
    }
    if (target.closest('[data-testid="b2b-group-member-row"]')) {
      return;
    }
    var handle = target.closest('[data-testid="b2b-group-drag-handle"]');
    if (!handle) {
      event.preventDefault();
      return;
    }
    var card = handle.closest('[data-testid="b2b-group-accordion"]');
    if (!card || !list.contains(card)) {
      event.preventDefault();
      return;
    }
    draggingCard = card;
    startOrder = orderedIds();
    card.setAttribute('data-group-dragging', '1');
    card.style.opacity = '0.6';
    handle.style.cursor = 'grabbing';
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      try {
        event.dataTransfer.setData('text/plain', card.getAttribute('data-group-id') || 'group');
        event.dataTransfer.setData('application/x-b2b-group-reorder', card.getAttribute('data-group-id') || '');
      } catch (e) {
        // ignore
      }
    }
  });

  // 全程允许放置，否则多数浏览器不触发 drop
  list.addEventListener('dragover', function (event) {
    if (!draggingCard) {
      return;
    }
    event.preventDefault();
    if (event.dataTransfer) {
      event.dataTransfer.dropEffect = 'move';
    }
    var target = asElement(event.target);
    var over = target && target.closest ? target.closest('[data-testid="b2b-group-accordion"]') : null;
    if (!over || over === draggingCard || !list.contains(over)) {
      return;
    }
    clearDropStyles();
    setDropStyle(over, true);
    var rect = over.getBoundingClientRect();
    var before = event.clientY < rect.top + rect.height / 2;
    if (before) {
      list.insertBefore(draggingCard, over);
    } else if (over.nextSibling) {
      list.insertBefore(draggingCard, over.nextSibling);
    } else {
      list.appendChild(draggingCard);
    }
  });

  list.addEventListener('drop', function (event) {
    if (!draggingCard) {
      return;
    }
    event.preventDefault();
  });

  list.addEventListener('dragend', function (event) {
    var target = asElement(event.target);
    var handle = target && target.closest ? target.closest('[data-testid="b2b-group-drag-handle"]') : null;
    if (handle) {
      handle.style.cursor = 'grab';
    }
    clearDropStyles();
    if (draggingCard) {
      draggingCard.removeAttribute('data-group-dragging');
      draggingCard.style.opacity = '';
    }
    draggingCard = null;
    var now = orderedIds();
    if (orderKey(now) !== orderKey(startOrder)) {
      persistOrder('dragend');
    }
    startOrder = [];
  });
})();
