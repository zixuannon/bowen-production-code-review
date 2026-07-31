# STAFF LEAVE 新方案 — School 15 灰度部署准备方案

> **状态**: 部署准备（不执行部署，不修改生产，不开启功能开关）
> **日期**: 2026-07-10
> **版本**: v2 (学校级白名单已实现)

---

## 一、当前版本

| 项目 | 值 |
|------|-----|
| Git 分支 | `feature-staff-leave-two-stage-approval` |
| 独立 Commit SHA | `e9ec8a0ea2af8dee907c5a5fb75f64d6d9848628` |
| Short | `e9ec8a0` |
| Commit 消息 | Add supervisor-final staff leave workflow with school allowlist |
| 文件数 | 34 个 committed (25 部署 + 8 docs + 1 test) |

> ✅ **Status**: 独立 commit 已创建，仅包含 Staff Leave 文件。无 Dify、Finance、缓存文件。

### 无关文件状态

| 类别 | 状况 |
|------|------|
| Playwright 测试产物 | 约 60+ yaml/png 文件，untracked，**不在 commit 中** |
| auth JSON | untracked，**不在 commit 中** |
| 旧 finance docs | 已 stash，**不在 commit 中** |
| 缓存文件 | stash，**不在 commit 中** |
| Dify/Student Profile | **不在 commit 中** |

---

## 二、精确部署文件清单（Allowlist）

### 2.1 必须部署 — 修改文件 (19)

| # | 文件路径 | 变更性质 | 部署必要性 |
|---|---------|---------|-----------|
| 1 | `app/Http/Controllers/Api/ApiController.php` | 新增 `isTwoStageEnabled()`, `applyLeaves` 两阶段修改 | **必须** |
| 2 | `app/Http/Controllers/Api/StaffApiController.php` | 新增 `isTwoStageEnabled()`, `leaveApprove/leaveDelete` 守卫, supervisor approval API | **必须** |
| 3 | `app/Http/Controllers/LeaveController.php` | Supervisor/HR controllers, 审批逻辑, 通知, 守卫 | **必须** |
| 4 | `app/Http/Controllers/StaffController.php` | 新增 `supervisor_user_id` CRUD, supervisor candidates | **必须** |
| 5 | `app/Models/Leave.php` | 新增常量, fillable, dates, relations | **必须** |
| 6 | `app/Models/Staff.php` | 新增 `supervisor_user_id` fillable, relation | **必须** |
| 7 | `app/Models/User.php` | 新增 `subordinates()` relation | **必须** |
| 8 | `app/Services/SchoolDataService.php` | 新增 `hr-view-leave` permission, `createHrRole()` [fix: `givePermissionTo`] | **必须** |
| 9 | `public/assets/js/custom/bootstrap-table/actionEvents.js` | 新增 `supervisorLeaveEvents`, XSS 修复 | **必须** |
| 10 | `public/assets/js/custom/bootstrap-table/formatter.js` | 新增 `escapeHtml/safeUrlAttr`, `leaveStageStatusFormatter`, XSS 加固 | **必须** |
| 11 | `public/assets/js/custom/bootstrap-table/queryParams.js` | 新增 `supervisorLeaveQueryParams`, `hrLeaveQueryParams` | **必须** |
| 12 | `public/assets/js/custom/common.js` | 新增 `#supervisorModal`, `#hrModal` 关闭处理 | **必须** |
| 13 | `resources/lang/en.json` | 所有翻译 key | **必须** |
| 14 | `resources/lang/zh-cn.json` | 所有翻译 key | **必须** |
| 15 | `resources/views/description_modal.blade.php` | `white-space: pre-wrap` 样式修复 | **必须** |
| 16 | `resources/views/layouts/sidebar.blade.php` | 新增 Supervisor + HR 菜单项 (条件渲染) | **必须** |
| 17 | `resources/views/leave/index.blade.php` | `leaveStageStatusFormatter` 替换旧 formatter | **必须** |
| 18 | `resources/views/staff/index.blade.php` | Supervisor 下拉在 staff create/edit/list | **必须** |
| 19 | `routes/web.php` | 新增 supervisor + HR routes | **必须** |

### 2.2 必须部署 — 新文件 (6)

| # | 文件路径 | 说明 |
|---|---------|------|
| 20 | `app/Services/StaffLeave/TwoStageLeaveService.php` | 核心服务：三级门控 + 学校白名单 + HR 通知 |
| 21 | `app/Console/Commands/InstallHrLeavePermissionForSchool.php` | Artisan 命令：单校安装 hr-view-leave [fix: `givePermissionTo` 保留现有权限] |
| 22 | `config/features.php` | Feature flag + 学校白名单配置 |
| 23 | `database/migrations/schools/2026_07_08_000001_add_two_stage_leave_approval.php` | Migration（**仅部署，不运行**） |
| 24 | `resources/views/leave/supervisor_requests.blade.php` | 主管审批页面 |
| 25 | `resources/views/leave/hr_requests.blade.php` | HR 只读查看页面 |

**总计部署文件: 25 个** (详见 `docs/STAFF_LEAVE_DEPLOY_MANIFEST.txt`)

### 2.3 部署方式：使用 tar artifact（推荐，保留目录结构）

> **禁止使用普通 rsync 多文件命令**（容易丢失目录结构）。

