# Staff Leave：直属主管最终审批 + HR 通知/只读查看

> **状态：** 设计阶段（只读扫描，不修改代码）
> **日期：** 2026-07-10
> **替代：** Phase 4 "HR 最终审批" 方案（已停止）

---

## 一、新状态流转

```
员工提交
  │
  ├── status=0, supervisor_status=0, supervisor_user_id=主管ID
  │   hr_status=NULL, hr_user_id=NULL, hr_comment=NULL, hr_reviewed_at=NULL
  │
  └── 直属主管操作 ▼
        │
        ├── 批准
        │   status = 1          ← 直接最终批准
        │   supervisor_status = 1
        │   supervisor_comment = 审批备注
        │   supervisor_reviewed_at = now()
        │   hr_status = NULL     ← 不设置（新方案）
        │   hr_user_id = NULL
        │   hr_comment = NULL
        │   hr_reviewed_at = NULL
        │
        │   通知：
        │   ✓ 员工："您的请假已由直属主管批准"
        │   ✓ HR（有 hr-view-leave 权限的所有活跃用户）："员工 X 的请假已由主管批准"
        │
        │   后果：
        │   ✓ Dashboard / Payroll / Report 等原有 status=1 逻辑照常生效
        │
        ├── 拒绝
        │   status = 2
        │   supervisor_status = 2
        │   supervisor_comment = 审批备注
        │   supervisor_reviewed_at = now()
        │   hr_status = NULL     ← 不设置
        │
        │   通知：
        │   ✓ 员工："您的请假已被直属主管拒绝"
        │   ? 可选择性通知 HR（建议：通知，HR 需要知道有被拒记录）
        │
        └── 撤回（员工）
            status=3, withdrawn_at=now()
            不修改 supervisor_status / hr_status
```

### 新旧对比

| 阶段 | 旧（Phase 4 两阶段） | 新（主管最终审批） |
|------|---------------------|-------------------|
| 提交 | 需 hasActiveHrApprover() 检查 | 不需要 HR 审批检查 |
| 主管批准后 | status=0, supervisor_status=1, **hr_status=0** | **status=1**, supervisor_status=1, **hr_status=NULL** |
| 主管拒绝后 | status=2, supervisor_status=2 | status=2, supervisor_status=2（不变） |
| HR 操作 | 可审批/拒绝，修改 status/hr_status | 不可操作，仅查看 |
| 最终生效 | HR 审批通过后 status=1 | 主管批准后 status=1 |
| HR 通知 | 主管批准后通知 HR "待审批" | 主管批准后通知 HR "已批准" |

---

## 二、需删除或停用的 HR 审批功能

### 2.1 删除后端方法

| 文件 | 删除内容 | 行号 |
|------|---------|------|
| `app/Http/Controllers/LeaveController.php` | 整个 `hrStatusUpdate()` 方法 | 1057–1203 |
| `app/Http/Controllers/LeaveController.php` | `supervisorStatusUpdate()` 内的 `hasActiveHrApprover()` 检查 | 832–836 |
| `app/Http/Controllers/LeaveController.php` | `supervisorStatusUpdate()` 批准分支的 `$leave->hr_status = APPROVAL_PENDING` | 842 |
| `app/Http/Controllers/LeaveController.php` | `store()` 内的 `hasActiveHrApprover()` 检查 | 169–172 |
| `app/Http/Controllers/Api/ApiController.php` | `applyLeaves()` 内的 `hasActiveHrApprover()` 检查 | 691–694 |
| `app/Services/StaffLeave/TwoStageLeaveService.php` | `hasActiveHrApprover()` 方法（标记为 @deprecated） | 80–95 |
| `routes/web.php` | `PUT leave/hr/status/update` 路由 | 883 |

### 2.2 删除前端功能

| 文件 | 删除内容 |
|------|---------|
| `public/assets/js/custom/bootstrap-table/actionEvents.js` | 整个 `hrLeaveEvents` handler（1310–1348） |
| `resources/views/leave/hr_requests.blade.php` | HR Approval Modal（#hrModal 整个块，73–149 行） |
| `resources/views/leave/hr_requests.blade.php` | 操作列 `data-events="hrLeaveEvents"` → 移除 |
| `public/assets/js/custom/bootstrap-table/formatter.js` | `hrStatusFormatter()`（226–236，改为只读或无操作列后不需要） |

### 2.3 删除权限

