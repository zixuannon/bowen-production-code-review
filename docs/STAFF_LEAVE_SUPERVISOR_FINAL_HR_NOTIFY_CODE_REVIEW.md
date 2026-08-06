# Staff Leave: 直属主管最终审批 + HR 通知/只读查看 - 代码审查报告

> **类型：** 只读静态审查（不修改代码）
> **日期：** 2026-07-10
> **审查目标：** 验证实施是否符合 `STAFF_LEAVE_SUPERVISOR_FINAL_HR_NOTIFY_DESIGN.md` 及最终业务规则

---

## 1. 审查范围

审查的文件遵循实施报告 (`STAFF_LEAVE_SUPERVISOR_FINAL_HR_NOTIFY_IMPLEMENTATION.md`) 列出的 12 个核心文件，加上 `StaffApiController.php`、`Leave.php` Model、`features.php` 等受影响文件。

## 2. Git diff 范围

`git diff --stat HEAD` 显示 **28 个文件**，共 +1272 / -3364 行：

| 类型 | 文件数 | 说明 |
|------|--------|------|
| 核心业务修改 | 12 | 实施报告列出的文件 |
| 其他代码修改 | 4 | `StaffApiController.php`, `StaffController.php`, `Leave.php`, `Staff.php`（Phase 3 遗留） |
| 翻译/Lang | 2 | `en.json`, `zh-cn.json` |
| 已删除 docs | 7 | `database-design.md`, `prd.md`, 5 个 finance 相关 doc |
| 缓存文件 | 2 | `bootstrap/cache/packages.php`, `services.php` |
| View 文件 | 3 | `description_modal.blade.php`, `leave/index.blade.php`, `staff/index.blade.php` |

**结论："83 files" 是分支累计文件数量，本次实施修改实际覆盖 12 个核心文件**。未发现夹带无关 Dify、Playwright 缓存、测试文件、环境文件。

---

## 3. 主管批准 - 状态流转

**文件：** `app/Http/Controllers/LeaveController.php` L827-835

```php
// Supervisor final approval: status=1, supervisor_status=1
$leave->status = \App\Models\Leave::STATUS_APPROVED;
$leave->supervisor_status = \App\Models\Leave::APPROVAL_APPROVED;
$leave->supervisor_comment = $comment;
$leave->supervisor_reviewed_at = now();
// supervisor_user_id unchanged (already set at submission)
// hr_status / hr_user_id / hr_comment / hr_reviewed_at stay NULL
```

### ✅ 检查结果：

| 检查项 | 结果 |
|--------|------|
| `status = 1` 立即设置 | ✅ PASS |
| `supervisor_status = 1` | ✅ PASS |
| `hr_status = null`（不写入） | ✅ PASS |
| 不调用 `hasActiveHrApprover()` | ✅ PASS |
| 不要求学校配置 HR 用户 | ✅ PASS |
| `supervisor_user_id` 保持不变 | ✅ PASS |
| `lockForUpdate()` 使用 | ✅ PASS（L769） |
| DB transaction 包裹 | ✅ PASS（L764-849） |
| 锁内重新检查所有状态 | ✅ PASS（L778-823） |
| 分配的主管才能审批 | ✅ PASS（L807-809） |
| 主管不能审批自己 | ✅ PASS（L813-815） |
| 已审批不能重复审批 | ✅ PASS（L795-804） |
| 请求不能伪造 `supervisor_user_id` | ✅ PASS（只读取不修改） |
| 通知在事务提交后 | ✅ PASS（L849-876） |

---

## 4. 主管拒绝 - 状态流转

**文件：** `app/Http/Controllers/LeaveController.php` L836-843

```php
$leave->status = \App\Models\Leave::STATUS_REJECTED;
$leave->supervisor_status = \App\Models\Leave::APPROVAL_REJECTED;
$leave->supervisor_comment = $comment;
$leave->supervisor_reviewed_at = now();
// hr_status stays null
```

### ✅ 检查结果：

