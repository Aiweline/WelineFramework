/**
 * B2B ControlCenter 客户组：编辑弹窗填表 + 元→分提交；Local 仅作旁侧翻译触发。
 */
(function () {
  'use strict';

  var dialog = document.getElementById('b2b-group-edit-dialog');
  var form = document.getElementById('b2b-group-edit-form');
  if (!dialog || !form) {
    return;
  }

  var groupIdInput = document.getElementById('b2b-group-edit-group-id');
  var nameInput = document.getElementById('b2b-group-edit-name');
  var descriptionInput = document.getElementById('b2b-group-edit-description');
  var nameHost = document.getElementById('b2b-group-edit-name-local-host');
  var descriptionHost = document.getElementById('b2b-group-edit-description-local-host');
  var vault = document.getElementById('b2b-group-local-vault');
  var creditYuanInput = document.getElementById('b2b-group-edit-credit-yuan');
  var spendYuanInput = document.getElementById('b2b-group-edit-spend-yuan');
  var creditMinorInput = document.getElementById('b2b-group-edit-credit-minor');
  var spendMinorInput = document.getElementById('b2b-group-edit-spend-minor');
  var statusSelect = document.getElementById('b2b-group-edit-status');
  var tierRankInput = document.getElementById('b2b-group-edit-tier-rank');
  var codeLabel = document.getElementById('b2b-group-edit-code-label');
  var typeLabel = document.getElementById('b2b-group-edit-type-label');
  var activePack = null;

  function yuanFromMinor(minor) {
    var n = Number(minor);
    if (!Number.isFinite(n) || n < 0) {
      n = 0;
    }
    return (Math.round(n) / 100).toFixed(2);
  }

  function minorFromYuan(raw) {
    var n = Number(String(raw == null ? '' : raw).trim());
    if (!Number.isFinite(n) || n < 0) {
      n = 0;
    }
    return Math.round(n * 100);
  }

  function returnActivePackSlots() {
    if (!activePack) {
      return;
    }
    if (activePack._nameSlot) {
      activePack.appendChild(activePack._nameSlot);
    }
    if (activePack._descSlot) {
      activePack.appendChild(activePack._descSlot);
    }
    activePack._nameSlot = null;
    activePack._descSlot = null;
    activePack = null;
  }

  function mountLocalPack(groupId, rowId) {
    returnActivePackSlots();
    if (!vault || !nameHost || !descriptionHost) {
      return;
    }
    nameHost.textContent = '';
    descriptionHost.textContent = '';
    var pack = null;
    var packs = vault.querySelectorAll('[data-b2b-group-local-pack]');
    for (var i = 0; i < packs.length; i++) {
      var item = packs[i];
      if (
        (groupId && item.getAttribute('data-group-id') === groupId) ||
        (rowId && item.getAttribute('data-group-row-id') === String(rowId))
      ) {
        pack = item;
        break;
      }
    }
    if (!pack) {
      nameHost.innerHTML = '<span class="w-text" data-tone="muted" data-size="sm">保存后可翻译</span>';
      descriptionHost.innerHTML = '<span class="w-text" data-tone="muted" data-size="sm">保存后可翻译</span>';
      return;
    }
    var nameSlot = pack.querySelector('[data-local-slot="name"]');
    var descSlot = pack.querySelector('[data-local-slot="description"]');
    if (nameSlot) {
      nameHost.appendChild(nameSlot);
    }
    if (descSlot) {
      descriptionHost.appendChild(descSlot);
    }
    activePack = pack;
    activePack._nameSlot = nameSlot;
    activePack._descSlot = descSlot;
  }

  function fillFromButton(btn) {
    if (!btn || !btn.getAttribute) {
      return;
    }
    var gid = btn.getAttribute('data-group-id') || '';
    var rowId = btn.getAttribute('data-group-row-id') || '';
    var name = btn.getAttribute('data-name') || '';
    var code = btn.getAttribute('data-code') || '';
    var description = btn.getAttribute('data-description') || '';
    var creditMinor = btn.getAttribute('data-credit-minor') || '0';
    var spendMinor = btn.getAttribute('data-spend-minor') || '0';
    var status = btn.getAttribute('data-status') || 'active';
    var tierRank = btn.getAttribute('data-tier-rank');
    var typeText = btn.getAttribute('data-type-label') || '';

    returnActivePackSlots();
    if (groupIdInput) groupIdInput.value = gid;
    if (nameInput) nameInput.value = name;
    if (descriptionInput) descriptionInput.value = description;
    if (creditYuanInput) creditYuanInput.value = yuanFromMinor(creditMinor);
    if (spendYuanInput) spendYuanInput.value = yuanFromMinor(spendMinor);
    if (creditMinorInput) creditMinorInput.value = String(Math.max(0, Math.round(Number(creditMinor) || 0)));
    if (spendMinorInput) spendMinorInput.value = String(Math.max(0, Math.round(Number(spendMinor) || 0)));
    if (statusSelect) statusSelect.value = status === 'disabled' ? 'disabled' : 'active';
    if (tierRankInput) {
      tierRankInput.value = tierRank == null || tierRank === '' ? '0' : String(tierRank);
    }
    if (codeLabel) codeLabel.textContent = code ? ('代码 ' + code) : '';
    if (typeLabel) typeLabel.textContent = typeText;
    mountLocalPack(gid, rowId);
  }

  document.addEventListener(
    'click',
    function (event) {
      var target = event.target;
      if (!target || !target.closest) {
        return;
      }
      var btn = target.closest('[data-testid="b2b-group-row-edit"]');
      if (!btn) {
        return;
      }
      fillFromButton(btn);
    },
    true
  );

  form.addEventListener('submit', function () {
    if (creditMinorInput && creditYuanInput) {
      creditMinorInput.value = String(minorFromYuan(creditYuanInput.value));
    }
    if (spendMinorInput && spendYuanInput) {
      spendMinorInput.value = String(minorFromYuan(spendYuanInput.value));
    }
  });

  dialog.addEventListener('close', function () {
    returnActivePackSlots();
  });
})();
