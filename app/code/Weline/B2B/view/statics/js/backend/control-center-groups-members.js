/**
 * B2B ControlCenter：客户组手风琴异步加载成员 + 搜索；拖放到其他组换组；加入客户弹窗填 group_id。
 */
(function () {
  'use strict';

  var panel = document.querySelector('[data-testid="b2b-groups-panel"]');
  if (!panel) {
    return;
  }

  var membersUrl = panel.getAttribute('data-members-url') || '';
  var removeUrl = panel.getAttribute('data-remove-url') || '';
  var assignUrl = panel.getAttribute('data-assign-url') || '';
  var assignGroupId = document.getElementById('b2b-group-assign-group-id');
  var assignLabel = document.getElementById('b2b-group-assign-label');
  var dragPayload = null;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formKey() {
    var el = document.querySelector('input[name="form_key"]');
    return el ? String(el.value || '') : '';
  }

  function findGroupCard(groupId) {
    if (!groupId) {
      return null;
    }
    var cards = panel.querySelectorAll('[data-testid="b2b-group-accordion"]');
    for (var i = 0; i < cards.length; i++) {
      if ((cards[i].getAttribute('data-group-id') || '') === String(groupId)) {
        return cards[i];
      }
    }
    return null;
  }

  function setDropActive(card, on) {
    if (!card) {
      return;
    }
    if (on) {
      card.setAttribute('data-drop-active', '1');
      card.style.outline = '2px solid var(--color-primary, var(--weline-theme-primary, currentColor))';
      card.style.outlineOffset = '2px';
    } else {
      card.removeAttribute('data-drop-active');
      card.style.outline = '';
      card.style.outlineOffset = '';
    }
  }

  function clearAllDropActive() {
    panel.querySelectorAll('[data-testid="b2b-group-accordion"][data-drop-active="1"]').forEach(function (card) {
      setDropActive(card, false);
    });
  }

  function renderMembers(listEl, groupId, members) {
    if (!listEl) {
      return;
    }
    if (!members || !members.length) {
      listEl.innerHTML =
        '<div class="w-text" data-tone="muted" data-testid="b2b-group-members-empty">' +
        esc('暂无成员') +
        '</div>' +
        '<div class="w-text" data-tone="muted" data-size="sm" data-testid="b2b-group-members-drag-hint">' +
        esc('可将其他组的成员拖到本卡片以换组') +
        '</div>';
      return;
    }
    var rows = members
      .map(function (m) {
        var cid = String(m.customer_id || '');
        var name = String(m.display_name || m.username || cid || '');
        var email = String(m.email || m.display_meta || '');
        var websiteName = String(
          m.website_name != null && m.website_name !== ''
            ? m.website_name
            : m.website_id != null
              ? m.website_id
              : ''
        );
        var secondary = [];
        if (cid) {
          secondary.push('#' + cid);
        }
        if (email && email.toLowerCase() !== name.toLowerCase()) {
          secondary.push(email);
        }
        var removeBtn = removeUrl
          ? '<form method="post" action="' +
            esc(removeUrl) +
            '" style="display:inline;">' +
            '<input type="hidden" name="group_id" value="' +
            esc(groupId) +
            '">' +
            '<input type="hidden" name="customer_id" value="' +
            esc(cid) +
            '">' +
            '<button type="submit" class="w-button" data-tone="danger" data-size="sm" data-testid="b2b-group-member-remove">' +
            esc('移出') +
            '</button></form>'
          : '';
        return (
          '<tr data-testid="b2b-group-member-row" draggable="true" data-customer-id="' +
          esc(cid) +
          '" data-source-group-id="' +
          esc(groupId) +
          '" style="cursor:grab;">' +
          '<td><div class="w-cluster" data-align="center" style="--w-gap:var(--weline-space-2);">' +
          '<span class="w-text" data-tone="muted" data-testid="b2b-group-member-drag-handle" title="' +
          esc('拖到其他客户组卡片以换组') +
          '" aria-hidden="true" style="cursor:grab; user-select:none;">⋮⋮</span>' +
          '<div class="w-stack" style="--w-gap:0.15rem;">' +
          '<span class="w-text" data-weight="strong" data-testid="b2b-group-member-name">' +
          esc(name) +
          '</span>' +
          (secondary.length
            ? '<span class="w-text" data-tone="muted" data-size="sm" data-testid="b2b-group-member-id">' +
              esc(secondary.join(' · ')) +
              '</span>'
            : '') +
          '</div></div></td>' +
          '<td><span data-testid="b2b-group-member-website">' +
          esc(websiteName) +
          '</span></td>' +
          '<td>' +
          esc(String(m.updated_at || '')) +
          '</td>' +
          '<td>' +
          removeBtn +
          '</td></tr>'
        );
      })
      .join('');
    listEl.innerHTML =
      '<div class="w-text" data-tone="muted" data-size="sm" data-testid="b2b-group-members-drag-hint" style="--w-mb:var(--weline-space-2);">' +
      esc('拖动成员行到其他客户组卡片可换组；「移出」仅解除本组关系。') +
      '</div>' +
      '<div class="w-table-wrap"><table class="w-table" data-w-vertical="middle" style="--w-mb:0;">' +
      '<thead><tr><th>' +
      esc('客户') +
      '</th><th>' +
      esc('站点') +
      '</th><th>' +
      esc('更新') +
      '</th><th>' +
      esc('操作') +
      '</th></tr></thead><tbody>' +
      rows +
      '</tbody></table></div>';
  }

  function loadMembers(card) {
    if (!card || !membersUrl) {
      return;
    }
    var details = card.querySelector('[data-testid="b2b-group-accordion-details"]');
    if (!details || !details.open) {
      return;
    }
    var groupId = card.getAttribute('data-group-id') || '';
    var body = card.querySelector('[data-testid="b2b-group-members-panel"]');
    var listEl = card.querySelector('[data-testid="b2b-group-members-list"]');
    var qInput = card.querySelector('[data-testid="b2b-group-members-q"]');
    if (!body || !listEl || !groupId) {
      return;
    }
    var q = qInput ? String(qInput.value || '').trim() : '';
    listEl.innerHTML = '<div class="w-text" data-tone="muted">' + esc('加载中…') + '</div>';
    var url = membersUrl + (membersUrl.indexOf('?') >= 0 ? '&' : '?') + 'group_id=' + encodeURIComponent(groupId);
    if (q) {
      url += '&q=' + encodeURIComponent(q);
    }
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          listEl.innerHTML =
            '<div class="w-alert" data-tone="warning">' + esc((data && data.error) || '加载失败') + '</div>';
          return;
        }
        body.setAttribute('data-loaded', '1');
        renderMembers(listEl, groupId, data.members || []);
      })
      .catch(function () {
        listEl.innerHTML = '<div class="w-alert" data-tone="warning">' + esc('加载失败') + '</div>';
      });
  }

  function moveMemberToGroup(customerId, sourceGroupId, targetGroupId) {
    if (!assignUrl || !customerId || !targetGroupId) {
      return Promise.reject(new Error('missing'));
    }
    if (sourceGroupId === targetGroupId) {
      return Promise.resolve({ skipped: true });
    }
    var body = new URLSearchParams();
    body.set('group_id', targetGroupId);
    body.set('customer_id', customerId);
    var key = formKey();
    if (key) {
      body.set('form_key', key);
    }
    return fetch(assignUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'text/html',
      },
      body: body.toString(),
      redirect: 'follow',
    }).then(function (res) {
      if (!res.ok && res.status >= 400) {
        throw new Error('assign_failed');
      }
      var sourceCard = findGroupCard(sourceGroupId);
      var targetCard = findGroupCard(targetGroupId);
      if (sourceCard) {
        loadMembers(sourceCard);
      }
      if (targetCard) {
        var details = targetCard.querySelector('[data-testid="b2b-group-accordion-details"]');
        if (details && !details.open) {
          details.open = true;
        } else {
          loadMembers(targetCard);
        }
      }
      return { ok: true };
    });
  }

  document.querySelectorAll('[data-testid="b2b-group-accordion"]').forEach(function (card) {
    var details = card.querySelector('[data-testid="b2b-group-accordion-details"]');
    if (details) {
      details.addEventListener('toggle', function () {
        if (!details.open) {
          return;
        }
        loadMembers(card);
      });
    }
    var qInput = card.querySelector('[data-testid="b2b-group-members-q"]');
    if (qInput) {
      var timer = null;
      qInput.addEventListener('input', function () {
        if (!details || !details.open) {
          return;
        }
        clearTimeout(timer);
        timer = setTimeout(function () {
          loadMembers(card);
        }, 280);
      });
    }
  });

  document.addEventListener(
    'click',
    function (event) {
      var target = event.target;
      if (!target || !target.closest) {
        return;
      }
      var btn = target.closest('[data-testid="b2b-group-members-assign-open"]');
      if (!btn) {
        return;
      }
      if (assignGroupId) {
        assignGroupId.value = btn.getAttribute('data-group-id') || '';
      }
      if (assignLabel) {
        assignLabel.textContent = btn.getAttribute('data-group-label') || '';
      }
    },
    true
  );

  panel.addEventListener('dragstart', function (event) {
    var row = event.target && event.target.closest ? event.target.closest('[data-testid="b2b-group-member-row"]') : null;
    if (!row || !panel.contains(row)) {
      return;
    }
    var cid = row.getAttribute('data-customer-id') || '';
    var sourceGroupId = row.getAttribute('data-source-group-id') || '';
    if (!cid) {
      event.preventDefault();
      return;
    }
    dragPayload = { customer_id: cid, source_group_id: sourceGroupId };
    row.setAttribute('data-dragging', '1');
    row.style.opacity = '0.55';
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', cid);
    }
  });

  panel.addEventListener('dragend', function (event) {
    var row = event.target && event.target.closest ? event.target.closest('[data-testid="b2b-group-member-row"]') : null;
    if (row) {
      row.removeAttribute('data-dragging');
      row.style.opacity = '';
    }
    dragPayload = null;
    clearAllDropActive();
  });

  panel.addEventListener('dragover', function (event) {
    if (!dragPayload) {
      return;
    }
    var card = event.target && event.target.closest ? event.target.closest('[data-testid="b2b-group-accordion"]') : null;
    if (!card || !panel.contains(card)) {
      return;
    }
    var targetGroupId = card.getAttribute('data-group-id') || '';
    if (!targetGroupId || targetGroupId === dragPayload.source_group_id) {
      return;
    }
    event.preventDefault();
    if (event.dataTransfer) {
      event.dataTransfer.dropEffect = 'move';
    }
    clearAllDropActive();
    setDropActive(card, true);
  });

  panel.addEventListener('dragleave', function (event) {
    var card = event.target && event.target.closest ? event.target.closest('[data-testid="b2b-group-accordion"]') : null;
    if (!card || !panel.contains(card)) {
      return;
    }
    var related = event.relatedTarget;
    if (related && card.contains(related)) {
      return;
    }
    setDropActive(card, false);
  });

  panel.addEventListener('drop', function (event) {
    if (!dragPayload) {
      return;
    }
    var card = event.target && event.target.closest ? event.target.closest('[data-testid="b2b-group-accordion"]') : null;
    if (!card || !panel.contains(card)) {
      return;
    }
    event.preventDefault();
    var targetGroupId = card.getAttribute('data-group-id') || '';
    var payload = dragPayload;
    dragPayload = null;
    clearAllDropActive();
    if (!targetGroupId || targetGroupId === payload.source_group_id) {
      return;
    }
    card.setAttribute('data-drop-busy', '1');
    moveMemberToGroup(payload.customer_id, payload.source_group_id, targetGroupId)
      .catch(function () {
        window.alert('换组失败，请重试');
      })
      .finally(function () {
        card.removeAttribute('data-drop-busy');
      });
  });
})();