| 检查项 | 结果 |
|--------|------|
| `status = 2` | ✅ PASS |
| `supervisor_status = 2` | ✅ PASS |
| 所有 `hr_*` 字段保持 null | ✅ PASS |
| 不通知 HR | ✅ PASS（只在 approve 分支调用 `getActiveHrApprovers`） |
| 只通知员工 | ✅ PASS（L869-871） |
| 已拒绝不能再审批 | ✅ PASS |

---

## 5. 员工提交 - 不依赖 HR

### ✅ `LeaveController::store()` L128-192：
- 无 `hasActiveHrApprover()` 调用 ✅
- 仍验证直属主管存在性和有效性（L149-167）✅
- 提交后 `status=0, supervisor_status=0, supervisor_user_id` 锁定 ✅

### ✅ `ApiController::applyLeaves()` L627-714：
- 无 `hasActiveHrApprover()` 调用 ✅
- 无 HR 权限检查 ✅
- 与 Web 路径行为一致 ✅

**无 HR 用户时仍可提交 ✅**

---

## 6. HR 审批功能移除 - 全面验证

| 搜索项 | 结果 | 文件 |
|--------|------|------|
| `hrStatusUpdate`（PHP） | 0 matches | ✅ 已清除 |
| `leave/hr/status/update`（路由） | 已删除 | ✅ 路由 L883 注释确认 |
| `hrLeaveEvents`（JS） | 仅 docs 中引用 | ✅ 已从 actionEvents.js 删除 |
| `#hrModal` | 已删除 | ✅ hr_requests.blade.php |
| HR approve/reject 按钮 | 已删除 | ✅ |
| HR 操作列 | 已删除 | ✅ |
| `hr-approve-leave`（业务逻辑） | 仅 Command 注释 | ✅ 非业务调用 |
| `waiting_for_hr_approval`（业务逻辑） | 无业务调用 | ✅ 仅 lang 文件遗留键 |

**HR 审批功能已 100% 移除 ✅**

---

## 7. HR 只读权限 - `hr-view-leave`

| 检查点 | 权限值 | 结果 |
|--------|--------|------|
| sidebar 菜单 | `hr-view-leave` | ✅ PASS |
| `hrRequests()` 页面 | `hr-view-leave` | ✅ PASS（L909） |
| `hrRequestsShow()` 数据接口 | `hr-view-leave` | ✅ PASS（L939） |
| SchoolDataService 权限注册 | `hr-view-leave` | ✅ PASS |
| TwoStageLeaveService 查询 | `hr-view-leave` | ✅ PASS（L114） |
| 不检查角色名 "HR" | 只检查 permission | ✅ PASS |
| School Admin 不自动获得 | 无代码 | ✅ PASS |
| 无权限用户被拒绝 | `noPermissionThenRedirect` | ✅ PASS |

**hr-view-leave 权限在所有关键路径一致 ✅**

---

## 8. HR 查询隔离

**文件：** `LeaveController::hrRequestsShow()` L950-957

```php
$sql = $this->leave->builder()
    ->whereIn('supervisor_status', [
        \App\Models\Leave::APPROVAL_APPROVED,
        \App\Models\Leave::APPROVAL_REJECTED,
    ])
    ->whereNull('withdrawn_at')
```

### ✅ 检查结果：

| 检查项 | 结果 |
|--------|------|
| `supervisor_status IN (1,2)` | ✅ PASS |
| `withdrawn_at IS NULL` | ✅ PASS |
| 不显示 `supervisor_status=0` | ✅ PASS |
| 显示主管拒绝记录 | ✅ PASS（包含 `APPROVAL_REJECTED`） |
| 不跨学校 | ✅ PASS（school DB 隔离） |
| 前端参数不突破过滤条件 | ✅ PASS |
| 无操作按钮 | ✅ PASS（`operate = ''`） |
| 历史 `supervisor_status=null` 排除 | ✅ PASS（只会匹配 `IN (1,2)`） |

---

## 9. HR 通知

**文件：** `LeaveController::supervisorStatusUpdate()` L858-868

```php
$hrViewerIds = TwoStageLeaveService::getActiveHrApprovers();
if (!empty($hrViewerIds)) {
    $hrTitle = Auth::user()->full_name . ' ' . trans('leave_approved_by_supervisor');
    $hrBody = trans('leave_approved_notification_for_hr');
    send_notification($hrViewerIds, $hrTitle, $hrBody, $type);
}
```

