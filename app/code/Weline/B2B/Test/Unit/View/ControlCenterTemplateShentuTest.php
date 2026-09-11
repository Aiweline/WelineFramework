<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * 审图契约：B2B ControlCenter 运营面去手填 ID + VIP 组。
 */
final class ControlCenterTemplateShentuTest extends TestCase
{
    public function testWritableWorkspaceTemplateHasHumanFormAndEmptyState(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('data-testid="b2b-<?= $escape($code) ?>-form-actions"', $content);
        self::assertStringContainsString("__('新增客户组')", $content);
        self::assertStringContainsString("__('客户组名称')", $content);
        self::assertStringContainsString("><?= __('启用') ?></option>", $content);
        self::assertStringContainsString("><?= __('停用') ?></option>", $content);
        self::assertStringContainsString('class="w-empty"', $content);
        self::assertStringContainsString("__('暂无客户组。请在上方填写信息后保存。')", $content);
        self::assertStringContainsString("__('顶部切换作用范围过滤本页列表。系统已预置 VIP 客户组（可改名称，不可删除）。列表可浏览；点「编辑」改名称、说明、达标折扣额度、消费门槛与启停；展开分组可搜索管理成员。达标折扣额度与消费门槛按站点默认基准货币配置：消费达标升入本档后，按折扣额度向批发信用钱包补差额（仅可抵扣批发订单）。其它客户组可自行新增。')", $content);

        self::assertStringNotContainsString('B2B rollout 门禁', $content);
        self::assertStringNotContainsString('候选解析', $content);
        self::assertStringNotContainsString('<option value="active">active</option>', $content);
        self::assertStringNotContainsString("__('客户组 ID')", $content);
        self::assertStringNotContainsString("__('价目表 ID')", $content);
        self::assertStringNotContainsString("__('客户 ID')", $content);
    }