| 权限名 | 当前用途 | 新方案处理 |
|--------|---------|-----------|
| `hr-approve-leave` | 批准/拒绝 | **替换为 `hr-view-leave`**，旧权限在 DB 中保留但不再检查 |

---

## 三、需保留的 HR 查看功能

### 3.1 保留页面

| 功能 | 文件 | 修改 |
|------|------|------|
| HR 查看页面 | `resources/views/leave/hr_requests.blade.php` | 移除 modal + 操作列，改为纯只读表格 |
| HR 数据接口 | `LeaveController::hrRequestsShow()` | 权限改为 `hr-view-leave` |
| HR 页面路由 | `GET leave/hr/requests` + `GET leave/hr/requests/show` | 保留 |

### 3.2 HR 查看范围

HR 可查看的记录：
```
supervisor_status = 1 (主管已处理)
AND withdrawn_at IS NULL
AND (hr_status IS NULL OR hr_status IN (0, 1, 2))  ← 兼容历史数据
```

即：所有主管已批准的记录（无 HR 审批）+ 历史有 HR 审批的记录。

### 3.3 HR 查看表格列

移除：
- `hrStatusFormatter` 列（HR 不再修改状态）
- 操作列 `data-events="hrLeaveEvents"`

改为显示最终状态：
- `supervisorStatusFormatter`（主管已批准/已拒绝）
- 新增列：最终状态 `status`（approved=1 / rejected=2）

---

## 四、权限方案

### 旧

| role | permission |
|------|-----------|
| HR | `hr-approve-leave` |

### 新

| role | permission |
|------|-----------|
| HR | `hr-view-leave` |

迁移策略：
1. 新安装：SchoolDataService 注册 `hr-view-leave`
2. 已存在 DB：不强制删除 `hr-approve-leave` 记录（只保留在 DB 中，不做删除）
3. 控制器/中间件只检查 `hr-view-leave`
4. `createHrRole()` 方法更新为分配 `hr-view-leave`

### 访问控制

| 页面 | 旧权限 | 新权限 |
|------|--------|--------|
| HR 查看页面 | `hr-approve-leave` | `hr-view-leave` |
| HR 审批操作 | `hr-approve-leave` | **删除（无审批操作）** |

---

## 五、supervisorStatusUpdate() 修改对照

```php
// 旧（Phase 4）：主管批准后进入 "等待 HR 批准"
if ($request->status === 'approved') {
    // Phase 4: check HR approver exists
    if (!TwoStageLeaveService::hasActiveHrApprover()) {  // ← 删除
        DB::rollBack();
        ResponseService::errorResponse(trans('hr_approver_not_configured'));
    }

    $leave->supervisor_status = APPROVAL_APPROVED;
    $leave->supervisor_comment = $comment;
    $leave->supervisor_reviewed_at = now();
    $leave->hr_status = APPROVAL_PENDING;  // ← 删除：新方案不设置 hr_status
    // status stays 0  ← 旧行为
}

// 新（主管最终审批）：主管批准 = 最终批准
if ($request->status === 'approved') {
    $leave->status = STATUS_APPROVED;           // ← 新增：直接设为 1
    $leave->supervisor_status = APPROVAL_APPROVED;
    $leave->supervisor_comment = $comment;
    $leave->supervisor_reviewed_at = now();
    // hr_status stays NULL                     // ← 不设置
    // supervisor_user_id unchanged
}
```

### 通知修改

```php
// 旧：主管批准后 → 通知员工 + 通知 HR "待审批"
if ($request->status === 'approved') {
    // ... also notify all HR approvers
    $hrApproverIds = TwoStageLeaveService::getActiveHrApprovers();
    send_notification($hrApproverIds, ..., 'leave_pending_hr_review', ...);
}

// 新：主管批准后 → 通知员工 + 通知 HR "已批准"
if ($request->status === 'approved') {
    // 通知员工
    send_notification([$leaveUserId], trans('leave_approved_by_supervisor'), ...);

    // 通知 HR：有新的已批准请假（不阻塞主管操作）
    try {
        $hrViewerIds = TwoStageLeaveService::getActiveHrApprovers();
        if (!empty($hrViewerIds)) {
            send_notification($hrViewerIds,
                Auth::user()->full_name . ' ' . trans('leave_approved_by_supervisor'),
                trans('leave_approved_notification_for_hr'),
                $type);
        }
    } catch (\Throwable $e) {
        Log::error('HR notification failed: ' . $e->getMessage());
        // 不抛出，不让通知失败影响主管批准
    }
}
```