### ✅ 检查结果：

| 检查项 | 结果 |
|--------|------|
| 只查 `hr-view-leave` 权限 | ✅ PASS（TwoStageLeaveService L114） |
| 只查有效用户（`status=1`） | ✅ PASS（L112） |
| 不依赖 HR 角色名 | ✅ PASS |
| 无 HR 时不阻塞 | ✅ PASS（`empty()` 检查） |
| 通知异常不回滚 | ✅ PASS（try/catch） |
| 主管拒绝不调用 | ✅ PASS（只在 approve 分支） |

---

## 10. Service 方法

**文件：** `app/Services/StaffLeave/TwoStageLeaveService.php`

| 方法 | 状态 | 说明 |
|------|------|------|
| `isEnabled()` | ✅ 正确 | feature flag + schema 检测 |
| `getActiveHrApprovers()` | ⚠️ P2 | 语义命名不当："Approvers" → 应改为 "Viewers" 或 "NotificationRecipients" |
| `hasActiveHrApprover()` | ⚠️ P1 | 标记 @deprecated 但保留完整实现，存在被误用风险。已无业务调用方 |
| `getEffectiveStatus()` | ✅ 正确 | 新记录 `supervisor_status=1` → `'approved'` |
| `resetCache()` | ✅ 正确 | 缓存隔离 |
| `cacheKey()` | ✅ 正确 | 按 school database name 缓存 |

---

## 11. Permission & Command

**文件：** `app/Console/Commands/InstallHrLeavePermissionForSchool.php`

### ✅ 检查结果：

| 检查项 | 结果 |
|--------|------|
| Command 签名正确 | ✅ `school:install-hr-leave-view-permission` |
| `--school-id` 必填 | ✅ PASS |
| `--dry-run` 不写数据库 | ✅ PASS |
| 幂等（`firstOrCreate`, `updateOrCreate`） | ✅ PASS |
| 创建 `hr-view-leave` permission | ✅ PASS（L61） |
| HR 角色绑定 `hr-view-leave` | ✅ PASS（L71） |
| 不分配用户 | ✅ PASS（L83 注释） |
| 清除 Spatie cache | ✅ PASS（L75） |
| 单学校操作 | ✅ PASS |
| 不删除旧 `hr-approve-leave` | ✅ PASS（保留） |
| 文件名与类名一致 | ✅ PASS |

**SchoolDataService** 同样正确：注册 `hr-view-leave`（L363），`createHrRole()` 分配 `hr-view-leave`（L818）。

---

## 12. 员工状态 Formatter

**文件：** `public/assets/js/custom/bootstrap-table/formatter.js` L238-273

### 新记录状态映射：

| 条件 | 显示 | Badge |
|------|------|-------|
| `withdrawn_at != null` | "已撤回" | secondary |
| `supervisor_status=null` 且 `status=0` | "Pending"（旧） | warning |
| `supervisor_status=0` | "等待直属主管" | warning |
| `supervisor_status=2` | "直属主管已拒绝" | danger |
| `supervisor_status=1` + `hr_status=1` | "HR 批准"（历史） | success |
| `supervisor_status=1` + `hr_status=2` | "HR 拒绝"（历史） | danger |
| `supervisor_status=1` + `hr_status=null/0` | **"已批准"** | success |

### ⚠️ P2 发现：`hr_status=0, supervisor_status=1, status=0` 遗留记录显示为"已批准"

当 `hr_status=0` 时，JS `==` 比较：`row.hr_status == 1` → `false`，`row.hr_status == 2` → `false`，fallthrough 到 `return "Approved"`。实际 `status=0` 不会影响 Dashboard/Payroll，仅显示有误导。

> 设计文档要求：不得误显示为已批准。但 Phase 4 从未生产启用，此记录仅测试存在。**本次不修改，仅记录。**

- ✅ 不显示"等待 HR" ✅
- ✅ 不显示"HR 最终批准/拒绝"（新记录）✅
- ✅ Badge class 正确 ✅

---

## 13. Dashboard / Payroll / Report 影响

下游全部使用 `status = 1`（`STATUS_APPROVED`）来统计和计算：