```bash
# 本地生成 tar.gz
cd /Users/goldlife/Projects/bowen-production-code-review
tar -czf staff_leave_school15_e9ec8a0.tar.gz -T docs/STAFF_LEAVE_DEPLOY_MANIFEST.txt

# 上传到生产
scp staff_leave_school15_e9ec8a0.tar.gz <user>@<server>:/tmp/

# 生产端解压（保留目录结构）
cd /www/wwwroot/183.240.79.48
tar -xzf /tmp/staff_leave_school15_e9ec8a0.tar.gz

# 验证解压完整性
tar -tzf /tmp/staff_leave_school15_e9ec8a0.tar.gz | sort | diff - <(sort docs/STAFF_LEAVE_DEPLOY_MANIFEST.txt)
```

### 2.4 SHA256 校验文件

`docs/STAFF_LEAVE_DEPLOY_SHA256.txt` — 包含所有 25 个部署文件的 SHA256。部署后在生产端校验：

```bash
cd /www/wwwroot/183.240.79.48
sha256sum -c docs/STAFF_LEAVE_DEPLOY_SHA256.txt --quiet
# 无输出 = 所有文件校验通过
```

### 2.3 严格排除 — 绝不部署

| 类别 | 文件 |
|------|------|
| 缓存文件 | `bootstrap/cache/packages.php`, `bootstrap/cache/services.php` |
| 环境文件 | `.env`, `.user.ini` |
| 已删除 docs | `docs/database-design.md`, `docs/finance_*.md`, `docs/prd.md` |
| 测试脚本 | `tests/phase3_test_setup.sh` |
| 测试 Commands | `app/Console/Commands/Phase3TestSetup.php`, `app/Console/Commands/SeedTestPermissions.php` |
| Playwright | `.playwright-cli/`, 所有 `*.yaml` |
| 测试数据 | `auth_*.json`, `login*.yaml`, `*_menu*.yaml`, etc. |
| 截图 | 所有 `*.png` |
| 本地包 | `packages/` |
| e2e backups | `docs/e2e_backups/` |

---

## 三、Feature Flag — 已实现三级门控 (Fail-Closed)

### 3.1 实现架构

```
STAFF_LEAVE_TWO_STAGE_ENABLED (全局 .env flag, 默认 false)
        │
        ├─ false → 所有学校: false
        │
        └─ true
            │
            └─ STAFF_LEAVE_ENABLED_SCHOOL_DATABASES (学校白名单)
                │
                ├─ 空 → 所有学校: false (fail-closed)
                ├─ 当前学校 DB 不在白名单 → false
                │
                └─ 当前学校 DB 在白名单 (如: eschool_saas_15_zixuan)
                    │
                    └─ Schema 10 字段检查
                        ├─ 不完整 → false
                        └─ 完整 → true
```

### 3.2 实现位置

`app/Services/StaffLeave/TwoStageLeaveService.php`:

```php
public static function isEnabled(): bool
{
    // Tier 1: 全局开关 (master kill switch)
    if (!config('features.staff_leave_two_stage_enabled')) return false;

    // Tier 2: 学校白名单 (fail-closed — 空列表禁用所有)
    $allowlist = self::parseAllowlist($raw);
    if (empty($allowlist)) return false;
    if (!in_array($schoolDb, $allowlist)) return false;

    // Tier 3: Schema 完整性 (10 columns)
    return Schema::connection('school')->hasColumn('staffs', 'supervisor_user_id')
        && /* ... 9 more columns ... */;
}
```

### 3.3 新环境变量

```bash
# .env (生产)
STAFF_LEAVE_TWO_STAGE_ENABLED=false          # 全局主开关
STAFF_LEAVE_ENABLED_SCHOOL_DATABASES=eschool_saas_15_zixuan  # 白名单（逗号分隔）
```

### 3.4 关键结论

| 问题 | 答案 |
|------|------|
| 当前 feature flag 是全局还是学校级？ | **全局 AND 学校级** — 必须两者都满足才启用 |
| 设置 true 是否影响所有学校？ | **否** — 白名单为空时所有学校为 false（fail-closed） |
| 如何确保只开启 School 15？ | 设置 `STAFF_LEAVE_ENABLED_SCHOOL_DATABASES=eschool_saas_15_zixuan` |
| 是否需要新增 school allowlist？ | **已实现** (`TwoStageLeaveService::parseAllowlist()`) |
| 是否需要代码改造？ | **已完成** |
| 是否需要新的 migration？ | **否** |
| 安全等级 | **Fail-closed** — 任何不确定情况 → false |
| 白名单为空 = 全部开启？ | **否** — 必须显式列出才能启用 |
| 识别方式 | 学校数据库名 (如 `eschool_saas_15_zixuan`)，Web/API/Command 一致 |

---

## 四、生产只读检查（命令列表，不执行）

### 4.1 基础环境检查

```bash
# 进入生产目录
cd /www/wwwroot/183.240.79.48

# 1. 生产 APP_ENV
grep "^APP_ENV=" .env

# 2. 生产 APP_URL
grep "^APP_URL=" .env

# 3. STAFF_LEAVE_TWO_STAGE_ENABLED 当前值
grep "STAFF_LEAVE_TWO_STAGE_ENABLED" .env

# 4. PHP 版本
php -v

# 5. PHP-FPM 服务状态
/etc/init.d/php-fpm-82 status

# 6. 文件权限和 owner
ls -la
ls -la bootstrap/cache/
```

### 4.2 School 15 数据库检查