主管拒绝后是否通知 HR：
- **建议通知**：HR 需要知道被拒绝的记录（可选配置）。统一通知。

---

## 六、TwoStageLeaveService 修改

保留（必要功能）：
- `isEnabled()` — 仍在 supervisor 流程中作为功能开关 + schema 完整性检查
- `getActiveHrApprovers()` — **保留但重构为通知用途**（查有 `hr-view-leave` 权限的用户）
- `resetCache()` / `cacheKey()` — 缓存工具

标记 @deprecated（删除旧功能）：
- `hasActiveHrApprover()` — 不再有调用方，新增 @deprecated 标记

修改：
- `getEffectiveStatus()` — 移除 `pending_hr`/`approved_final`/`rejected_hr` 三个状态

### getEffectiveStatus() 新逻辑

```
如果 withdrawn_at != null → 'withdrawn'
如果 supervisor_status IS NULL → 旧流程（old_flow_pending/approved/rejected）
如果 supervisor_status == 0（pending）→ 'pending_supervisor'
如果 supervisor_status == 1 → 'approved'        ← 统一
如果 supervisor_status == 2 → 'rejected_supervisor'
```

不再有 pending_hr / approved_final / rejected_hr 概念。

---

## 七、前端 formatter 修改

### hrStatusFormatter() → 删除

新方案下 HR 不使用 Action 列，`hr_status` 也不再是核心状态。如果保留 HR 查看页：
- 移除 `data-formatter="hrStatusFormatter"`
- 新增 `data-field="status"` 直接显示最终批准/拒绝状态

### leaveStageStatusFormatter() → 简化

```js
// 旧
if (row.supervisor_status == 1) {
    if (row.hr_status == 0) {
        return "Waiting for HR";       // ← 删除
    } else if (row.hr_status == 1) {
        return "HR Approved";          // ← 删除
    } else if (row.hr_status == 2) {
        return "HR Rejected";          // ← 删除
    }
}

// 新
if (row.supervisor_status == 1) {
    // 主管批准即最终批准（新方案）
    // 兼容历史：如果 hr_status == 1/2，仍显示对应状态
    if (row.hr_status == 1) return "Approved";   // 历史已通过
    if (row.hr_status == 2) return "Rejected";   // 历史已拒绝
    return "Approved";                             // 新方案：主管批准=最终批准
}
```

### 员工 leave list 页表格

员工列表页 `resources/views/leave/index.blade.php` 使用 `leaveStageStatusFormatter`，修改后：
- 主管批准 → 直接显示 "Approved"（不再显示 "Waiting for HR Final Approval"）
- 主管拒绝 → "Rejected"（不变）
- 历史有 hr_status 的记录 → 仍正常显示

---

## 八、Store / applyLeaves 修改

| 文件 | 删除 | 保留 |
|------|------|------|
| `LeaveController::store()` | `hasActiveHrApprover()` 检查（L169–172） | 主管验证逻辑（supervisor_user_id 验证） |
| `ApiController::applyLeaves()` | `hasActiveHrApprover()` 检查（L691–694） | 主管验证逻辑 |

---

## 九、数据兼容性

### 旧测试数据

| hr_status 值 | 场景 | 处理 |
|-------------|------|------|
| 0 (pending) | 主管已批，HR 尚未审批的遗留记录 | **永久存在** — 没有 HR 再审批，但不影响正常功能。可通过数据修复脚本批量处理 |
| 1 (approved) | HR 已最终批准 | 保持不变，正常显示 |
| 2 (rejected) | HR 已拒绝 | 保持不变 |
| NULL | 新记录或旧流程 | 新记录默认行为 |

### 需要数据修复的情况

| 场景 | row 示例 | 问题 | 建议 |
|------|---------|------|------|
| supervisor_status=1, hr_status=0, status=0 | 主管已批，HR 未审 | 员工看到 "等待 HR 审批"，但 HR 不再审批 | 可选：批量设置 status=1, hr_status=NULL |

**不需要 migration**。

可选数据修复 Command（建议提供但默认不自动执行）：

```sql
-- 将主管批准但 HR 未审的历史记录标记为最终批准
UPDATE leaves
SET status = 1, hr_status = NULL
WHERE supervisor_status = 1 AND hr_status = 0 AND status = 0;
```