| 下游功能 | 查询条件 | 新流程影响 |
|----------|----------|------------|
| `filter_leave()` | `where('status', 1)` | ✅ 主管批准后立即纳入 |
| `detail()` (leave report) | `where('status', 1)` | ✅ 主管批准后立即纳入 |
| `myPayrollSlip()` | `where('status', 1)` | ✅ 主管批准后立即纳入 |
| `staffPayrollList()` | `where('status', 1)` | ✅ 主管批准后立即纳入 |
| `getAttendance()` | `where('status', 1)` | ✅ 主管批准后立即纳入 |
| `getLeaves()` (API) | `where('status', 1)` | ✅ 主管批准后立即纳入 |

**主管批准 `status=1` 立即生效是预期行为 ✅**

**没有下游逻辑额外要求 `hr_status=1` ✅**

**没有查询因 `hr_status=null` 而排除新记录 ✅**

---

## 14. 旧接口守卫

| 接口 | 守卫方式 | 结果 |
|------|----------|------|
| `leave_status_update()`（Web） | `supervisor_status != null` → block | ✅ PASS（L575-578） |
| `leaveApprove()`（API） | `supervisor_status != null` → block | ✅ PASS（StaffApiController L593-598） |
| `leaveDelete()`（API） | `supervisor_status != null` → block | ✅ PASS（StaffApiController L654-658） |
| `destroy()`（Web） | `supervisor_status != null` → block | ✅ PASS（LeaveController L407-410） |
| `supervisorStatusUpdate()` | re-check locked status | ✅ PASS |

---

## 15. XSS 修复回归

### ✅ 全部保留：

| 检查项 | 结果 |
|--------|------|
| `escapeHtml()` | ✅ 16 处使用 |
| `safeUrlAttr()` | ✅ 12 处使用 |
| `descriptionFormatter` 转义 | ✅ 保留 |
| `diaryFormatter` 转义 | ✅ 保留 |
| `tableDescriptionEvents` 使用 `.text()` | ✅ 保留 |
| file link `rel="noopener noreferrer"` | ✅ 保留 |
| `StudentNameFormatter` / `StaffNameFormatter` | ✅ 保留 |
| Description modal `white-space: pre-wrap` | ✅ 保留 |

### ❌ 未发现回退：

- `.html(row.description)` / `.html(row.reason)` / `.html(row.comment)` - 0 匹配
- `innerHTML` 不安全赋值 - 0 匹配
- `javascript:` URL - 0 匹配

---

## 16. 静态检查结果

| 检查 | 结果 |
|------|------|
| PHP syntax (`php -l`) | ✅ 6 个文件全部通过 |
| JSON parse (en.json, zh-cn.json) | ✅ 全部有效 |
| `git diff --check` | ✅ 无格式问题 |
| `hr-approve-leave` 业务代码搜索 | ✅ 仅 Command 注释 |
| `hrLeaveEvents` 代码搜索 | ✅ 已删除 |
| `hasActiveHrApprover` 调用搜索 | ✅ 无业务调用（仅 Service 自身） |
| `waiting_for_hr_approval` 业务代码搜索 | ✅ 无调用 |
| `leave_pending_hr_review` 业务代码搜索 | ✅ 无调用 |
| Migration | ✅ 无新增 |
| `dd`/`dump`/`var_dump` 残留 | ⚠️ 大量文件和 vendor 库中存在，但在核心变更文件中未发现新增 |

---

## 17. 发现的问题汇总

### P0（阻塞生产）：0 个

### P1（影响功能或翻译）：2 个

**P1-1: `zh-cn.json` 缺失 `leave_approved_notification_for_hr` 翻译键**

- **文件：** `resources/lang/zh-cn.json`
- **影响：** 主管批准后通知 HR 时，中文环境 `trans('leave_approved_notification_for_hr')` 返回原始 key 字符串，不可阅读
- **修复：** 添加 `"leave_approved_notification_for_hr": "有一条员工请假已由直属主管批准。"`

**P1-2: `zh-cn.json` 的 `leave_approved_by_supervisor` 翻译未更新**