```sql
-- 连接到生产数据库: sql_43_160_241_126

-- 5. School 15 数据库名称确认
SELECT id, name, code, database_name FROM schools WHERE id = 15;

-- 6. migrations 记录确认 (school DB)
SELECT migration, batch FROM eschool_saas_15_zixuan.migrations 
WHERE migration LIKE '%two_stage%';

-- 7. staffs.supervisor_user_id 是否存在
SHOW COLUMNS FROM eschool_saas_15_zixuan.staffs LIKE 'supervisor_user_id';

-- 8. leaves 新字段是否完整
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'eschool_saas_15_zixuan'
  AND TABLE_NAME = 'leaves'
  AND COLUMN_NAME IN (
    'supervisor_status', 'supervisor_comment', 'supervisor_user_id', 
    'supervisor_reviewed_at', 'hr_status', 'hr_comment', 
    'hr_user_id', 'hr_reviewed_at', 'withdrawn_at'
  )
ORDER BY ORDINAL_POSITION;
```

### 4.3 遗留数据检查

```sql
-- 使用 eschool_saas_15_zixuan 数据库

-- 9. 是否存在旧 HR pending 遗留数据
-- (supervisor_status=1 AND hr_status=0 AND status=0)
-- 这是 Phase 4 测试期间未终审的记录
SELECT 
    id, user_id, reason, from_date, to_date, 
    status, supervisor_status, hr_status, 
    supervisor_user_id, supervisor_reviewed_at,
    created_at
FROM leaves 
WHERE supervisor_status = 1 
  AND hr_status = 0 
  AND status = 0;

-- 10. 是否存在未配置主管的员工
SELECT s.id, s.user_id, u.first_name, u.last_name, u.email, s.supervisor_user_id
FROM staffs s
JOIN users u ON u.id = s.user_id
WHERE s.supervisor_user_id IS NULL
  AND u.status = 1
  AND u.deleted_at IS NULL;

-- 11. 主管指向不存在用户的记录
SELECT s.id, s.user_id, u.first_name, u.last_name, s.supervisor_user_id
FROM staffs s
JOIN users u ON u.id = s.user_id
LEFT JOIN users sup ON sup.id = s.supervisor_user_id
WHERE s.supervisor_user_id IS NOT NULL
  AND sup.id IS NULL;

-- 12. 主管指向停用用户的记录
SELECT s.id, s.user_id, u.first_name, u.last_name, s.supervisor_user_id
FROM staffs s
JOIN users u ON u.id = s.user_id
JOIN users sup ON sup.id = s.supervisor_user_id
WHERE sup.status != 1 OR sup.deleted_at IS NOT NULL;
```

### 4.4 权限/角色检查

```sql
-- 13. 是否已经存在 hr-view-leave permission
SELECT * FROM permissions WHERE name = 'hr-view-leave';

-- 14. 是否存在 HR role
SELECT r.* FROM roles r WHERE r.name = 'HR' AND r.school_id = 15;

-- 15. 是否已有用户拥有 hr-view-leave
SELECT u.id, u.first_name, u.last_name, u.email, u.status
FROM users u
JOIN model_has_roles mhr ON mhr.model_id = u.id AND mhr.model_type = 'App\\Models\\User'
JOIN role_has_permissions rhp ON rhp.role_id = mhr.role_id
JOIN permissions p ON p.id = rhp.permission_id
WHERE p.name = 'hr-view-leave'
  AND u.school_id = 15;
```

---

## 五、部署前 School 15 DB 备份方案

### 5.1 建议备份目录

```bash
BACKUP_DIR="/root/backups/staff_leave_supervisor_final_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
```

### 5.2 备份命令（只输出，不执行）

> ⚠️ **密码安全**: 使用 `~/.my.cnf` 或 `MYSQL_PWD` 环境变量，或在提示符下输入。**不得将密码写入命令行 `-p<password>` 参数**（会泄漏到 `~/.bash_history` 和 `ps aux`）。

```bash
# === 预检查 ===
echo "Backup target: $BACKUP_DIR"
echo "Current date: $(date)"
echo "=== WARNING: Verify this is the PRODUCTION server ==="

# MySQL 认证方式（选其一）：
# A) 使用 ~/.my.cnf
#    创建文件 ~/.my.cnf，内容:
#    [client]
#    user=<db_user>
#    password=<db_password>
#    chmod 600 ~/.my.cnf
#    然后在命令中省略 -u -p 参数

# B) 使用环境变量（仅当前 shell 有效）
#    export MYSQL_PWD='<db_password>'
#    然后命令中使用 -u <db_user>（不带 -p）

# === 1. 代码备份 ===
cd /www/wwwroot/183.240.79.48
tar -czf "$BACKUP_DIR/code_backup.tar.gz" \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='storage/logs' \
    --exclude='storage/framework/cache' \
    --exclude='.git' \
    .

# === 2. School 15 DB 完整备份 ===
mysqldump --single-transaction --routines --triggers \
    -u <db_user> \
    eschool_saas_15_zixuan \
    > "$BACKUP_DIR/eschool_saas_15_zixuan_full.sql"

# === 3. 权限相关表备份 ===
mysqldump --single-transaction \
    -u <db_user> \
    eschool_saas_15_zixuan \
    permissions \
    roles \
    role_has_permissions \
    model_has_roles \
    model_has_permissions \
    > "$BACKUP_DIR/eschool_saas_15_zixuan_permissions.sql"

# === 4. leaves 表备份 ===
mysqldump --single-transaction \
    -u <db_user> \
    eschool_saas_15_zixuan \
    leaves \
    leave_details \
    > "$BACKUP_DIR/eschool_saas_15_zixuan_leave_tables.sql"

# === 5. staffs 表备份 ===
mysqldump --single-transaction \
    -u <db_user> \
    eschool_saas_15_zixuan \
    staffs \
    > "$BACKUP_DIR/eschool_saas_15_zixuan_staffs.sql"

# === 6. 备份校验 ===
echo "=== Backup verification ==="
ls -lh "$BACKUP_DIR/"
for f in "$BACKUP_DIR"/*.sql; do
    echo "Checking: $f"
    tail -1 "$f" | grep -q "Dump completed" && echo "  VALID" || echo "  WARNING: May be incomplete"
done

# === 7. 记录备份信息（变量展开） ===
BACKUP_TIMESTAMP="$(date '+%Y-%m-%d %H:%M:%S')"
cat > "$BACKUP_DIR/README.txt" << HEREDOC
Backup created: ${BACKUP_TIMESTAMP}
Feature: Staff Leave Supervisor Final Approval
School: 15 (Zixuan / SCH202615 / eschool_saas_15_zixuan)
Branch: feature-staff-leave-two-stage-approval
Commit: e9ec8a0ea2af8dee907c5a5fb75f64d6d9848628
HEREDOC
```

