# PageBuilder 第二阶段强契约并发构建流程图

本文只保留一张第二阶段总图。第二阶段从第一阶段 confirmed `plan_json` 和确认后固化的 `build_plan_v2` 读取 JSON 树，先冻结任务定义与任务级上下文，再实时更新前端任务进度面板，最后按强契约并发执行构建。生成提示词时只能读取 `build_blueprint.tasks[].runtime_context`，禁止再从大 scope 做兜底回退。

```mermaid
graph TD
    START["启动 Stage2 Build"] --> LOAD["读取 confirmed plan_json + build_plan_v2"]
    LOAD --> CHECK_CONTRACT["检查第一阶段合同与 build_plan_v2 是否已固化"]
    CHECK_CONTRACT --> BACK_STAGE1["否：跳回第一阶段确认方案与合同"]
    CHECK_CONTRACT --> CONTRACT_GATE["G1 合同准入门禁：confirmed plan_json / build_plan_v2 / source_of_truth 必须完整"]
    CONTRACT_GATE --> CONTRACT_GATE_FAIL["G1 fail：合同缺失或结构错误"]
    CONTRACT_GATE_FAIL --> BACK_STAGE1
    CONTRACT_GATE --> READ_TREE["G1 pass：读取 build_plan_v2 JSON 树"]

    READ_TREE --> LOAD_CONTEXT["冻结 Stage2 运行上下文"]
    LOAD_CONTEXT --> PARSE_TASKS["解析 build_plan_v2.tasks / pages / blocks / build_order"]
    PARSE_TASKS --> SAVE_TASKS["写入任务定义、排序、依赖、result_ref、context_refs"]
    SAVE_TASKS --> TASK_TREE["生成任务树"]
    TASK_TREE --> TASK_DEF_STORE["任务定义存 build_blueprint.tasks"]
    TASK_DEF_STORE --> TASK_STATE["初始化任务运行态"]
    TASK_STATE --> TASK_STATE_MODEL["状态字段：status attempt_no reason retryable result_ref started_at finished_at updated_at"]
    TASK_STATE_MODEL --> TASK_STATE_STORE["运行态存 scope.build_tasks"]
    TASK_STATE_STORE --> TASK_TREE_GATE["G2 任务树门禁：覆盖率、依赖、顺序、result_ref、runtime_context 完整"]
    TASK_TREE_GATE --> TASK_GATE_FAIL["G2 fail：任务树不可执行"]
    TASK_GATE_FAIL --> BUILD_FAILED
    TASK_TREE_GATE --> TASK_PANEL["G2 pass：推送前端任务面板"]

    TASK_PANEL --> BUILD_ACTION["判断 Build 类型"]
    BUILD_ACTION --> FULL_REBUILD["第二阶段重建：用户确认从头生成"]
    FULL_REBUILD --> STOP_QUEUE["停止旧 running / queued Build 队列，调用 w_query 停止队列"]
    STOP_QUEUE --> CLEAR_BUILD["清空 Stage2 旧产物和任务状态"]
    CLEAR_BUILD --> CLEAR_TASK_STATE["清理 build_tasks retryable_ai_failures gate_ledger build_summary active_operation"]
    CLEAR_TASK_STATE --> CLEAR_STORE["清理 virtual_pages_by_type page_type_layouts shared_components build_contracts qa_report_contract publish 状态"]
    CLEAR_STORE --> CLEAR_ASSET_STATE["资产状态处理：保留 locked_by_user，旧生成资产标 stale，verified_assets 重新计算"]
    CLEAR_ASSET_STATE --> STAGE2_RESTART["回到 Stage2 起点：重新解析 build_plan_v2.tasks 并初始化任务树"]
    STAGE2_RESTART --> LOAD_CONTEXT

    BUILD_ACTION --> RETRY_FAILED["重试失败任务：用户点击重试失败"]
    RETRY_FAILED --> RETRY_SCAN["读取 build_tasks 和 retryable_ai_failures"]
    RETRY_SCAN --> RETRY_RESET["只把 failed interrupted missing_result stale running 设为 pending"]
    RETRY_RESET --> RETRY_KEEP["保留 done 任务、result_ref、已生成产物和 verified_assets"]
    RETRY_KEEP --> RETRY_QUEUE_DETAILS["创建新 Build 队列 details，写入 resume_failed_tasks 或 fresh_repair_failed_tasks"]

    BUILD_ACTION --> CONTINUE_BUILD["继续构建：对账已有产物"]
    CONTINUE_BUILD --> RECONCILE["已有 artifact 标记 done，中断 running 归 pending"]

    RETRY_QUEUE_DETAILS --> QUEUE_CHECK["检查可复用 Build 队列"]
    RECONCILE --> QUEUE_CHECK
    QUEUE_CHECK --> QUEUE_GATE["G3 队列准入门禁：queue_id execution_token build_type operation 状态一致"]
    QUEUE_CHECK --> CREATE_QUEUE["无可复用队列则创建新队列"]
    CREATE_QUEUE --> QUEUE_GATE
    QUEUE_GATE --> QUEUE_GATE_FAIL["G3 fail：队列身份或状态不可恢复"]
    QUEUE_GATE_FAIL --> BUILD_FAILED
    QUEUE_GATE --> BUILD_STREAM["G3 pass：运行或复用 Build 队列"]

    BUILD_STREAM --> ASSET_PREFLIGHT["图片资产预检和预生成"]
    ASSET_PREFLIGHT --> ASSET_SLOT_DISCOVER["从 task.runtime_context.asset_context 与任务合同提取图片需求"]
    ASSET_SLOT_DISCOVER --> ASSET_SLOT_BUILD["生成或复用稳定 slot_id：page_type + block_key + image_role"]
    ASSET_SLOT_BUILD --> ASSET_MANIFEST_UPSERT["upsert asset_manifest slots：slot_id slot_type page_type block_key label prompt_brief status final_url locked_by_user"]
    ASSET_MANIFEST_UPSERT --> ASSET_MANIFEST_GATE["G4 资产清单门禁：slot_id 唯一、page scoped、prompt_brief 可生成"]
    ASSET_MANIFEST_GATE --> ASSET_FAIL
    ASSET_MANIFEST_GATE --> ASSET_SLOT_STATUS["G4 pass：判断 slot 状态：locked / ready / pending / failed"]
    ASSET_SLOT_STATUS --> ASSET_REUSE["已有 final_url 且未 stale：复用"]
    ASSET_SLOT_STATUS --> ASSET_SKIP_LOCKED["locked_by_user：保留用户图，不进入生成队列"]
    ASSET_SLOT_STATUS --> ASSET_QUEUE["待生成或失败：创建 image_asset 图片队列任务"]
    ASSET_QUEUE --> ASSET_QUEUE_PAYLOAD["图片队列 payload：public_id slot_id prompt_brief slot_type page_type execution_token"]
    ASSET_QUEUE_PAYLOAD --> CONTENT_GATE_BEFORE_IMAGE["G5 图片前内容门禁：inspectContentGate 必须通过"]
    CONTENT_GATE_BEFORE_IMAGE --> ASSET_FAIL
    CONTENT_GATE_BEFORE_IMAGE --> ASSET_GENERATE["G5 pass：图片模型按 slot prompt 生成图片"]
    ASSET_GENERATE --> ASSET_FILE["写入物理文件 pub/media/page-build/ai-generated"]
    ASSET_FILE --> ASSET_RECORD["recordGenerated 回写 asset_manifest：final_url status variants"]
    ASSET_REUSE --> ASSET_VERIFIED["extractVerifiedAssets 生成 verified_assets：slot_id 到 final_url"]
    ASSET_SKIP_LOCKED --> ASSET_VERIFIED
    ASSET_RECORD --> ASSET_VERIFIED
    ASSET_VERIFIED --> ASSET_STORE["资产写入 scope.asset_manifest 和 scope.verified_assets"]
    ASSET_STORE --> ASSET_URL["每个图片 slot 都有明确 final_url，组件生成只能使用这些 URL"]
    ASSET_URL --> ASSET_OK["资产可用：进入调度"]
    ASSET_GENERATE --> ASSET_FAIL["资产失败：slot 记录 failed reason，相关任务 failed 并写 ledger"]
    ASSET_OK --> SCHEDULE["选择下一批并发任务"]
    ASSET_FAIL --> RETRYABLE_LEDGER

    SCHEDULE --> HAS_TASK["判断是否还有 pending 任务"]
    HAS_TASK --> FINALIZE["没有 pending：进入收尾"]
    HAS_TASK --> DEP_RULE["有 pending：应用依赖和顺序规则"]
    DEP_RULE --> TASK_BATCH["本批任务标记 running 并推送前端"]

    TASK_BATCH --> TASK_TYPE["判断任务类型"]
    TASK_TYPE --> SHARED_TASK["shared header / footer 任务"]
    TASK_TYPE --> BLOCK_TASK["page block 任务"]
    SHARED_TASK --> PROMPT_CTX["组装单任务提示词上下文"]
    BLOCK_TASK --> PROMPT_CTX

    PROMPT_CTX --> CTX_BASE["1 底座提示词：执行规则和输出 schema"]
    CTX_BASE --> CTX_BASE_DETAIL["底座只包含构建规则：禁止重新规划、输出 JSON schema、HTML/CSS 范围、响应式、质量线"]
    CTX_BASE_DETAIL --> CTX_TASK["2 绑定当前 build_blueprint task"]
    CTX_TASK --> CTX_TASK_DETAIL["任务来源：task.plan_context / task.task_script / task.block_task / task.implementation_contract"]
    CTX_TASK_DETAIL --> CTX_REFS["3 解析 task.runtime_context.context_refs"]
    CTX_REFS --> CTX_SITE["4 组装全站上下文"]
    CTX_SITE --> CTX_SITE_DETAIL["全站来源：task.runtime_context.site_context / theme_context_snapshot / shared_prompt_context / policy_context / skill_context / reference_context"]
    CTX_SITE_DETAIL --> CTX_SITE_RULE["全站合并规则：Stage1 confirmed 快照优先，不读取会话历史，不重新解释用户需求"]
    CTX_SITE_RULE --> CTX_PAGE["5 组装当前页面上下文"]
    CTX_PAGE --> CTX_PAGE_SOURCE["页面来源：plan_context.page_type / page_goal / page_design_plan / page_flow_role / content_boundary"]
    CTX_PAGE_SOURCE --> CTX_PAGE_MERGE["页面合并：全站主题约束加当前页面职责，只允许收窄到当前页面，不允许改变页面结构"]
    CTX_PAGE_MERGE --> CTX_BRANCH["判断是否 Block 任务"]
    CTX_BRANCH --> CTX_BLOCK["6A 当前 Block 执行上下文"]
    CTX_BRANCH --> CTX_SHARED["6B Header Footer 上下文"]
    CTX_BLOCK --> CTX_BLOCK_DETAIL["Block 来源：plan_context.block_goal / block_task.content_plan / style_plan / implementation_slices / responsive_contract"]
    CTX_SHARED --> CTX_SHARED_DETAIL["Shared 来源：shared contract、导航、CTA、页脚链接、全站视觉与交互约束"]
    CTX_BLOCK_DETAIL --> CTX_ASSET["7 组装执行资源"]
    CTX_SHARED_DETAIL --> CTX_ASSET
    CTX_ASSET --> CTX_ASSET_DETAIL["资源来源：task.runtime_context.asset_context + verified_assets + asset_manifest slots + final_url"]
    CTX_ASSET_DETAIL --> CTX_SLOT_MATCH["按当前 task 的 page_type / block_key / image_role 匹配 required slot_id"]
    CTX_SLOT_MATCH --> CTX_SLOT_TEMPLATE["把匹配到的 slot_id 和 final_url 转成可复制图片模板"]
    CTX_SLOT_TEMPLATE --> CTX_SLOT_RULE["图片模板规则：img 必须同时带 src data-pb-ai-image-role data-pb-ai-asset-slot"]
    CTX_SLOT_RULE --> PROMPT_GATE["G6 Prompt 门禁：底座版本、task.runtime_context、token 预算、资产 allowlist、输出 schema 完整"]
    PROMPT_GATE --> PROMPT_GATE_FAIL["G6 fail：任务 failed，reason=prompt_context_invalid"]
    PROMPT_GATE_FAIL --> TASK_FAIL
    PROMPT_GATE --> CTX_FINAL_RULE["G6 pass：只生成当前 Header / Footer 或当前 Block，不解释 why，不新增 Block，不改页面结构"]
    CTX_FINAL_RULE --> PROMPT_READY["形成单任务 prompt：只生成当前任务"]

    PROMPT_READY --> AI_GENERATE["AI 生成组件 JSON"]
    AI_GENERATE --> AI_FAIL["调用失败或超时"]
    AI_GENERATE --> TASK_VALIDATE["返回 JSON 后逐任务验证"]
    TASK_VALIDATE --> SCHEMA_GATE["G7 组件结构门禁：JSON schema html_content css_extra block_id type 完整"]
    SCHEMA_GATE --> VALID_FAIL
    SCHEMA_GATE --> CONTENT_VISUAL_GATE["G8 内容视觉门禁：无内部字段、无 demo 文案、语言正确、主题正确、响应式正确"]
    CONTENT_VISUAL_GATE --> VALID_FAIL
    CONTENT_VISUAL_GATE --> IMAGE_SLOT_VALIDATE["G9 图片插槽门禁：required slot 必须在 HTML 中使用对应 final_url"]
    IMAGE_SLOT_VALIDATE --> VALID_FAIL["验证失败：任务 failed"]
    IMAGE_SLOT_VALIDATE --> SAVE_ARTIFACT["验证通过：保存组件产物"]
    AI_FAIL --> TASK_FAIL["统一写 failed 字段和 ledger"]
    VALID_FAIL --> TASK_FAIL
    SAVE_ARTIFACT --> ARTIFACT_SLOT_BIND["保存组件的 asset_slots used_slot_ids 和 final_url refs"]
    ARTIFACT_SLOT_BIND --> ARTIFACT_GATE["G10 写回门禁：page_order block_order result_ref artifact_id 可追踪"]
    ARTIFACT_GATE --> VALID_FAIL
    ARTIFACT_GATE --> ARTIFACT_STORE["G10 pass：产物写入 virtual_pages_by_type 页面块和 shared_components 共享组件"]
    ARTIFACT_STORE --> ARTIFACT_REF["任务 result_ref 记录 page_type section_code region artifact_id"]
    ARTIFACT_REF --> UPDATE_LAYOUT["按 page_order 和 block_order 更新 layout"]
    UPDATE_LAYOUT --> TASK_DONE["任务 done，记录 artifact_id"]
    TASK_DONE --> NEXT_BATCH["回到调度器选择下一批任务"]
    TASK_FAIL --> RETRYABLE_LEDGER["写 retryable_ai_failures.build：task_key slot_id reason gate_key retryable"]
    RETRYABLE_LEDGER --> TASK_FAIL_STATE["build_tasks 标 failed，本轮不立即重试"]
    TASK_FAIL_STATE --> NEXT_BATCH
    NEXT_BATCH --> SCHEDULE

    FINALIZE --> PAGE_QA["G11 整页门禁 inspectScope：任务完成、覆盖率、可追踪、内容、主题、图片、语言、响应式"]
    PAGE_QA --> QA_FAIL["QA 失败：页面或任务 failed，can_publish=0"]
    PAGE_QA --> BUILD_SUMMARY["QA 通过或失败后生成 summary"]
    QA_FAIL --> BUILD_SUMMARY
    BUILD_SUMMARY --> PUBLISH_GATE["G12 发布门禁：刷新 qa_report_contract 后检查 can_publish 和发布 checklist"]
    PUBLISH_GATE --> CAN_PUBLISH["判断是否全部成功且发布门禁通过"]
    CAN_PUBLISH --> BUILD_FAILED["否：workspace failed，显示重试入口"]
    CAN_PUBLISH --> BUILD_READY["是：workspace can_publish，进入预览发布"]

    TASK_PANEL --> VBLOCK_ADJUST["用户调整虚拟主题已生成 Block"]
    VBLOCK_ADJUST --> VBLOCK_READ["读取当前生成块：virtual_pages_by_type 页面块或 shared_components 共享组件"]
    VBLOCK_READ --> VBLOCK_ACTION["判断调整方式：partial_patch refine regenerate"]
    VBLOCK_ACTION --> VBLOCK_PARTIAL["partial_patch：只替换当前块 JSON HTML CSS 或字段"]
    VBLOCK_ACTION --> VBLOCK_REFINE["refine 或 regenerate：按 instruction 重新生成当前组件"]
    VBLOCK_PARTIAL --> VBLOCK_PATCH_SERVICE["调用 AiSiteBlockPartialPatchService"]
    VBLOCK_REFINE --> BLOCK_EDIT_STORE["编辑指令存 shared_component_refinements 或 virtual_pages_by_type.page.section_refinements"]
    BLOCK_EDIT_STORE --> BLOCK_EDIT_DIRTY["标记相关任务 dirty failed 或 pending"]
    BLOCK_EDIT_DIRTY --> BLOCK_EDIT_REBUILD["重新进入 Build 队列，只重建受影响任务"]
    BLOCK_EDIT_REBUILD --> BUILD_STREAM
    VBLOCK_PATCH_SERVICE --> VBLOCK_VALIDATE["虚拟块调整门禁：schema 内容安全 asset 引用 layout 兼容"]
    VBLOCK_VALIDATE --> VBLOCK_PATCH_FAIL["验证失败：写失败 reason，保留原块"]
    VBLOCK_VALIDATE --> VBLOCK_WRITE["验证通过：写回当前生成块"]
    VBLOCK_WRITE --> VBLOCK_STORE["存储位置：virtual_pages_by_type.page.blocks 或 shared_components"]
    VBLOCK_STORE --> VBLOCK_LAYOUT["同步 layout：VirtualThemeLayout 或当前页面 blocks 顺序"]
    VBLOCK_LAYOUT --> VBLOCK_PREVIEW["推送 preview_ready 并重新加载 workspace scope"]
    VBLOCK_PREVIEW --> PAGE_QA
    VBLOCK_PATCH_FAIL --> SSE

    GATE_LEDGER["门禁台账 gate_ledger"]
    GATE_RECORD["记录字段：gate_key status blocking reason details task_id page_type block_key slot_id queue_id"]
    GATE_LEDGER --> GATE_RECORD
    GATE_RECORD --> GATE_FAIL_RULE["失败处理：不新增失败字段，统一写 failed reason，gate_ledger 保存门禁明细"]

    SSE["SSE 运行时反馈总线"]
    SSE_CHECK["SSE 实时检查整个 Stage2 流程数据"]
    SSE_CHECK_DATA["检查数据：scope_version build_tasks task_counts gate_ledger gate_status retryable_ai_failures asset_manifest verified_assets slot_status used_slot_ids missing_slot_ids virtual_pages_by_type shared_components result_ref block_patch_state"]
    SSE_SCHEMA["公共字段：stage status progress message payload timestamp elapsed_ms queue_id execution_token operation_id"]
    SSE_PROGRESS["进度规则：5 启动，10 合同，15 任务树，20 队列，30 资产，35 到 85 并发任务，90 整页 QA，96 汇总，100 完成或失败"]
    SSE_GATE["门禁反馈：gate_start gate_pass gate_fail gate_block"]
    SSE_GATE_PAYLOAD["Gate payload：gate_key gate_label status blocking reason task_id page_type block_key slot_id details"]
    SSE_BUILD["Build 队列反馈：build_start runtime_context_frozen build_blueprint_saved panel_update queue_created queue_running retry_failed_selected stage2_rebuild_clear"]
    SSE_BUILD_PAYLOAD["Build payload：queue_id execution_token stream_url build_type task_total page_total block_total runtime_context_keys"]
    SSE_TASK["任务调度反馈：task_schedule task_running task_done task_failed"]
    SSE_TASK_PAYLOAD["任务 payload：task_id task_key task_type page_type block_key slot_id asset_status batch_id attempt_no status reason retryable result_ref"]
    SSE_TASK_STATS["任务统计 payload：pending_count running_count done_count failed_count current_batch total_progress"]
    SSE_PROMPT["Prompt 组装反馈：base_loaded task_loaded runtime_refs_loaded site_context page_context block_context asset_context prompt_ready"]
    SSE_PROMPT_PAYLOAD["Prompt payload：base_prompt_version global_context_ref page_context_ref block_context_ref prompt_scope slot_ids final_urls asset_ready policy_ref skill_codes context_hash"]
    SSE_AI["AI 与验证反馈：component_generate component_validate schema_invalid quality_invalid ai_failed token_usage"]
    SSE_AI_PAYLOAD["AI payload：provider model latency_ms input_tokens output_tokens schema_errors quality_errors"]
    SSE_QA["QA 与收尾反馈：page_qa build_summary build_failed build_ready can_publish retryable_tasks"]
    SSE_QA_PAYLOAD["QA payload：page_type qa_status failed_reasons retryable_tasks used_slot_ids missing_slot_ids preview_url can_publish"]
    SSE_PANEL["前端面板消费：当前阶段 当前任务 进度百分比 失败原因 重试入口 预览发布状态"]
    BUILD_READY --> SSE
    BUILD_FAILED --> SSE
    START -.-> SSE_CHECK
    CONTRACT_GATE -.-> SSE_CHECK
    LOAD_CONTEXT -.-> SSE_CHECK
    TASK_TREE_GATE -.-> SSE_CHECK
    TASK_STATE_STORE -.-> SSE_CHECK
    TASK_PANEL -.-> SSE_CHECK
    BUILD_ACTION -.-> SSE_CHECK
    STAGE2_RESTART -.-> SSE_CHECK
    RETRY_SCAN -.-> SSE_CHECK
    RETRY_RESET -.-> SSE_CHECK
    RETRY_QUEUE_DETAILS -.-> SSE_CHECK
    QUEUE_GATE -.-> SSE_CHECK
    CLEAR_ASSET_STATE -.-> SSE_CHECK
    BUILD_STREAM -.-> SSE_CHECK
    ASSET_SLOT_DISCOVER -.-> SSE_CHECK
    ASSET_MANIFEST_UPSERT -.-> SSE_CHECK
    ASSET_MANIFEST_GATE -.-> SSE_CHECK
    ASSET_QUEUE -.-> SSE_CHECK
    CONTENT_GATE_BEFORE_IMAGE -.-> SSE_CHECK
    ASSET_RECORD -.-> SSE_CHECK
    ASSET_STORE -.-> SSE_CHECK
    TASK_BATCH -.-> SSE_CHECK
    PROMPT_GATE -.-> SSE_CHECK
    PROMPT_READY -.-> SSE_CHECK
    AI_GENERATE -.-> SSE_CHECK
    TASK_VALIDATE -.-> SSE_CHECK
    SCHEMA_GATE -.-> SSE_CHECK
    CONTENT_VISUAL_GATE -.-> SSE_CHECK
    IMAGE_SLOT_VALIDATE -.-> SSE_CHECK
    ARTIFACT_SLOT_BIND -.-> SSE_CHECK
    ARTIFACT_GATE -.-> SSE_CHECK
    ARTIFACT_STORE -.-> SSE_CHECK
    TASK_DONE -.-> SSE_CHECK
    TASK_FAIL -.-> SSE_CHECK
    RETRYABLE_LEDGER -.-> SSE_CHECK
    TASK_FAIL_STATE -.-> SSE_CHECK
    PAGE_QA -.-> SSE_CHECK
    PUBLISH_GATE -.-> SSE_CHECK
    BUILD_SUMMARY -.-> SSE_CHECK
    BLOCK_EDIT_STORE -.-> SSE_CHECK
    VBLOCK_ADJUST -.-> SSE_CHECK
    VBLOCK_PATCH_SERVICE -.-> SSE_CHECK
    VBLOCK_WRITE -.-> SSE_CHECK
    VBLOCK_PREVIEW -.-> SSE_CHECK
    GATE_LEDGER -.-> SSE_CHECK
    SSE_CHECK --> SSE_CHECK_DATA
    SSE_CHECK_DATA --> SSE
    SSE --> SSE_SCHEMA
    SSE_SCHEMA --> SSE_PROGRESS
    SSE_PROGRESS --> SSE_GATE
    SSE_GATE --> SSE_GATE_PAYLOAD
    SSE_GATE_PAYLOAD --> SSE_BUILD
    SSE_BUILD --> SSE_BUILD_PAYLOAD
    SSE_BUILD_PAYLOAD --> SSE_TASK
    SSE_TASK --> SSE_TASK_PAYLOAD
    SSE_TASK_PAYLOAD --> SSE_TASK_STATS
    SSE_TASK_STATS --> SSE_PROMPT
    SSE_PROMPT --> SSE_PROMPT_PAYLOAD
    SSE_PROMPT_PAYLOAD --> SSE_AI
    SSE_AI --> SSE_AI_PAYLOAD
    SSE_AI_PAYLOAD --> SSE_QA
    SSE_QA --> SSE_QA_PAYLOAD
    SSE_QA_PAYLOAD --> SSE_PANEL

    CONTRACT_GATE -.-> GATE_LEDGER
    TASK_TREE_GATE -.-> GATE_LEDGER
    QUEUE_GATE -.-> GATE_LEDGER
    ASSET_MANIFEST_GATE -.-> GATE_LEDGER
    CONTENT_GATE_BEFORE_IMAGE -.-> GATE_LEDGER
    PROMPT_GATE -.-> GATE_LEDGER
    SCHEMA_GATE -.-> GATE_LEDGER
    CONTENT_VISUAL_GATE -.-> GATE_LEDGER
    IMAGE_SLOT_VALIDATE -.-> GATE_LEDGER
    ARTIFACT_GATE -.-> GATE_LEDGER
    PAGE_QA -.-> GATE_LEDGER
    PUBLISH_GATE -.-> GATE_LEDGER
    VBLOCK_VALIDATE -.-> GATE_LEDGER
    RETRYABLE_LEDGER -.-> GATE_LEDGER

    NOTE1["注释 A：第二阶段只执行 confirmed plan_json 与 build_plan_v2，不重新规划"]
    NOTE2["注释 B：build_blueprint.tasks 是任务定义，scope.build_tasks 是运行态；二者必须分开"]
    NOTE3["注释 C：并发执行必须按 page_order 和 block_order 写回"]
    NOTE4["注释 D：底座提示词只提供执行规则，不决定 Block 是否存在；上下文必须从 Stage1 confirmed contract、build_plan_v2 和 task.runtime_context 组装"]
    NOTE5["注释 E：Stage2 不解释为什么需要某个 Block，只按 Stage1 合同执行"]
    NOTE6["注释 F：失败字段保持一个，失败原因通过 reason 区分"]
    NOTE7["注释 G：SSE 是前端控制面板进度来源，必须用虚线实时检查各阶段流程数据，不能只发 summary"]
    NOTE8["注释 H：重建清空旧产物，重试只跑失败、中断、缺失结果任务"]
    NOTE9["注释 I：任务树不是静态展示数据，scope.build_tasks 必须保存每个任务状态、result_ref、reason、attempt_no"]
    NOTE10["注释 J：生成产物先进 virtual_pages_by_type 和 shared_components，图片资产进 asset_manifest 和 verified_assets"]
    NOTE11["注释 K：虚拟主题 Block 调整发生在 Stage2 产物层，不直接改 confirmed Stage1 合同"]
    NOTE12["注释 L：并发任务阶段的 progress 不能只按时间估算，要按 task_total done failed running pending 汇总计算"]
    NOTE13["注释 M：partial_patch 直接替换当前已生成块；refine / regenerate 走指令记录和受影响任务重建"]
    NOTE14["注释 N：虚拟主题块调整后必须重新验证、更新 layout、推送 preview_ready，再进入页面 QA"]
    NOTE15["注释 O：当前页面上下文不是一条 page_type，它必须包含页面职责、设计计划、内容边界、当前任务引用与排序信息"]
    NOTE16["注释 P：图片任务和组件任务靠 slot_id 对应，不靠图片顺序或自然语言描述猜测"]
    NOTE17["注释 Q：组件 HTML 必须同时保留 final_url 和 data-pb-ai-asset-slot，QA 用 required / used / missing 三类 slot 列表定位错配"]
    NOTE18["注释 R：门禁系统不是最终 QA，而是每个跨边界动作前的准入和阻断"]
    NOTE19["注释 S：门禁失败仍写统一 failed reason，gate_ledger 记录哪个 gate 卡住和证据"]
    NOTE20["注释 T：内容门禁在图片生成前阻断，避免内容未完成时先生成错误主题图片"]
    NOTE21["注释 U：失败任务本轮只记账不立即重试；下次用户触发重试失败时统一重跑 failed interrupted missing_result stale running"]
    NOTE22["注释 V：第二阶段重建不是重试，必须停止旧队列并清空 Stage2 运行态和产物，再从 build_plan_v2.tasks 重新建任务树"]

    READ_TREE --> NOTE1
    PARSE_TASKS --> NOTE2
    DEP_RULE --> NOTE3
    CTX_BASE_DETAIL --> NOTE4
    LOAD_CONTEXT --> NOTE5
    TASK_FAIL --> NOTE6
    SSE_PANEL --> NOTE7
    BUILD_ACTION --> NOTE8
    TASK_STATE_STORE --> NOTE9
    ARTIFACT_STORE --> NOTE10
    VBLOCK_ADJUST --> NOTE11
    SSE_TASK_STATS --> NOTE12
    VBLOCK_ACTION --> NOTE13
    VBLOCK_PREVIEW --> NOTE14
    CTX_PAGE_SOURCE --> NOTE15
    ASSET_SLOT_BUILD --> NOTE16
    IMAGE_SLOT_VALIDATE --> NOTE17
    GATE_LEDGER --> NOTE18
    GATE_FAIL_RULE --> NOTE19
    CONTENT_GATE_BEFORE_IMAGE --> NOTE20
    RETRYABLE_LEDGER --> NOTE21
    STAGE2_RESTART --> NOTE22
```