**不执行此修复的后果**：
- 遗留 hr_status=0, status=0 的记录在员工页面仍显示为 "等待 HR"，不会进入 Dashboard/Payroll
- 相当于永远卡在中间状态
- **建议提供修复命令，由学校管理员选择执行**

---

## 十、SchoolDataService 和权限 Command

### 权限注册

```php
// 旧
['name' => 'hr-approve-leave'],  // Phase 4: HR final approval

// 新
['name' => 'hr-view-leave'],      // HR 只读查看 + 接收通知
```

### createHrRole()

```php
// 旧
$hr_role->syncPermissions(['hr-approve-leave']);

// 新
$hr_role->syncPermissions(['hr-view-leave']);
```

### 对已安装学校的影响

- 旧 `hr-approve-leave` 权限保留在 DB（不删除）
- 需要为新版创建一个 migration/data-command 将 `hr-approve-leave` → `hr-view-leave` 的角色权限更新
- **建议**：提供一次性 Command，复用新方案的权限 Command

---

## 十一、Phase 2 兼容性

Phase 2（主管审批 + supervisor_status）**完全不受影响**：

| 功能 | Phase 2 行为 | 新方案 | 影响 |
|------|-------------|--------|------|
| 主管审批 API | supervisorStatusUpdate | supervisorStatusUpdate（修改） | ✅ 不新增 API，只改内部逻辑 |
| 主管页面 | supervisorRequests | 不变 | ✅ 不受影响 |
| 员工提交 | store / applyLeaves | 移除 HR 检查 | ✅ 更宽松 |
| 员工查看 | leaveStageStatusFormatter | 简化 | ✅ 显示更直观 |
| Dashboard | status=1 的逻辑 | 不变 | ✅ status=1 的时机提前 |
| feature flag | staff_leave_two_stage_enabled | 不变 | ✅ 同一个 flag |
| schema 检查 | isEnabled() 查 11 列 | 不变（hr_* 列仍在） | ✅ schema 兼容 |

---

## 十二、XSS 修复完整保留

所有 XSS 修复保持不变（`formatter.js`、`actionEvents.js`）：

1. ✅ `escapeHtml()` 工具函数保留
2. ✅ `safeUrlAttr()` 工具函数保留
3. ✅ `descriptionFormatter()` + `diaryFormatter()` 使用转义
4. ✅ `tableDescriptionEvents` 使用 `.text()`
5. ✅ 所有文件链接使用 `safeUrlAttr()` + `rel="noopener noreferrer"`
6. ✅ `StudentNameFormatter` / `StaffNameFormatter` 转义

---

## 十三、HR 通知目标对象

### 如何确定通知给哪些 HR

```php
TwoStageLeaveService::getActiveHrApprovers()
```

方法内部改为查询有 `hr-view-leave` 权限的活跃用户：

```php
public static function getActiveHrApprovers()
{
    if (!self::isEnabled()) {
        return collect();
    }
    try {
        return User::where('status', 1)
            ->whereHas('roles.permissions', function ($q) {
                $q->where('name', 'hr-view-leave');  // 从 hr-approve-leave 改为 hr-view-leave
            })
            ->pluck('id');
    } catch (\Throwable) {
        return collect();
    }
}
```

### 如果没有 HR 用户

- **允许主管批准**：如果没有任何用户有 `hr-view-leave` 权限，跳过 HR 通知
- HR 通知失败**不会回滚主管批准**（try/catch 包裹）

---

## 十四、修改文件清单（预计）

### PHP 后端（预计 ~6 文件）

| 文件 | 改动 |
|------|------|
| `app/Http/Controllers/LeaveController.php` | 删除 hrStatusUpdate()；修改 supervisorStatusUpdate() 去掉 HR 审批逻辑；修改 store() 去掉 hasActiveHrApprover()；hrRequestsShow() 权限改为 hr-view-leave |
| `app/Http/Controllers/Api/ApiController.php` | applyLeaves() 去掉 hasActiveHrApprover() |
| `app/Services/StaffLeave/TwoStageLeaveService.php` | hasActiveHrApprover() 标记 @deprecated；getEffectiveStatus() 简化；getActiveHrApprovers() 改用 hr-view-leave |
| `app/Services/SchoolDataService.php` | hr-approve-leave → hr-view-leave；createHrRole() 分配 hr-view-leave |
| `routes/web.php` | 删除 PUT leave/hr/status/update |
| `resources/lang/en.json` + `zh-cn.json` | 更新/新增翻译 key |

### 前端 JS（预计 ~3 文件）