### 5.3 恢复命令

```bash
# 恢复 School 15 完整数据库
mysql -u <db_user> eschool_saas_15_zixuan \
    < /root/backups/staff_leave_supervisor_final_<timestamp>/eschool_saas_15_zixuan_full.sql

# 仅恢复权限表
mysql -u <db_user> eschool_saas_15_zixuan \
    < /root/backups/staff_leave_supervisor_final_<timestamp>/eschool_saas_15_zixuan_permissions.sql

# 恢复代码
cd /www/wwwroot/183.240.79.48
tar -xzf /root/backups/staff_leave_supervisor_final_<timestamp>/code_backup.tar.gz
```

---

## 六、Flag=false 代码部署步骤

> **前提**: `STAFF_LEAVE_TWO_STAGE_ENABLED=false` 已在生产 `.env` 中确认

### 6.1 部署前确认

```bash
cd /www/wwwroot/183.240.79.48

# 1. 确认路径正确
pwd && ls -la artisan

# 2. 确认 flag 为 false
grep "STAFF_LEAVE_TWO_STAGE_ENABLED" .env
# 预期输出: STAFF_LEAVE_TWO_STAGE_ENABLED=false (或行不存在，默认 false)

# 3. 确认白名单已配置
grep "STAFF_LEAVE_ENABLED_SCHOOL_DATABASES" .env

# 4. 确认 PHP 版本
php -v | head -1
```

### 6.2 上传方式：tar artifact（保留目录结构）

```bash
# 在本地执行
cd /Users/goldlife/Projects/bowen-production-code-review

# 确保已生成 tar.gz（基于 manifest）
tar -czf staff_leave_school15_e9ec8a0.tar.gz -T docs/STAFF_LEAVE_DEPLOY_MANIFEST.txt

# 验证 tar 内容
tar -tzf staff_leave_school15_e9ec8a0.tar.gz | head -25

# 上传到生产
scp staff_leave_school15_e9ec8a0.tar.gz <user>@<server>:/tmp/

# ----- 在生产服务器执行 -----
cd /www/wwwroot/183.240.79.48

# 解压（保留完整相对路径，如 app/Services/StaffLeave/TwoStageLeaveService.php）
tar -xzf /tmp/staff_leave_school15_e9ec8a0.tar.gz

# 验证解压完整性
diff <(tar -tzf /tmp/staff_leave_school15_e9ec8a0.tar.gz | sort) \
     <(find . -type f -newer /tmp/staff_leave_school15_e9ec8a0.tar.gz | sed 's|^\./||' | sort)
```

### 6.3 部署后检查

```bash
cd /www/wwwroot/183.240.79.48

# 5. 检查 owner/group（只检查本次部署的目录）
# ⚠️ 禁止递归 chown 整个 app/config/resources 目录！
ls -la app/Services/StaffLeave/TwoStageLeaveService.php
ls -la config/features.php

# 仅在必要时修复单个文件的 owner
# chown www:www app/Services/StaffLeave/TwoStageLeaveService.php

# 6. PHP 语法检查（逐个文件）
php -l app/Http/Controllers/Api/ApiController.php
php -l app/Http/Controllers/Api/StaffApiController.php
php -l app/Http/Controllers/LeaveController.php
php -l app/Http/Controllers/StaffController.php
php -l app/Models/Leave.php
php -l app/Models/Staff.php
php -l app/Models/User.php
php -l app/Services/SchoolDataService.php
php -l app/Services/StaffLeave/TwoStageLeaveService.php
php -l app/Console/Commands/InstallHrLeavePermissionForSchool.php
php -l config/features.php
php -l routes/web.php

# 7. JSON parse 检查（验证错误码）
php -r "\$d = json_decode(file_get_contents('resources/lang/en.json'), true); if (json_last_error() !== JSON_ERROR_NONE) { echo 'en.json: INVALID ' . json_last_error_msg() . PHP_EOL; exit(1); } echo 'en.json: VALID (' . count(\$d) . ' keys)' . PHP_EOL;"
php -r "\$d = json_decode(file_get_contents('resources/lang/zh-cn.json'), true); if (json_last_error() !== JSON_ERROR_NONE) { echo 'zh-cn.json: INVALID ' . json_last_error_msg() . PHP_EOL; exit(1); } echo 'zh-cn.json: VALID (' . count(\$d) . ' keys)' . PHP_EOL;"

# 8. JS 语法检查（使用 node --check）
node --check public/assets/js/custom/bootstrap-table/formatter.js && echo "formatter.js: OK"
node --check public/assets/js/custom/bootstrap-table/actionEvents.js && echo "actionEvents.js: OK"
node --check public/assets/js/custom/bootstrap-table/queryParams.js && echo "queryParams.js: OK"
node --check public/assets/js/custom/common.js && echo "common.js: OK"

# 9. route:list (确认新路由)
php artisan route:list 2>&1 | grep "leave/supervisor\|leave/hr"

# 10. 清理缓存
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
php artisan config:cache  # 生产建议 config:cache

# 11. 清除 Spatie 权限缓存 (可选)
php artisan permission:cache-reset

# 12. 必要时重启 PHP-FPM 8.2
/etc/init.d/php-fpm-82 restart

# 13. 确认返回正常
curl -s -o /dev/null -w "%{http_code}" https://school.mmbowen.com/login
# 预期: 200
```

