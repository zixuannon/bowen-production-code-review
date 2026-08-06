# Phase 3: Two-Stage Leave Approval — Comprehensive Test Report

**Date**: 2026-07-09  
**Test Environment**: Local, isolated `eschool_test_phase3`  
**Feature Flag**: `STAFF_LEAVE_TWO_STAGE_ENABLED=true`  
**Test Methods**: Chrome/Playwright browser (UI), fetch/JSON (API), direct DB verification, schema manipulation  

---

## 1. Test Environment Summary

| Component | Value |
|-----------|-------|
| Main DB | `eschool` (school record id=1, code=TEST001, database_name=eschool_test_phase3) |
| School DB | `eschool_test_phase3` |
| Server | `php artisan serve --port=8899` |
| APP_ENV | local |
| php.ini | `error_reporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED` |

### Test Users

| User | Email | Role | Staff ID | Supervisor |
|------|-------|------|----------|------------|
| Admin | admin@test.local | School Admin | 1 | None |
| Employee A | employee_a@test.local | Staff | 2 | Supervisor B (user_id=3) |
| Supervisor B | supervisor_b@test.local | Staff | 3 | None |
| Supervisor C | supervisor_c@test.local | Staff | 4 | None |
| Employee D | employee_d@test.local | Staff | 5 | Supervisor C (user_id=4) |
| Employee E | employee_e@test.local | Staff | 6 | None |

### Test Data

| Leave ID | User | Reason | supervisor_status |
|----------|------|--------|-------------------|
| 1001 | Employee A (2) | "Browser Test Pending" | 0 |
| 1002 | Employee A (2) | "Approve Test Target" | 0 |
| 1003 | Employee A (2) | "Reject Test Target" | 0 |
| 1004 | Employee D (5) | "Other supervisor" | 0 |
| 2001 | Supervisor B (3) | "Self-approval test" | 0 |
| 998 | (old) | "Old flow record" | NULL |
| 999 | (old) | "Old flow record" | NULL |

---

## 2. Test Results: API/Logic (Section A)

**Method**: curl + DB verification (from prior test session)

| # | Test Case | Result | Notes |
|---|-----------|--------|-------|
| A1 | Submit leave without supervisor → blocked | ✅ PASS | "no valid direct supervisor" error | 
| A2 | Submit leave with supervisor → created | ✅ PASS | supervisor_status=0, supervisor_user_id=3 |
| A3 | Supervisor sees only own subordinates | ✅ PASS | API filters correctly |
| A4 | Approve → supervisor_status=1, hr_status=0, status=0 | ✅ PASS | State machine correct |
| A5 | Reject → supervisor_status=2, status=2 | ✅ PASS | State machine correct |
| A6 | Self-approve → blocked | ⚠️ N/A | Supervisor B can't submit own leave (has no supervisor) |
| A7 | Repeat approve → blocked | ✅ PASS | lockForUpdate + re-check prevents |
| A8 | Old (NULL) records preserved | ✅ PASS | 998, 999 kept |
| A9 | Old status=1 records OK | ✅ PASS | Not affected |
| A10 | Feature OFF → old flow works | ✅ PASS | Fields stay NULL, no errors |
| A11 | Feature OFF → admin approval works | ✅ PASS | Old flow intact |
| A12 | Feature OFF → no supervisor fields | ✅ PASS | No SQL errors |
| A13 | Dashboard excludes sup-approved | ✅ PASS | status=0 excluded |
| A14 | Reports exclude sup-approved | ✅ PASS | status=0 excluded |
| A15 | FCM notification | ⏸️ SKIP | Requires device |
| A16 | Concurrent approval → one wins | ✅ PASS | lockForUpdate verified |
| A17 | Old API blocks new-flow records | ✅ PASS | 403 on supervisor_status NOT NULL |
| A18 | Supervisor change → old leave unchanged | ✅ PASS | supervisor_user_id immutable |
| A19 | Notification uses leave.supervisor_user_id | ✅ PASS | Code verified |

**Section A**: 17 PASS • 1 N/A • 1 SKIP • 0 FAIL

---

## 3. Test Results: Browser/UI (Section B)

**Method**: Playwright CLI (Chrome), logged in as Supervisor B

### B1: Menu and Access Control

| # | Test Case | Result | Notes |
|---|-----------|--------|-------|
| B1a | Feature ON + has subordinates → menu visible | ✅ PASS | "Supervisor Leave Requests" appears in sidebar (after fixing missing leave permissions) |
| B1b | Feature OFF → menu hidden | ✅ PASS | Verified by code: `@if(config('features...'))` Blade condition |
| B1c | URL direct access control | ✅ PASS | `/leave/supervisor/requests` renders correctly for authorized user |
| B1d | Only own subordinates shown | ✅ PASS | 3 records for user_id=2; user_id=5 (Employee D) not shown |
| B1e | Old-flow records excluded | ✅ PASS | 998, 999 not in table |
| B1f | Pagination/search/sort | ✅ PASS | Bootstrap Table server-side: search, sort, page controls available |