    public function testApplicationsAndGroupsUseTagSearchAndVipActions(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('<w:theme:search-select', $content);
        self::assertStringContainsString('<w:customer:admin:select', $content);
        self::assertStringContainsString('data-testid="b2b-groups-accordion"', $content);
        self::assertStringContainsString('data-testid="b2b-group-accordion"', $content);
        self::assertStringContainsString('data-testid="b2b-group-accordion-details"', $content);
        self::assertStringContainsString('data-testid="b2b-group-row-edit"', $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-dialog"', $content);
        self::assertStringContainsString('data-testid="b2b-group-assign-dialog"', $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-name-local"', $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-description-local"', $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-name"', $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-description"', $content);
        self::assertStringContainsString('CustomerGroupRecord\\LocalDescription', $content);
        self::assertStringContainsString("__('源语言可在此直接改；旁侧「翻译」打开各语种（LocalModel）。')", $content);
        self::assertStringContainsString('data-testid="b2b-group-edit-tier-rank"', $content);
        self::assertStringContainsString('data-testid="b2b-group-drag-handle"', $content);
        self::assertStringContainsString('data-reorder-url=', $content);
        self::assertStringContainsString('control-center-groups-reorder.js', $content);
        self::assertStringContainsString('data-testid="b2b-groups-base-currency"', $content);
        self::assertStringContainsString('data-testid="b2b-group-members-panel"', $content);
        self::assertStringContainsString("__('搜索姓名、邮箱或编号')", $content);
        self::assertStringContainsString('id="b2b-group-assign-customer"', $content);
        self::assertStringContainsString("__('编辑客户组')", $content);
        self::assertStringContainsString('当前默认基准货币', $content);
        self::assertStringContainsString("__('系统 VIP 不可删除')", $content);
        self::assertStringNotContainsString('data-testid="b2b-group-row-rename"', $content);
        self::assertStringNotContainsString("__('保存能力')", $content);
        self::assertStringNotContainsString("__('目标额度（元）')", $content);
        self::assertStringNotContainsString("__('目标额度（%{1}）'", $content);
        self::assertStringContainsString("__('达标折扣额度（%{1}）'", $content);
        self::assertStringContainsString("__('达标折扣额度')", $content);
        self::assertStringContainsString("__('消费门槛')", $content);
        self::assertStringContainsString('消费达到本档门槛后升入本档', $content);
        self::assertStringNotContainsString('b2b-group-member-add-', $content);
        self::assertStringContainsString('data-testid="b2b-hang-row-approve"', $content);
        self::assertStringContainsString('data-testid="b2b-membership-row-approve"', $content);

        self::assertStringNotContainsString('data-testid="b2b-membership-approve-id"', $content);
        self::assertStringNotContainsString('data-testid="b2b-hang-approve-key"', $content);
        self::assertStringNotContainsString("__('申请单 ID')", $content);
    }

    public function testGroupMembersJsShowsReadableCustomerPrimaryAndMutedId(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/statics/js/backend/control-center-groups-members.js';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('display_name', $content);
        self::assertStringContainsString('b2b-group-member-name', $content);
        self::assertStringContainsString('b2b-group-member-id', $content);
        self::assertStringContainsString('website_name', $content);
        self::assertStringNotContainsString("'<td><code>' +\n          esc(cid)", $content);
    }

    public function testGroupMembersJsSupportsDragMoveBetweenGroups(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/statics/js/backend/control-center-groups-members.js';
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('draggable="true"', $content);
        self::assertStringContainsString('b2b-group-member-drag-handle', $content);
        self::assertStringContainsString('data-assign-url', $content);
        self::assertStringContainsString('moveMemberToGroup', $content);
        self::assertStringContainsString('data-drop-active', $content);
        self::assertStringContainsString('拖到其他客户组卡片', $content);
    }

    public function testControlCenterUsesOfficialWebsiteStoreChannelScopeToolbar(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml';
        $content = (string) file_get_contents($path);
        $toolbar = (string) file_get_contents(BP . 'app/code/Weline/B2B/view/templates/Backend/partials/scope-toolbar.phtml');

        self::assertStringContainsString("Weline_B2B::templates/Backend/partials/scope-toolbar.phtml", $content);
        self::assertStringContainsString('data-testid="b2b-work-scope-toolbar"', $toolbar);
        self::assertStringContainsString('<w:scope', $toolbar);
        self::assertStringContainsString('id="b2b-work-scope"', $toolbar);
        self::assertStringContainsString("__('作用范围')", $toolbar);
        self::assertStringContainsString('control-center-work-scope.js', $toolbar);
        self::assertStringContainsString("__('顶部切换网站 / 店铺 / 渠道作用范围过滤整页列表。待批行可批准/驳回；生效中可撤销；撤销后可选组重新授权（申请审计仍保留 approved）。有权限时可删除申请记录。')", $content);
        self::assertStringContainsString('data-testid="b2b-membership-row-revoke"', $content);
        self::assertStringContainsString("__('快速撤销（可多选站点）')", $content);
        self::assertStringContainsString('name="website_ids"', $content);
        self::assertStringContainsString('id="b2b-cc-revoke-website"', $content);
        self::assertStringContainsString('multiple="true"', $content);

        self::assertStringNotContainsString('data-testid="b2b-applications-scope"', $content);
        self::assertStringNotContainsString('id="b2b-applications-scope-website"', $content);
        self::assertStringNotContainsString("__('站点范围')", $content);
        self::assertStringNotContainsString('撤销请在页底搜索客户', $content);
    }

    public function testApprovedRowUsesEntitlementProjectionForRevokeUi(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString("entitlement_active", $content);
        self::assertStringContainsString("__('资格已撤销')", $content);
        self::assertStringContainsString('data-testid="b2b-membership-entitlement-revoked"', $content);
        self::assertStringContainsString('$entitlementActive', $content);
        self::assertStringContainsString('$appStatus === \'approved\' && $entitlementActive', $content);
        self::assertStringContainsString('data-testid="b2b-membership-row-reauthorize"', $content);
        self::assertStringContainsString("__('重新授权')", $content);
        self::assertStringContainsString('reauthorize_action', $content);
    }

    public function testApplicationsDeleteButtonWrappedByAclTag(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('delete_application_action', $content);
        self::assertStringContainsString('Weline_B2B::commerce:partner:applications:delete', $content);
        self::assertStringContainsString('<acl source="<?= $escape($deleteApplicationAclSource) ?>">', $content);
        self::assertStringContainsString('data-testid="b2b-membership-row-delete"', $content);
        self::assertStringContainsString("__('删除')", $content);
        self::assertStringContainsString("__('确认删除该身份申请记录？此操作不可恢复，不会自动撤销已生效的批发资格。')", $content);
        self::assertStringContainsString('有权限时可删除申请记录', $content);
    }
}