### 6.4 部署后禁止事项

| 禁止操作 | 原因 |
|---------|------|
| ❌ `php artisan migrate` | Migration 已在 School 15 执行 |
| ❌ `php artisan migrate:school` | 会循环所有学校 |
| ❌ 手动设置 `STAFF_LEAVE_TWO_STAGE_ENABLED=true` | 灰度未完成 |
| ❌ 修改生产数据库任何记录 | 风险 |
| ❌ 删除备份 | 回滚需要 |
| ❌ `chown -R www:www app/` | 递归修改会影响其他文件的权限 |
| ❌ `chown -R www:www config/` | 同上 |

---

## 七、School 15 权限安装

### 7.1 DRY RUN 命令（先执行）

```bash
cd /www/wwwroot/183.240.79.48

php artisan school:install-hr-leave-view-permission --school-id=15 --dry-run
```

**预期 dry-run 输出**:
```
========================================
  Install HR View Leave Permission
========================================
  School ID      : 15
  School Name    : Zixuan
  Database       : eschool_saas_15_zixuan
  Dry Run        : YES
========================================
[DRY RUN] Would create permission: hr-view-leave
[DRY RUN] Would optionally create/update HR role with hr-view-leave
[DRY RUN] No changes made.
```

### 7.2 正式安装命令（取得批准后执行）

```bash
cd /www/wwwroot/183.240.79.48

php artisan school:install-hr-leave-view-permission --school-id=15
```

### 7.3 安装后验证

```sql
-- 使用 eschool_saas_15_zixuan 数据库

-- 确认 permission 已创建
SELECT * FROM permissions WHERE name = 'hr-view-leave';
-- 预期: 1 行，name='hr-view-leave', guard_name='web'

-- 确认 HR role 已创建/更新
SELECT * FROM roles WHERE name = 'HR' AND school_id = 15;
-- 预期: 1 行

-- 确认 HR role 包含 hr-view-leave（并保留所有现有权限）
SELECT p.name 
FROM role_has_permissions rhp
JOIN permissions p ON p.id = rhp.permission_id
WHERE rhp.role_id = (SELECT id FROM roles WHERE name = 'HR' AND school_id = 15)
ORDER BY p.name;
-- 预期: 至少包含 'hr-view-leave'，且原有权限必须全部保留

-- 确认没有自动分配用户
SELECT COUNT(*) FROM model_has_roles 
WHERE role_id = (SELECT id FROM roles WHERE name = 'HR' AND school_id = 15);
-- 预期: 0 (用户需手动分配)
```

### 7.4 权限安装安全保证

| 保证 | 说明 |
|------|------|
| 仅 school-id=15 | `InstallHrLeavePermissionForSchool` 单校操作 |
| 不循环其他学校 | 无循环逻辑 |
| 不自动分配用户 | 代码中 `model_has_roles` 无操作 |
| 不自动赋予 School Admin | School Admin 不自动获得 hr-view-leave |
| 不删除旧 hr-approve-leave | permission 记录保留在原位 |
| 保留 HR role 现有权限 | `givePermissionTo('hr-view-leave')` **追加而非替换**（修复 P0 bug: 旧版 `syncPermissions` 会清空） |
| 不运行 migration | 仅操作 permissions/roles 表 |
| 执行幂等 | `firstOrCreate` + `updateOrCreate` + `givePermissionTo` 均可重复执行 |

---

## 八、HR 查看用户配置

### 8.1 前置检查：确认 tenant DB 表结构

```sql
-- 使用 eschool_saas_15_zixuan 数据库
-- 必须先确认以下字段是否存在，不得假设

-- 检查 users 表是否有 school_id 字段
SELECT COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'eschool_saas_15_zixuan'
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME = 'school_id';

-- 检查 roles 表是否有 school_id 字段
SELECT COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'eschool_saas_15_zixuan'
  AND TABLE_NAME = 'roles'
  AND COLUMN_NAME = 'school_id';

-- 列出 Spatie 权限相关表结构
SHOW TABLES LIKE '%permission%';
SHOW TABLES LIKE '%role%';
```

### 8.2 只读查询（School 15 用户列表）

```sql
-- 使用 eschool_saas_15_zixuan 数据库

-- School 15 用户列表
-- 注意: 如果 users 表无 school_id，改用其他过滤条件
SELECT 
    u.id, 
    u.first_name, 
    u.last_name, 
    u.email, 
    u.status,
    CASE WHEN u.status = 1 THEN 'Active' ELSE 'Inactive' END AS status_text,
    s.id AS staff_id
FROM users u
LEFT JOIN staffs s ON s.user_id = u.id
WHERE u.deleted_at IS NULL
ORDER BY u.first_name;
```

