# STAFF LEAVE 学校级灰度白名单 — 实现报告

> **日期**: 2026-07-10
> **版本**: v1.0

---

## 一、当前学校上下文获取方式

### 1.1 Web 端

**中间件**: `app/Http/Middleware/SwitchDatabase.php`

```php
$school_database_name = Session::get('school_database_name');
Config::set('database.connections.school.database', $school_database_name);
DB::setDefaultConnection('school');
```

### 1.2 API 端

**中间件**: `app/Http/Middleware/APISwitchDatabase.php`

```php
$schoolCode = $request->header('school-code');
$school = School::on('mysql')->where('code', $schoolCode)->first();
Config::set('database.connections.school.database', $school->database_name);
```

### 1.3 Command 端

**方法**: `SchoolDataService::switchToSchoolDatabase($school_id)`

```php
$school_database = School::where('id', $school_id)->pluck('database_name')->first();
Config::set('database.connections.school.database', $school_database);
Session::put('school_database_name', $school_database);
```

### 1.4 统一标识符

所有三种上下文最终都设置同一个配置:

```
config('database.connections.school.database')
```

**选择**: 使用 **学校数据库名** (如 `eschool_saas_15_zixuan`) 作为白名单标识符。

---

## 二、白名单使用 School ID 还是 Database Name

| 选项 | 优势 | 劣势 |
|------|------|------|
| School ID | 语义清晰 | 需额外查询 central schools 表，增加 DB 查询 |
| School Database Name | 无需额外查询，直接从 config 读取 | 需知道数据库名 |

**选择**: **School Database Name**

理由:
- Web/API/Command 三种上下文都通过 `config('database.connections.school.database')` 获取
- 无需每个请求查询 central schools 表
- 项目现有架构未在 tenant DB 中存储 school_id
- Database name 在 tenant 上下文已天然唯一

---

## 三、新环境变量

```bash
# .env
STAFF_LEAVE_TWO_STAGE_ENABLED=false                              # 全局主开关
STAFF_LEAVE_ENABLED_SCHOOL_DATABASES=eschool_saas_15_zixuan      # 白名单（逗号分隔）
```

配置映射:

```php
// config/features.php
'staff_leave_two_stage_enabled' => env('STAFF_LEAVE_TWO_STAGE_ENABLED', false),
'staff_leave_enabled_school_databases' => env('STAFF_LEAVE_ENABLED_SCHOOL_DATABASES', ''),
```

---

## 四、Fail-Closed 行为矩阵

| # | 全局 Flag | 白名单 | 当前学校 | 结果 |
|---|----------|--------|---------|------|
| 1 | false | any | any | **false** (Tier 1 kill) |
| 2 | true | "" (空) | any | **false** (Tier 2 fail-closed) |
| 3 | true | "eschool_saas_15_zixuan" | eschool_saas_15_zixuan + schema OK | **true** |
| 4 | true | "eschool_saas_15_zixuan" | eschool_saas_20_other | **false** (不在白名单) |
| 5 | true | "eschool_saas_15_zixuan" | eschool_saas_15_zixuan + schema incomplete | **false** (Tier 3 schema) |
| 6 | true | "eschool_saas_15_zixuan" | 无法确定 | **false** (fallback) |
| 7 | true | " 15 , 16 " | eschool_saas_15_zixuan + schema OK | **true** (trim + parse) |
| 8 | true | "15,abc,," | eschool_saas_15_zixuan + schema OK | **false** (invalid format, ignores) |

---

## 五、修改文件

| # | 文件 | 变更 |
|---|------|------|
| 1 | `config/features.php` | 新增 `staff_leave_enabled_school_databases` 配置 + 完整文档 |
| 2 | `app/Services/StaffLeave/TwoStageLeaveService.php` | 新增 `parseAllowlist()` 私有方法；`isEnabled()` 改为三级门控；缓存 key 包含 flag + allowlist hash；移除旧 `cacheKey()` 方法 |
| 3 | `app/Console/Commands/InstallHrLeavePermissionForSchool.php` | **P0 Fix**: `syncPermissions()` → `givePermissionTo('hr-view-leave')`，保留现有权限 |
| 4 | `app/Services/SchoolDataService.php` | **P0 Fix**: `createHrRole()` 中 `syncPermissions()` → `givePermissionTo('hr-view-leave')` |
| 5 | `tests/Unit/TwoStageLeaveServiceAllowlistTest.php` | **新增**: 14 个隔离测试 |

---

## 六、测试结果

### 6.1 测试执行

```
$ php vendor/bin/phpunit tests/Unit/TwoStageLeaveServiceAllowlistTest.php --no-coverage

OK (14 tests, 18 assertions)
```

### 6.2 测试覆盖

