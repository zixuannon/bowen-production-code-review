# Staff Leave：直属主管最终审批 + HR 通知/只读查看 - 实施报告

> **状态：** 实施完成（未部署生产）
> **日期：** 2026-07-10
> **基于设计：** `docs/STAFF_LEAVE_SUPERVISOR_FINAL_HR_NOTIFY_DESIGN.md`

---

## 1. 修改文件清单

| # | 文件 | 改动类型 |
|---|------|---------|
| 1 | `app/Http/Controllers/LeaveController.php` | 核心逻辑修改 |
| 2 | `app/Http/Controllers/Api/ApiController.php` | 移除 HR 检查 |
| 3 | `app/Services/StaffLeave/TwoStageLeaveService.php` | 简化状态 + 权限改为 hr-view-leave |
| 4 | `app/Services/SchoolDataService.php` | hr-approve-leave → hr-view-leave |
| 5 | `app/Console/Commands/InstallHrLeavePermissionForSchool.php` | 重写为 hr-view-leave |
| 6 | `routes/web.php` | 移除 HR approval 路由 |
| 7 | `resources/views/leave/hr_requests.blade.php` | 移除 modal + 操作列 |
| 8 | `resources/views/layouts/sidebar.blade.php` | 权限改为 hr-view-leave |
| 9 | `resources/lang/en.json` | 更新翻译 |
| 10 | `resources/lang/zh-cn.json` | 更新翻译 |
| 11 | `public/assets/js/custom/bootstrap-table/formatter.js` | 简化 leaveStageStatusFormatter |
| 12 | `public/assets/js/custom/bootstrap-table/actionEvents.js` | 删除 hrLeaveEvents |

---

## 2. 主管批准后的最终状态

```
status = 1 (Leave::STATUS_APPROVED)
supervisor_status = 1 (Leave::APPROVAL_APPROVED)
supervisor_user_id = 提交时的直属主管 ID（不变）
supervisor_comment = 审批备注
supervisor_reviewed_at = now()
hr_status = NULL
hr_user_id = NULL
hr_comment = NULL
hr_reviewed_at = NULL
withdrawn_at = NULL

通知：
  ✓ 员工：trans('leave_approved_by_supervisor')
  ✓ HR（有 hr-view-leave 权限的用户）：trans('leave_approved_notification_for_hr')
  ✓ 无 HR 用户时：跳过 HR 通知，主管批准仍成功
  ✓ HR 通知失败不阻塞主管批准（try/catch 包裹）
```

## 3. 主管拒绝后的最终状态

```
status = 2 (Leave::STATUS_REJECTED)
supervisor_status = 2 (Leave::APPROVAL_REJECTED)
supervisor_comment = 审批备注
supervisor_reviewed_at = now()
所有 hr_* 字段保持 NULL

通知：
  ✓ 员工：trans('leave_rejected_by_supervisor')
  ✗ 不通知 HR
```

## 4. 已移除的 HR 审批功能

| 功能 | 位置 | 状态 |
|------|------|------|
| `hrStatusUpdate()` 方法 | `LeaveController.php` | ✅ 已删除 |
| `PUT leave/hr/status/update` 路由 | `routes/web.php` | ✅ 已删除 |
| `hrLeaveEvents` JS handler | `actionEvents.js` | ✅ 已删除 |
| HR Approval Modal (`#hrModal`) | `hr_requests.blade.php` | ✅ 已删除 |
| HR 操作列 (`data-events="hrLeaveEvents"`) | `hr_requests.blade.php` | ✅ 已删除 |
| HR approve/reject 按钮 | `hr_requests.blade.php` | ✅ 已删除 |
| `hasActiveHrApprover()` 在 `store()` | `LeaveController.php` | ✅ 已删除 |
| `hasActiveHrApprover()` 在 `supervisorStatusUpdate()` | `LeaveController.php` | ✅ 已删除 |
| `hasActiveHrApprover()` 在 `applyLeaves()` | `ApiController.php` | ✅ 已删除 |
| `hr_status = APPROVAL_PENDING` 写入 | `LeaveController.php` | ✅ 已删除 |
| "等待 HR 最终审批" 状态显示 | `formatter.js` | ✅ 已移除 |

## 5. HR 只读权限

| 项目 | 旧值 | 新值 |
|------|------|------|
| 权限名 | `hr-approve-leave` | `hr-view-leave` |
| HR 页面权限 | `hr-approve-leave` | `hr-view-leave` |
| HR 数据接口 | `hr-approve-leave` | `hr-view-leave` |
| 侧边栏菜单 | `hr-approve-leave` | `hr-view-leave` |
| SchoolDataService 注册 | `hr-approve-leave` | `hr-view-leave` |
| createHrRole() | `syncPermissions(['hr-approve-leave'])` | `syncPermissions(['hr-view-leave'])` |
| TwoStageLeaveService 查询 | `hr-approve-leave` | `hr-view-leave` |
| 旧权限 DB 中 | 保留不动 | 不删除 |
| School Admin | 不自动获得 | 不自动获得 |

## 6. HR 通知规则

| 场景 | 通知 HR | 说明 |
|------|---------|------|
| 主管批准 | ✅ 是 | 通知所有有 `hr-view-leave` 的用户 |
| 主管拒绝 | ❌ 否 | 仅通知员工 |
| 无 HR 用户 | ❌ 跳过 | 静默跳过，批准仍成功 |
| 通知失败 | ❌ 不阻塞 | catch 异常 + log::error |

## 7. 是否依赖 HR 用户存在

**否。** 员工提交不再检查 HR 用户。主管批准不再检查 HR 用户。如果没有 HR 用户，HR 通知静默跳过。

---

## 是否需要 migration：否