### 8.3 当前角色查询

```sql
-- 使用 eschool_saas_15_zixuan 数据库

-- 用户及其角色
SELECT 
    u.id, u.first_name, u.last_name, u.email, u.status,
    GROUP_CONCAT(r.name SEPARATOR ', ') AS roles,
    CASE WHEN EXISTS (
        SELECT 1 FROM model_has_roles mhr2
        JOIN role_has_permissions rhp2 ON rhp2.role_id = mhr2.role_id
        JOIN permissions p2 ON p2.id = rhp2.permission_id
        WHERE mhr2.model_id = u.id 
          AND mhr2.model_type = 'App\\Models\\User'
          AND p2.name = 'hr-view-leave'
    ) THEN 'YES' ELSE 'NO' END AS has_hr_view_leave
FROM users u
LEFT JOIN model_has_roles mhr ON mhr.model_id = u.id AND mhr.model_type = 'App\\Models\\User'
LEFT JOIN roles r ON r.id = mhr.role_id
WHERE u.deleted_at IS NULL
GROUP BY u.id
ORDER BY u.first_name;
```

### 8.4 建议操作步骤

> **需用户明确指定 HR 用户后才能执行，禁止自动猜测或分配。**

分配 HR 权限的正确方式（任选其一）：

**方式 A: 通过 Spatie API（推荐）**
```bash
# 生产端使用 tinker 或编写单次 Command
php artisan tinker
> $user = \App\Models\User::find(<user_id>);
> $user->assignRole('HR');  # 或 $user->givePermissionTo('hr-view-leave');
```

**方式 B: 通过后台 Admin UI**
- 登录 School Admin 账号
- 进入 User Management → 选择目标用户
- 在 Roles/Permissions 标签页分配 HR role 或 hr-view-leave permission

> ⚠️ **禁止直接 INSERT SQL**: 直接 INSERT `model_has_roles` / `role_has_permissions` 表会绕过 Spatie 的缓存和事件系统，导致权限不一致。

---

## 九、直属主管完整性检查

### 9.1 只读统计查询

```sql
-- 使用 eschool_saas_15_zixuan 数据库

-- 1. School 15 活跃 Staff 总数
SELECT COUNT(*) AS total_active_staff
FROM staffs s
JOIN users u ON u.id = s.user_id
WHERE u.school_id = 15 AND u.status = 1 AND u.deleted_at IS NULL;

-- 2. 已配置 supervisor_user_id 数量
SELECT COUNT(*) AS staff_with_supervisor
FROM staffs s
JOIN users u ON u.id = s.user_id
WHERE u.school_id = 15 AND u.status = 1 AND u.deleted_at IS NULL
  AND s.supervisor_user_id IS NOT NULL;

-- 3. 未配置主管数量
SELECT COUNT(*) AS staff_without_supervisor
FROM staffs s
JOIN users u ON u.id = s.user_id
WHERE u.school_id = 15 AND u.status = 1 AND u.deleted_at IS NULL
  AND s.supervisor_user_id IS NULL;

-- 4. 主管指向不存在用户的数量
SELECT COUNT(*) AS invalid_supervisor_refs
FROM staffs s
JOIN users u ON u.id = s.user_id
LEFT JOIN users sup ON sup.id = s.supervisor_user_id
WHERE u.school_id = 15 AND u.deleted_at IS NULL
  AND s.supervisor_user_id IS NOT NULL
  AND sup.id IS NULL;

-- 5. 主管指向停用用户的数量
SELECT COUNT(*) AS inactive_supervisor_refs
FROM staffs s
JOIN users u ON u.id = s.user_id
JOIN users sup ON sup.id = s.supervisor_user_id
WHERE u.school_id = 15 AND u.deleted_at IS NULL
  AND (sup.status != 1 OR sup.deleted_at IS NOT NULL);

-- 6. 自己是自己主管的数量
SELECT COUNT(*) AS self_supervisor
FROM staffs s
JOIN users u ON u.id = s.user_id
WHERE u.school_id = 15
  AND s.supervisor_user_id = s.user_id;

-- 7. A↔B 循环 (两个员工互为主管)
SELECT 
    a.user_id AS staff_a_user_id,
    ua.first_name AS staff_a_name,
    a.supervisor_user_id AS a_supervisor,
    b.user_id AS staff_b_user_id,
    ub.first_name AS staff_b_name,
    b.supervisor_user_id AS b_supervisor
FROM staffs a
JOIN staffs b ON b.supervisor_user_id = a.user_id AND b.user_id = a.supervisor_user_id
JOIN users ua ON ua.id = a.user_id
JOIN users ub ON ub.id = b.user_id
WHERE a.user_id < b.user_id;

-- 8. 多级循环 (需递归 CTE 或应用层检测)
-- 以下为简化的疑似检测
SELECT 
    s.user_id, u.first_name, u.last_name
FROM staffs s
JOIN users u ON u.id = s.user_id
JOIN staffs sup ON sup.user_id = s.supervisor_user_id
WHERE s.supervisor_user_id = sup.user_id
  AND u.school_id = 15;
```

### 9.2 每位主管的下属数量