| 测试 | 场景 | 通过 |
|------|------|------|
| `global_flag_false_returns_false` | Tier 1: 全局 flag=false | ✅ |
| `global_flag_false_even_with_allowlist_returns_false` | flag=false 但白名单有值 | ✅ |
| `allowlist_empty_returns_false` | flag=true, 白名单空 | ✅ |
| `allowlist_null_string_returns_false` | 白名单为空字符串 | ✅ |
| `school_not_in_allowlist_returns_false` | 不在白名单 | ✅ |
| `another_migrated_school_not_in_allowlist_returns_false` | 其他已 migration 学校不在白名单 | ✅ |
| `csv_with_spaces_is_correctly_parsed` | CSV 空格 trim | ✅ |
| `empty_entries_are_ignored` | 空条目忽略 | ✅ |
| `duplicates_are_deduplicated` | 重复去重 | ✅ |
| `non_string_allowlist_returns_false` | 非法值不异常 | ✅ |
| `allowlisted_school_reaches_schema_check` | 通过 Tier 1/2，进入 Tier 3 | ✅ |
| `school_15_true_does_not_pollute_school_16` | 缓存隔离 | ✅ |
| `reset_cache_clears_all_entries` | 缓存清除 | ✅ |
| `config_clear_reacts_to_new_allowlist` | config 变更生效 | ✅ |

---

## 七、HR Role 现有权限保留确认

### 7.1 修复的 P0 Bug

**问题**: `InstallHrLeavePermissionForSchool` 和 `SchoolDataService::createHrRole()` 使用:

```php
$role->syncPermissions(['hr-view-leave']);  // 会删除所有现有权限！
```

**修复**:

```php
$role->givePermissionTo('hr-view-leave');   // 追加，保留现有权限。幂等
```

### 7.2 幂等性保证

| 操作 | 第一次执行 | 第二次执行 |
|------|----------|----------|
| `Permission::firstOrCreate(...)` | 创建 hr-view-leave | 已存在，跳过 |
| `Role::updateOrCreate(...)` | 创建/更新 HR role | 已存在，跳过 |
| `$role->givePermissionTo('hr-view-leave')` | 追加 permission | 已存在，跳过（幂等） |
| Spatie cache clear | 清除 | 清除 |

---

## 八、最终独立 Commit

```
Commit: e9ec8a0ea2af8dee907c5a5fb75f64d6d9848628
Short:  e9ec8a0
Branch: feature-staff-leave-two-stage-approval
Message: Add supervisor-final staff leave workflow with school allowlist
Files:  34 (25 deployable + 8 docs + 1 test)
```

---

## 九、部署 Artifact

| 文件 | 路径 |
|------|------|
| Tar.gz | `staff_leave_school15_e9ec8a0.tar.gz` (163K) |
| Manifest | `docs/STAFF_LEAVE_DEPLOY_MANIFEST.txt` (25 文件) |
| SHA256 | `docs/STAFF_LEAVE_DEPLOY_SHA256.txt` (25 条校验和) |

Manifest 文件数: **25** (19 modified + 6 new)

---

## 十、无关文件

| 状态 | 说明 |
|------|------|
| Commit 中无文件 | Playwright artifacts (~60+ yaml/png)、auth JSON、finance docs **均不在 commit 中** |
| 工作区无文件 | ~50+ Playwright 文件 untracked，**不会被包含在部署 artifact 中** |
| 部署 tar 验证 | `tar -tzf` 输出仅 25 个路径，无 `.env`、`bootstrap/cache`、测试文件 |

---

## 十一、P0/P1/P2 总结

### P0

| # | 问题 | 状态 |
|---|------|------|
| 1 | `syncPermissions` 会删除 HR role 现有权限 | ✅ 已修复（`InstallHrLeavePermissionForSchool` + `SchoolDataService::createHrRole`） |

### P1

| # | 问题 | 状态 |
|---|------|------|
| 1 | 部署文档中 rsync 多文件可能丢失目录结构 | ✅ 已修正为 tar artifact |
| 2 | SQL INSERT 直接写入 Spatie 表 | ✅ 已替换为 Spatie API / Admin UI |
| 3 | 数据库密码在命令行和 shell history | ✅ 已改为 `~/.my.cnf` 或 `MYSQL_PWD` |

### P2

| # | 问题 | 状态 |
|---|------|------|
| 1 | JS 语法检查从 `require('fs')` 改为 `node --check` | ✅ 已修正 |
| 2 | JSON parse 验证未检查错误码 | ✅ 已修正 |
| 3 | README heredoc 日期变量 | ✅ 已修正为双引号 heredoc |
| 4 | `chown -R` 递归修改整个目录 | ✅ 已改为单文件或移除 |

---

## 十二、部署决策

| 决策点 | 答案 |
|--------|------|
| 是否需要 migration | **否** — School 15 已执行 |
| 是否允许重新进入部署准备审查 | **是** — 本文档 + `STAFF_LEAVE_SCHOOL15_GRAY_DEPLOYMENT_PREPARATION.md` 即为最新 |
| 是否允许生产部署 | **否** — 需用户明确批准 |
| 是否允许生产开启 flag | **否** — 需全部前置条件满足 |