| 文件 | 改动 |
|------|------|
| `public/assets/js/custom/bootstrap-table/actionEvents.js` | 删除 hrLeaveEvents handler |
| `public/assets/js/custom/bootstrap-table/formatter.js` | 简化 leaveStageStatusFormatter()；删除/停用 hrStatusFormatter() |
| `resources/views/leave/hr_requests.blade.php` | 移除 modal + 操作列；改为只读表格 |

### 数据库（不修改）

- 不新增 migration
- `leaves.hr_status` 等列保留在 DB 中不动
- 历史数据保留

---

## 十五、风险

| # | 风险 | 等级 | 缓解 |
|---|------|------|------|
| 1 | 遗留 hr_status=0,status=0 的记录永远卡在 "等待 HR" 状态 | 中 | 提供可选数据修复 Command；不执行自动修复，由学校管理员选择 |
| 2 | 前端 `leaveStageStatusFormatter` 简化后，历史有 hr_status 的记录显示错误 | 低 | 保留对历史 hr_status=1/2 的兼容逻辑 |
| 3 | 手机 API 客户端缓存旧状态格式 | 低 | API 返回的 status 字段不变（1/2/0） |
| 4 | 已分配的 `hr-approve-leave` 权限在 DB 中残留 | 低 | 不强制清理，Controller 不再检查此权限 |
| 5 | 主管批准后 status=1 立即进入 Payroll/Dashboard，与旧审批流程有细微行为差异 | 低 | 正是期望行为 |

---

## 十六、是否可开始实施

**✅ 是。** 设计已完成扫描，实施路径清晰：

1. 修改 `supervisorStatusUpdate()` — 核心逻辑变更
2. 删除 `hrStatusUpdate()` 方法 + 路由
3. 移除 `hasActiveHrApprover()` 的 3 个调用点
4. 简化 `TwoStageLeaveService::getEffectiveStatus()`
5. 修改前端 formatter + 移除 HR action events
6. HR 页面改为只读
7. 权限 hr-approve-leave → hr-view-leave
8. 更新翻译文案
9. 创建可选数据修复 Command

**预计工作量：** 2-3 小时。

---

## 十七、是否允许生产部署：**否**

原因：
1. 方案刚设计完成，尚未实施
2. 前端 formatter 变更需要浏览器定向 E2E 测试
3. 遗留 hr_status=0 的数据修复需要确认策略
4. 功能开关保持 `false` 直到实施完成并测试通过
5. API 向后兼容性需要在手机客户端上验证

---

## 十八、附录：完整测试清单

### 主管审批流

- [ ] 员工提交请假 → supervisor_status=0, status=0, hr_status=NULL
- [ ] 主管批准 → status=1, supervisor_status=1, hr_status=NULL
- [ ] 主管拒绝 → status=2, supervisor_status=2, hr_status=NULL
- [ ] 主管批准后通知员工 "已批准"
- [ ] 主管批准后通知 HR "已批准"（如果有 HR 用户）
- [ ] 无 HR 用户时主管仍可批准（不报错）
- [ ] HR 通知失败不回滚主管操作
- [ ] 员工撤回 → status=3

### HR 只读查看

- [ ] HR 可查看所有主管已批准的记录
- [ ] HR 页面无语病（无 approve/reject 按钮）
- [ ] HR 页面可搜索、筛选、排序
- [ ] HR 页面不受 `hr-view-leave` 权限控制
- [ ] 无 `hr-view-leave` 权限的用户不可访问 HR 页面

### 历史数据兼容

- [ ] 旧记录（supervisor_status=NULL）不受影响
- [ ] 旧记录（hr_status=0/1/2）正常显示
- [ ] Dashboard / Payroll 对 status=1 的新记录正常运行

### 前端

- [ ] 员工列表不显示 "等待 HR 审批"
- [ ] 员工列表主管批准后显示 "已批准"
- [ ] HR 列表无操作列
- [ ] Console 无 JS error
- [ ] XSS 修复完整保留

### 权限

- [ ] `hr-view-leave` 权限正常显示在 School Admin 权限管理
- [ ] `createHrRole()` 创建新的 HR 角色使用 `hr-view-leave`
- [ ] 旧 `hr-approve-leave` 权限不影响现有页面

### API

- [ ] `GET /api/leaves` 不返回中间状态
- [ ] `POST /api/applyLeaves` 不再要求 HR 审批配置
- [ ] 旧 `PUT leave/hr/status/update` 返回 404