```sql
-- 使用 eschool_saas_15_zixuan 数据库

SELECT 
    sup.id AS supervisor_user_id,
    sup.first_name AS supervisor_first_name,
    sup.last_name AS supervisor_last_name,
    sup.email AS supervisor_email,
    COUNT(s.id) AS subordinate_count,
    GROUP_CONCAT(u.first_name, ' ', u.last_name ORDER BY u.first_name SEPARATOR ', ') AS subordinates
FROM users sup
JOIN staffs s ON s.supervisor_user_id = sup.id
JOIN users u ON u.id = s.user_id AND u.deleted_at IS NULL
WHERE sup.deleted_at IS NULL
GROUP BY sup.id, sup.first_name, sup.last_name, sup.email
ORDER BY subordinate_count DESC;
```

### 9.3 未配置主管员工清单

```sql
-- 使用 eschool_saas_15_zixuan 数据库

SELECT 
    s.id AS staff_id,
    u.id AS user_id,
    u.first_name,
    u.last_name,
    u.email,
    u.status
FROM staffs s
JOIN users u ON u.id = s.user_id
WHERE u.school_id = 15
  AND u.status = 1
  AND u.deleted_at IS NULL
  AND s.supervisor_user_id IS NULL
ORDER BY u.first_name;
```

---

## 十、Flag=false 生产 Smoke Test

> 代码部署后、flag=false 时，在浏览器执行以下测试：

### 10.1 测试清单

| # | 测试项 | URL/端点 | 预期结果 |
|---|--------|---------|---------|
| 1 | 首页 | `GET /` | 正常加载 |
| 2 | 登录 | `POST /login` | 成功登录 |
| 3 | Staff 列表 | `GET /staff` | 列表加载，新字段不显示(flag=false) |
| 4 | Staff create | `GET /staff/create` | 表单正常，supervisor 下拉不出现 |
| 5 | Staff edit | `PUT /staff/{id}` | 编辑正常 |
| 6 | Leave 列表 | `GET /leave` | 列表加载，旧 status formatter |
| 7 | 旧员工提交 Leave | `POST /leave` | 提交成功，旧流程 |
| 8 | 旧管理员审批 | `PUT /leave/status/update` | 审批成功 |
| 9 | API getLeaves | `GET /api/staff/leaves` | 返回数据正常 |
| 10 | API applyLeaves | `POST /api/staff/leave-request` | 提交成功 |
| 11 | Payroll | `GET /payroll` | 正常 |
| 12 | Dashboard | `GET /dashboard` | 正常 |
| 13 | Report | `GET /leave/report` | 正常 |
| 14 | Console/Network | DevTools | 无 JS 错误, 无 500 |
| 15 | 中英文切换 | 语言下拉 | en.json/zh-cn.json 正常 |
| 16 | 新菜单隐藏 | Sidebar | Supervisor/HR 菜单项不出现 |
| 17 | 无 Unknown column | 所有 Leave 页面 | 无 SQL 错误 |
| 18 | 无 500 | 所有操作 | 无 500 |

### 10.2 确认无 Unknown column 错误

```bash
# 检查生产日志
tail -100 /www/wwwroot/183.240.79.48/storage/logs/laravel.log | grep -i "unknown column\|supervisor_status\|supervisor_user_id"
# 预期: 空输出（flag=false 时不查询新字段）
```

---

## 十一、灰度开启前置条件 CheckList

> 只有以下**全部**完成，才允许讨论 flag=true：

| # | 条件 | 状态 |
|---|------|------|
| 1 | ✅ 精确代码部署完成 | 待执行 |
| 2 | ✅ flag=false smoke test 通过 | 待执行 |
| 3 | ✅ School 15 permission 安装完成 | 待执行 |
| 4 | ✅ HR 查看用户已人工确认并分配 | 待执行 |
| 5 | ✅ 活跃 Staff 直属主管完整性已检查 | 待执行 |
| 6 | ✅ 异常主管关系已处理 | 待执行 |
| 7 | ✅ School 15 遗留 HR pending 数量为 0 | 待确认 |
| 8 | ✅ 新备份有效 | 待执行 |
| 9 | ✅ 回滚步骤已验证 | 待验证 |
| 10 | ✅ 用户明确批准开启 | **待批准** |

---

## 十二、回滚方案

### A. 代码部署后、flag=false 的回滚

> 最简单：恢复备份的代码目录，并删除本次新增文件

```bash
cd /www/wwwroot/183.240.79.48

# 1. 恢复代码备份
tar -xzf /root/backups/staff_leave_supervisor_final_<timestamp>/code_backup.tar.gz

# 2. 删除本次新增的 6 个文件（备份中不存在）
rm -f app/Services/StaffLeave/TwoStageLeaveService.php
rm -f app/Console/Commands/InstallHrLeavePermissionForSchool.php
rm -f config/features.php
rm -f database/migrations/schools/2026_07_08_000001_add_two_stage_leave_approval.php
rm -f resources/views/leave/supervisor_requests.blade.php
rm -f resources/views/leave/hr_requests.blade.php
# 清理空目录
rmdir app/Services/StaffLeave/ 2>/dev/null

# 3. 清除缓存
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
/etc/init.d/php-fpm-82 restart
```

### B. 权限安装后的回滚

```bash
# 可选：恢复权限表备份
# 注意：不强制回滚，hr-view-leave 留存在 DB 通常无业务影响

# 如果要恢复:
cd /www/wwwroot/183.240.79.48
# 恢复权限表备份（使用 ~/.my.cnf 或 MYSQL_PWD 环境变量）
mysql -u <db_user> eschool_saas_15_zixuan \
    < /root/backups/staff_leave_supervisor_final_<timestamp>/eschool_saas_15_zixuan_permissions.sql

php artisan config:clear
/etc/init.d/php-fpm-82 restart
```

### C. flag=true 后的紧急回滚