### B2: Approval Workflow (Web UI)

| # | Test Case | Result | Notes |
|---|-----------|--------|-------|
| B2a | Modal content display | ✅ PASS | Leave ID, reason, dates, attachments correctly populated |
| B2b | Approve with comment | ✅ PASS | Leave 1002 → supervisor_status=1, modal closed, table refreshed |
| B2c | Reject with comment | ✅ PASS | Leave 1003 → supervisor_status=2, status=2, table refreshed |
| B2d | No action buttons for reviewed | ✅ PASS | Approved/rejected rows show no edit button |
| B2e | Modal data resets between records | ✅ PASS | Radio unchecked, comment cleared on re-open |
| B2f | Status badges correct | ✅ PASS | "Pending Supervisor" / "Approved by Supervisor" / "Rejected by Supervisor" |

### B3: Error/Edge Cases

| # | Test Case | Result | Notes |
|---|-----------|--------|-------|
| B3a | Console errors | ✅ PASS | Only font 404s (16); zero JS errors |
| B3b | Network 5xx | ✅ PASS | No 5xx during all operations |
| B3c | XSS protection | ✅ PASS | `data-escape="true"` on Bootstrap Table |
| B3d | Server error display | ✅ PASS | Invalid leave ID returns proper JSON error |
| B3e | Form submit re-enable | ⚠️ NOTE | `common.js` disables submit button for 300ms only; re-enables before AJAX completes |

### B4: Translations

| # | Test Case | Result | Notes |
|---|-----------|--------|-------|
| B4a | English labels correct | ✅ PASS | All labels, statuses, buttons in English |
| B4b | Chinese translations exist | ✅ PASS | All 17 supervisor keys in zh-cn.json with Chinese translations |

**Section B**: 17 PASS • 0 FAIL • 1 NOTE

---

## 4. Test Results: Real Concurrent Approval (Section C)

**Method**: Two parallel `fetch()` requests from authenticated browser, targeting same leave ID.

```
Request 1 → {"error":false, "message":"Data Updated Successfully", "code":200}
Request 2 → {"error":true,  "message":"This leave request has already been reviewed.", "code":103}
```

**Database**: `supervisor_status=1, supervisor_comment="req1"` — only first request succeeded.

**Section C**: ✅ PASS — `lockForUpdate()` + in-lock re-check correctly prevents race conditions.

---

## 5. Test Results: Self-Approval Security (Section D)

**Method**: Created leave 2001 (user_id=3, supervisor_user_id=3); Supervisor B tries to approve.

```
Response → {"error":true, "message":"Cannot approve your own leave request.", "code":103}
```

**Database**: `supervisor_status=0` (unchanged)

**Section D**: ✅ PASS — self-approval correctly blocked.

---

## 6. Test Results: Schema Incomplete (Section E)

**Method**: Dropped `hr_status` column from `leaves`, then attempted to approve leave.

**What happened**: `LeaveController::isTwoStageEnabled()` (line 107) checks for `hr_status` column existence via `Schema::connection('school')->hasColumn('leaves', 'hr_status')`. When column is missing, method returns `false`, which causes the in-lock check at line 803. The enhanced check now distinguishes: if feature flag is ON but schema is incomplete → returns `two_stage_schema_incomplete` message.

```
Response → {"error":true, "message":"两级请假审批功能暂时不可用..."(zh) / "The two-stage leave approval feature is temporarily unavailable..."(en), "code":103}
```

**Result**:
- **Crash prevention**: ✅ PASS — missing column does not cause SQL error
- **Error message**: ✅ PASS — returns distinct `two_stage_schema_incomplete` i18n message (zh-cn + en)

| Finding | Severity | Status |
|---------|----------|--------|
| Schema check prevents crash | — | ✅ PASS |
| Error message improved | P2 | ✅ FIXED |

---

## 7. Bugs Found

### Bug #1: `window.baseUrl` undefined → form action broken (P1 - FIXED)

**Location**: `public/assets/js/custom/bootstrap-table/actionEvents.js:1303`

The JS handler for supervisorLeaveEvents used `window.baseUrl` but the project declares `baseUrl` as a global `const` (not `window.baseUrl`). This caused the form action to become `"undefined/leave/supervisor/status/update"`.

**Fix**: Changed `window.baseUrl` → `baseUrl` (committed).

### Bug #2: Success callback does not close `#supervisorModal` (P2 - FIXED)

**Location**: `public/assets/js/custom/common.js:355`

The `.edit-form` success callback hides `#editModal`, `#change-bill`, `#viewModal` but not `#supervisorModal`.

**Fix**: Added `$('#supervisorModal').modal('hide');` to the hide list.

### Bug #3: Missing leave permissions for Staff role (Test Env - FIXED)

**Location**: DB `eschool_test_phase3`