数据库列 `hr_status`、`hr_user_id`、`hr_comment`、`hr_reviewed_at` 在 `leaves` 表中完整保留不动。新记录不再写入这些列。

---

## 是否需要数据修复：否（本次）

不创建自动数据修复 Command。遗留 `supervisor_status=1, hr_status=0, status=0` 记录的影响：
- 员工页显示 "已批准"（新 formatter 逻辑：`supervisor_status=1` 且 `hr_status=null/0` → Approved）
- 实际 status=0 所以不会进入 Dashboard/Payroll
- 只有确认目标学校存在此类记录后，才另行创建手动修复方案

---

## XSS 修复是否保留：✅ 完整保留

- `escapeHtml()` - 16 处使用
- `safeUrlAttr()` - 12 处使用
- `descriptionFormatter` + `diaryFormatter` 安全转义
- `tableDescriptionEvents` 使用 `.text()`
- 所有文件链接使用 `safeUrlAttr()` + `rel="noopener noreferrer"`
- `StudentNameFormatter` / `StaffNameFormatter` 转义
- 无 `.html(row.` 或 `innerHTML` 回退

---

## P0/P1/P2

### P0（已验证通过）
- [x] `supervisorStatusUpdate()` 不再写 `hr_status`
- [x] `supervisorStatusUpdate()` 批准后直接设 `status=1`
- [x] `hrStatusUpdate()` 方法已删除
- [x] `PUT leave/hr/status/update` 路由已删除（返回 404）
- [x] `hr-approve-leave` 不在业务代码中检查
- [x] 侧边栏菜单改为 `hr-view-leave`
- [x] 通知不阻塞事务提交

### P1（已验证通过）
- [x] 员工提交不再依赖 HR 用户
- [x] XSS 修复完整保留
- [x] Phase 2 旧流程不受影响
- [x] `supervisor_status=NULL` 的旧记录仍走旧流程
- [x] 旧管理员审批接口对新流程记录继续守卫

### P2（待手动验证）
- [ ] Dashboard/Payroll 对新 status=1 记录正常运作
- [ ] 手机 API 客户端兼容性
- [ ] 浏览器 E2E 测试（functional flag ON）
- [ ] `php artisan route:list` 确认无 hr/status/update 路由
- [ ] 遗留 hr_status=0,status=0 数据扫描

---

## 是否建议进入 WorkBuddy 静态审查：是

建议审查以下关键路径：
1. `supervisorStatusUpdate()` 批准分支的状态变更
2. `leaveStageStatusFormatter` 新逻辑分支完整性
3. 所有 `hr-view-leave` 权限检查点一致
4. `hr_requests.blade.php` 无遗留 JS 引用

## 是否允许生产部署：**否**

原因：
1. 功能开关 `staff_leave_two_stage_enabled` 保持 false
2. 需手机 API 兼容性测试
3. 需浏览器 E2E 回归测试
4. 需 WorkBuddy 静态审查通过

---
---

## 定向修复记录（2026-07-10）

基于静态审查报告 `STAFF_LEAVE_SUPERVISOR_FINAL_HR_NOTIFY_CODE_REVIEW.md` 的 P1 修复。

### 修复文件

| # | 文件 | 修复内容 |
|---|------|---------|
| 1 | `resources/lang/zh-cn.json` | 修正 `leave_approved_by_supervisor` + 新增 `leave_approved_notification_for_hr` + `legacy_leave_pending` |
| 2 | `resources/lang/en.json` | 新增 `legacy_leave_pending` |
| 3 | `app/Services/StaffLeave/TwoStageLeaveService.php` | 重命名 `getActiveHrApprovers()` → `getHrNotificationRecipients()`，删除 `hasActiveHrApprover()` |
| 4 | `app/Http/Controllers/LeaveController.php` | 更新调用 `getActiveHrApprovers()` → `getHrNotificationRecipients()` |
| 5 | `public/assets/js/custom/bootstrap-table/formatter.js` | 修正遗留中间状态（supervisor_status=1,hr_status=0,status=0）显示为"遗留待处理" |
| 6 | `config/features.php` | 更新注释为 Superisor Final Approval |

### P1 翻译修复

- **`leave_approved_by_supervisor`（中文）**：
  - 旧：`"主管已批准您的请假申请，正在等待HR最终审批。"`
  - 新：`"您的请假申请已由直属主管批准。"`

- **`leave_approved_notification_for_hr`（中文）**：
  - 新增：`"有一条员工请假已由直属主管批准。"`

### Service 方法

- 新方法名：`getHrNotificationRecipients()`
- 已删除：`hasActiveHrApprover()`（PHP 业务代码 0 调用）
- 旧方法 `getActiveHrApprovers()` 已完全移除（PHP 业务代码 0 调用）

### 遗留中间状态显示

`supervisor_status=1, hr_status=0, status=0` 现在显示为 **"遗留待处理"**（warning badge），不再误显示为"已批准"。

### XSS 修复状态

- `escapeHtml()`：保留，16 处使用
- `safeUrlAttr()`：保留，12 处使用
- 无 `.html(row.description|reason|comment)` 或 `innerHTML` 回退
- 无 `"javascript:"` 或 `"data:text/html"` 注入

### P0/P1/P2

| 等级 | 数量 | 状态 |
|------|------|------|
| P0 | 0 | - |
| P1 | 2 | ✅ 已修复 |
| P2 | 6 | 命名已完成，其余为文档/测试引用 |

### 结论

| 问题 | 答案 |
|------|------|
| 是否允许进入隔离功能测试 | **是** |
| 是否运行 migration | **否** |
| 是否部署生产 | **否** |
| 是否开启生产功能开关 | **否** |