> **优先回滚方式**（最快，1 分钟内）：

```bash
cd /www/wwwroot/183.240.79.48

# 1. 立即将 flag 恢复 false
sed -i 's/STAFF_LEAVE_TWO_STAGE_ENABLED=true/STAFF_LEAVE_TWO_STAGE_ENABLED=false/' .env

# 2. 清空白名单（可选，但推荐作为双重保险）
sed -i 's/^STAFF_LEAVE_ENABLED_SCHOOL_DATABASES=.*/STAFF_LEAVE_ENABLED_SCHOOL_DATABASES=/' .env

# 3. 清除配置缓存
php artisan config:clear
php artisan config:cache

# 4. 重启 PHP-FPM
/etc/init.d/php-fpm-82 restart
```

> **说明**:
> - 不自动删除 permission/role — 留存在 DB 不影响旧流程
> - 不自动回滚数据库字段 — 数据保留，不会被查询
> - 已批准的 Leave 数据保留，`supervisor_status` 字段不影响旧流程显示
> - 如需完整回滚代码，再执行方案 A

---

## 十三、风险矩阵

| 风险 | 影响 | 概率 | 缓解措施 |
|------|------|------|---------|
| 其他学校意外 migration | 中 | 低 | 新增 school allowlist + schema 检测 |
| 未配置主管员工无法提交 | 高 | 中 | 预先统计并修复未配置情况 |
| 主管指向无效用户 | 高 | 低 | 预先统计 |
| 遗留 HR pending Phase 4 数据 | 低 | 低 | 预先清空 |
| 多学校共享代码 → 全局 flag 影响 | **高** | **高** | **不可直接设置全局 true，必须先改造为学校级** |
| PHP 8.2 语法兼容 | 低 | 低 | 已验证，均使用 8.x 语法 |
| 加载旧 Blade 缓存 | 中 | 低 | `view:clear` |
| JS 浏览器缓存 | 中 | 中 | 强制刷新 (Ctrl+Shift+R) |

---

## 十四、明确禁止事项

| # | 禁止 | 原因 |
|---|------|------|
| 1 | ❌ 运行 `php artisan migrate` | 已在 School 15 执行 |
| 2 | ❌ 运行 `php artisan migrate:school` | 循环所有学校 |
| 3 | ❌ 修改生产数据库 | 风险 |
| 4 | ❌ 设置 `STAFF_LEAVE_TWO_STAGE_ENABLED=true` | 需先完成全部前置条件 |
| 5 | ❌ 部署 `.env`, 缓存, vendor | 环境差异 |
| 6 | ❌ 部署测试文件/截图 | 安全/空间 |
| 7 | ❌ 自动分配 HR 用户 | 需人工确认 |
| 8 | ❌ 删除旧 `hr-approve-leave` permission | 旧流程兼容 |
| 9 | ❌ 删除旧 HR role 绑定 | 旧流程兼容 |
| 10 | ❌ `chown -R www:www app/` | 递归修改影响其他文件 |
| 11 | ❌ 数据库密码写入命令行 | 泄漏到 shell history |
| 12 | ❌ 直接 SQL INSERT 分配角色 | 绕过 Spatie 缓存 |
| 13 | ❌ 使用 `syncPermissions()` 更新 HR role | 会清空现有权限（已修复） |

---

## 十五、最终结论

| # | 问题 | 答案 |
|---|------|------|
| 1 | 当前分支与独立 Commit | `feature-staff-leave-two-stage-approval` @ `e9ec8a0` |
| 2 | 精确部署文件数 | **25** (详见 `docs/STAFF_LEAVE_DEPLOY_MANIFEST.txt`) |
| 3 | 是否存在无关文件 | **否** — commit 仅含 Staff Leave 文件。Playwright 产物 untracked |
| 4 | School 15 是否需要 migration | **否** — 已在 School 15 单独执行 |
| 5 | Feature flag 是全局还是学校级 | **三级门控**: 全局 flag → 学校白名单 → schema。Fail-closed |
| 6 | 是否能只开启 School 15 | **是** — 设置 `STAFF_LEAVE_ENABLED_SCHOOL_DATABASES=eschool_saas_15_zixuan` |
| 7 | 是否需要代码改造 | **已完成** — 学校白名单已实现在 `TwoStageLeaveService` 中 |
| 8 | 白名单识别方式 | 学校数据库名，Web/API/Command 一致 |
| 9 | 白名单为空的行为 | 所有学校为 false（fail-closed） |
| 10 | 回滚核心步骤 | ① flag→false, ② 清空白名单, ③ config:clear, ④ 重启 PHP-FPM |
| 11 | 是否允许开始生产代码部署 | **否** — 需用户确认并批准 |
| 12 | 是否允许开启功能开关 | **否** — 需所有前置条件满足 |

---

## 十六、待用户决定

| # | 决定项 | 说明 |
|---|--------|------|
| 1 | ~~是否接受学校级白名单改造~~ | ✅ 已实现（`e9ec8a0`） |
| 2 | 指定 School 15 的 HR 用户 | 需提供 user_id 列表，通过 Spatie API 或 Admin UI 分配 |
| 3 | 未配置主管的员工的处理 | 重新分配 or 暂不开启？ |
| 4 | 异常主管关系的处理 | 修复 or 接受？ |
| 5 | 是否清理遗留 HR pending 数据 | Phase 4 测试遗留 |
| 6 | 授权执行生产代码部署 (flag=false) | 仅代码层面，不开启功能 |
| 7 | 授权执行 School 15 权限安装 | 非破坏性操作 |