- **文件：** `resources/lang/zh-cn.json` L2089
- **当前值：** `"主管已批准您的请假申请，正在等待HR最终审批。"`
- **影响：** 员工收到通知后误解为还有 HR 审批步骤
- **修复：** 改为 `"您的请假申请已由直属主管批准。"` 与 `en.json` 一致

### P2（改进建议）：6 个

**P2-1: `getActiveHrApprovers()` 方法名语义不当**

- **文件：** `app/Services/StaffLeave/TwoStageLeaveService.php` L105
- **问题：** "Approvers" 暗示审批角色，但此方法实际用于获取通知接收者
- **建议：** 改名为 `getHrNotificationRecipients()` 或 `getActiveHrViewers()`

**P2-2: `hasActiveHrApprover()` 标记 @deprecated 但保留完整实现**

- **文件：** `app/Services/StaffLeave/TwoStageLeaveService.php` L80
- **风险：** 方法已无调用方，但可能被未来开发者误用
- **建议：** 直接删除，或改为抛出异常

**P2-3: 遗留翻译键未清理**

- **文件：** `zh-cn.json` 和 `en.json`
- **死键：** `waiting_for_hr_approval`, `hr_approve`, `hr_reject`, `hr_self_approve_error`, `hr_already_reviewed_error`, `hr_invalid_flow_error`, `leave_pending_hr_review`, `hr_approver_not_configured`
- **建议：** 保留无妨，但可清理

**P2-4: `leaveStageStatusFormatter` 中 `hr_status=0` 显示为"已批准"**

- **文件：** `public/assets/js/custom/bootstrap-table/formatter.js` L260-270
- **问题：** `hr_status=0, supervisor_status=1, status=0` 的记录显示为 "Approved"
- **影响：** 仅显示层面，`status=0` 不影响业务逻辑，Phase 4 从未生产启用
- **建议：** 如确需修复，增加 `row.hr_status === 0 && row.status == 0` 的分支

**P2-5: `config/features.php` 注释已过时**

- **文件：** `config/features.php` L18-29
- **问题：** 注释仍提及 "Phase 4 (HR final approval)"，当前已是 "Supervisor Final Approval"
- **建议：** 更新注释

**P2-6: Git diff 包含非本次变更文件**

- `StaffController.php`, `Leave.php`, `Staff.php`, `User.php` 为 Phase 3 遗留变更
- `bootstrap/cache/*.php` 为缓存自动更新
- 已删除 7 个 docs 文件（含 finance 和 design 相关）
- 建议在 PR 中明确说明

---

## 18. 关键问答

| 问题 | 答案 |
|------|------|
| **P0 数量** | **0** |
| **P1 数量** | **2**（zh-cn.json 翻译缺失 + 未更新） |
| **P2 数量** | **6** |
| **最严重问题** | zh-cn.json 中文翻译未同步更新，HR 通知和员工通知显示旧文案 |
| **HR 审批功能是否全部移除** | **是** — `hrStatusUpdate` 方法、路由、Modal、按钮、JS handler 全部删除 |
| **主管批准是否直接 status=1** | **是** — 批准后立即 `status=1, supervisor_status=1` |
| **无 HR 用户是否仍可批准** | **是** — 不依赖 HR 用户存在 |
| **XSS 修复是否完整保留** | **是** — escapeHtml 16 处、safeUrlAttr 12 处全部保留 |
| **"83 files" 是否存在无关变更** | **否** — 83 是分支累计。本次实施修改 12 个核心文件。其他为 Phase 3 遗留和缓存 |
| **是否允许进入隔离测试** | **是**（P1 为翻译问题，不影响逻辑正确性） |
| **是否允许生产部署** | **否** |
| **是否允许生产开启功能开关** | **否** |

---

## 19. 需要 CodeBuddy 修复的问题

1. **P1-1**: `zh-cn.json` 添加 `leave_approved_notification_for_hr` 翻译
2. **P1-2**: `zh-cn.json` 更新 `leave_approved_by_supervisor` 翻译

---

## 20. 审查结论

**核心逻辑全部正确。** 主管批准即 `status=1`、HR 只读、通知分离均已正确实现。HR 审批入口 100% 移除。XSS 修复完整保留。2 个 P1 翻译问题不影响代码运行，但建议在隔离测试前修复。