The Staff role only had `approve-leave` but the sidebar requires `@canany(['leave-list', 'leave-create', ...])`. The entire Leave section was hidden.

**Fix**: Added `leave-list`, `leave-create`, `leave-edit`, `leave-delete` permissions to the Staff role.

### Bug #4: PHP 8.5 deprecation warnings polluting JSON (Test Env - FIXED)

**Location**: System `php.ini`

PHP 8.5 emits E_DEPRECATED from Carbon and react/promise, corrupting AJAX JSON responses.

**Fix**: Set `error_reporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED` in system php.ini.

### Bug #5: Schema incomplete error message misleading (P2 - FIXED)

**Location**: `app/Http/Controllers/LeaveController.php`

When `hr_status` column is missing, `isTwoStageEnabled()` returns false, and the error message says "cannot be approved through this endpoint" instead of indicating the schema is incomplete.

**Fix**: Added `two_stage_schema_incomplete` i18n message (zh-cn + en). `supervisorStatusUpdate()` now checks `config('features.staff_leave_two_stage_enabled')` before falling back to the "old endpoint" error. If feature flag is ON but schema check fails, returns the distinct schema-incomplete message.

---

## 8. Summary

| Section | Description | Tests | PASS | FAIL | SKIP |
|---------|-------------|-------|------|------|------|
| A | API/Logic | 19 | 17 | 0 | 1 N/A, 1 SKIP |
| B | Browser/UI | 17 | 17 | 0 | 0 |
| C | Concurrent | 1 | 1 | 0 | 0 |
| D | Self-approval | 1 | 1 | 0 | 0 |
| E | Schema incomplete | 1 | 1 | 0 | 0 |
| **Total** | | **39** | **37** | **0** | **2 N/A/SKIP** |

\* Schema incomplete: crash prevention and error message both verified (PASS).

### Bug Severity Summary

| Severity | Count | Details |
|----------|-------|---------|
| P0 (blocker) | 0 | None |
| P1 (critical) | 1 | `window.baseUrl` undefined (FIXED) |
| P2 (minor) | 2 | Modal close callback, schema error message (both FIXED) |
| Test env | 3 | Permissions, php.ini, school installed flag |

---

## 9. Deployment Recommendations

1. **可以进入 Phase 4**：Phase 3 核心逻辑已全部验证通过，可以开始 Phase 4（HR 最终审批）的设计和实现。

2. **不允许单独部署 Phase 3**：Phase 3 必须与 Phase 4 一起部署。主管批准后 leave 停留在 `status=0`，没有 HR 审批无法完结。

3. **不允许在生产开启功能开关**：在 Phase 4 完成前，生产 `.env` 中 `STAFF_LEAVE_TWO_STAGE_ENABLED` 必须保持 `false`。

4. **FCM 仍未进行真实设备测试**：通知逻辑已验证但未在真实设备上测试推送接收。

5. **测试环境特殊配置不属于生产代码**：
   - `app/Console/Commands/SeedTestPermissions.php` 仅用于测试
   - 本地 `php.ini` 修改（error_reporting）仅解决测试环境 PHP 8.5 兼容性问题
   - 测试数据库权限和测试数据不纳入生产部署

6. **Pre-deployment checklist (Phase 3+4 合并部署时)**：
   - [ ] Run Phase 3 migration on target DB (adds 5 columns)
   - [ ] 完成 Phase 4 HR 审批功能
   - [ ] 确认所有 staff 配置了 `supervisor_user_id`
   - [ ] 确认 Staff 角色拥有 leave 相关权限
   - [ ] `APP_DEBUG=false` in production
   - [ ] `php artisan config:clear` + `php artisan view:clear`
   - [ ] 先试点学校，验证后全量开启

---

## 10. Files Modified During Testing

| File | Change | Reason |
|------|--------|--------|
| `actionEvents.js:1303` | `window.baseUrl` → `baseUrl` | Bug fix |
| `common.js:355` | Added `#supervisorModal` hide | Bug fix |
| `app/Console/Commands/SeedTestPermissions.php` | New file | Test env setup | ❌ NOT FOR PROD |
| `/opt/homebrew/etc/php/8.5/php.ini` | error_reporting change | Test env fix | ❌ NOT FOR PROD |
| DB `eschool_test_phase3` | Added 4 leave permissions to Staff role | Test env fix | ❌ NOT FOR PROD |

---
## 11. Phase 3 Final Freeze — Files NOT for Production

| File / Artifact | Reason |
|-----------------|--------|
| `app/Console/Commands/SeedTestPermissions.php` | 仅用于测试环境权限种子 |
| `/opt/homebrew/etc/php/8.5/php.ini` | 本地开发机 PHP 8.5 兼容性配置 |
| `eschool_test_phase3` 数据库权限/测试数据 | 仅用于测试 |
| `.playwright-cli/` 目录 | 测试工具缓存 |
| `.user.ini` | 本地开发配置 |
