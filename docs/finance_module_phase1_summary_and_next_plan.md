# eSchool 财务模块第一阶段总结与下一阶段计划

> 文档日期：2026-06-12  
> 状态：第一阶段已完成，可上线  
> 面向读者：老板、项目负责人、开发人员

---

## 一、第一阶段已完成功能

### 1.1 Finance Dashboard / 财务总览

**入口：** `/finance-dashboard`  
**权限：** `fees-paid`

**功能说明：**

- 给老板和财务人员查看整体财务状况的首页。
- 核心指标卡片：
  - Total Income（总收入）
  - Total Expense（总支出）
  - Net Income（净收入）= Total Income - Total Expense
  - Current Outstanding（当前欠费总额）
  - Compulsory Income（必缴收入）
  - Optional Income（可选收入）
  - Collection Rate（收费率）
- 最近收款列表（Recent Payments）
- 最近支出列表（Recent Expenses）
- 快捷入口（Quick Links）
- 支持按日期范围筛选：`?from=YYYY-MM-DD&to=YYYY-MM-DD`

**关键修复（P2-3）：** Dashboard 和 Finance Report 的收入日期口径已统一，均按实际收款日期（`compulsory_fees.date` / `optional_fees.date`）统计。

**相关 Commit：** `7a08eed` Add finance dashboard  
**修复 Commit：** `5a7725c` Fix finance module P2 issues

---

### 1.2 Finance Report / 收支报表

**入口：** `/finance-report`  
**权限：** `fees-paid`

**功能说明：**

- 按日期范围、Type（Income/Expense）、Finance Category 查看收入和支出明细。
- 显示：
  - Total Income = Compulsory Income + Optional Income
  - Total Expense
  - Net Income = Total Income - Total Expense
  - Compulsory Income / Optional Income
  - Outstanding Reference（欠费参考，不计入 Income）
- 支持 Category Breakdown（按财务分类汇总）
- 支持 Excel Export（`/finance-report/export`）

**核心规则：**
- Outstanding 不计入 Income
- Optional Income 不计入 Outstanding
- 无 double count（同一笔付款不会被重复统计）

**相关 Commit：**
- `08d02de` Add finance report page
- `9e23805` Fix finance report controller signature
- `8e707da` Improve finance report filters and categories
- `79753c0` Add finance report export

---

### 1.3 Outstanding Fees / 欠费名单

**入口：** `/outstanding-fees`  
**权限：** `fees-paid`

**功能说明：**

- 查看所有学生欠费情况的一览表。
- 每行显示：
  - Student Name（学生姓名）
  - Admission No（学号）
  - Class / Section（班级）
  - Contact（联系方式）
  - Expected Amount（应缴金额，仅来自 compulsory fee items）
  - Compulsory Paid（已缴必缴费用，仅统计 `status=Success`）
  - Optional Paid（已缴可选费用，仅供参考）
  - Outstanding Amount = max(0, Expected - Compulsory Paid)
  - Status（Paid / Unpaid / Partially Paid）
  - Last Payment Date（最后付款日期）
- 支持筛选：
  - Search（按姓名搜索）
  - Outstanding Only（仅欠费学生）
  - Status Filter（按状态筛选）
  - Session Year Filter（按学年筛选）
  - Class Section Filter（按班级筛选）
- 支持 Excel Export（`/outstanding-fees/export`）

**Export 修复（P2-1、P2-2）：**
- Session Year 在导出中显示为年份名称（如 `2026`），而非数字 ID
- List 区域已添加完整的列标题行

**核心规则：**
- Expected 只来自 compulsory fee items
- Compulsory Paid 只统计 `compulsory_fees.status=Success`
- Optional Paid 只显示参考，不减少 Outstanding
- Outstanding = max(0, Expected - Compulsory Paid)

**相关 Commit：**
- `e6881ca` Add outstanding fees list
- `cc2dffa` Group student finance pages
- `c271f98` Add outstanding fees export
- `a5bdd66` Fix outstanding fees export session year query
- `5a7725c` Fix finance module P2 issues（P2-1、P2-2 修复）

---

### 1.4 Student Ledger / 学生账本

**入口：** `/student-ledger`（列表页）、`/student-ledger/{userId}`（详情页）  
**权限：** `fees-paid`

**功能说明：**

- 查看单个学生的完整财务账本。
- 显示：
  - Student Info（学生基本信息，含 Contact）
  - Expected Amount（应缴金额）
  - Compulsory Paid（已缴必缴费用）
  - Optional Paid（已缴可选费用，仅供参考）
  - Outstanding（欠费金额）
  - Payment History（付款记录列表）
- 支持 Print Statement（打印账单，`/student-ledger/{userId}/print`）
- 支持 Search（按姓名/学号搜索）
- `student_id` 使用 `users.id`，不是 `students.id`

**关键修复：**
- Controller 签名修复（`95038b5`）
- Class Section 查找修复（`82a6de4`）
- Search 功能修复（`efc7b9a`、`f645c81`）
- 空标签显示优化（`be808f7`）
- 显示 Contact 信息（`107c9eb`）

**核心规则：**
- Optional Paid 只显示参考，不减少 Outstanding
- 使用 `users.id` 作为学生标识

**相关 Commit：**
- `ef24a43` Add student ledger view
- `95038b5` Fix student ledger controller signature
- `82a6de4` Fix student ledger class section lookup
- `efc7b9a` Fix student ledger search
- `f645c81` Improve student ledger search matching
- `be808f7` Improve empty labels in student ledger
- `107c9eb` Show contact in student ledger
- `2571bd2` Add student ledger print statement

---

### 1.5 Finance Categories / 财务分类

**说明：**

- 用于给 Compulsory Income 和 Expense 做 Report Category 分类。
- 已在 `fees_class_types` 表上增加 `finance_category_id` 字段。
- 已在 `expenses` 表上增加 `finance_category_id` 字段。
- 部分测试数据已配置分类。

**未完成：** 部分历史 fee item / payment 没有配置 Report Category，导致 Finance Report 中出现 "Uncategorized"。

**相关 Commit：**
- `e8eb951` Fix finance category table events and active status
- `a4c6055` Improve Fee Setup UI
- `7dab9a0` Connect finance categories to expenses and fees
- `e74cfb8` Fix finance category review issues

---

### 1.6 Finance / Expenses 菜单 Phase 1 整理

**改动范围：** 仅 `resources/views/layouts/sidebar.blade.php`

**变更内容：**

| 区域 | 改动 |
|------|------|
| Finance 顶级菜单 | `Income` → `Finance / 财务管理` |
| Expense 顶级菜单 | `expense` → `Expenses / 支出管理` |
| Finance 子菜单顺序 | 重新排序，新增 Student Ledger 入口 |
| Income Summary | → `Fee Collection` |
| Optional Fee | → `Optional Fees` |
| Manage Fee | → `Manage Fees` |
| Fees Type | → `Fee Types` |
| Fees Transaction Logs | → `Transaction Logs` |
| manage_expense | → `Manage Expenses` |
| manage_category | → `Expense Categories` |
| Finance Categories | 保持不变 |
| 新增 Student Ledger | `route('student-ledger.index')`，权限 `fees-paid` |

**未改动的：**
- 所有 route name 不变
- 所有 controller 逻辑不变
- 所有权限逻辑不变
- 未删除任何旧功能或菜单
- Expense Report 和 Finance Categories 位置未移动

**相关 Commit：** `82ad6f3` Improve finance sidebar labels

---

## 二、相关 Commit 清单

| Commit | 日期 | 说明 |
|--------|------|------|
| `08d02de` | 2026-06-12 | Add finance report page — 新增财务收支报表页面 |
| `9e23805` | 2026-06-12 | Fix finance report controller signature — 修复 Controller 签名 |
| `8e707da` | 2026-06-12 | Improve finance report filters and categories — 优化筛选和分类 |
| `79753c0` | 2026-06-12 | Add finance report export — 新增收支报表 Excel 导出 |
| `c271f98` | 2026-06-12 | Add outstanding fees export — 新增欠费名单 Excel 导出 |
| `a5bdd66` | 2026-06-12 | Fix outstanding fees export session year query — 修复学年查询 |
| `2571bd2` | 2026-06-12 | Add student ledger print statement — 新增学生账单打印 |
| `7a08eed` | 2026-06-12 | Add finance dashboard — 新增财务总览仪表盘 |
| `5a7725c` | 2026-06-12 | Fix finance module P2 issues — 修复 4 个 P2 问题 |
| `82ad6f3` | 2026-06-12 | Improve finance sidebar labels — 菜单命名和顺序整理 |
| `e6881ca` | 2026-06-12 | Add outstanding fees list — 新增欠费名单页面 |
| `cc2dffa` | 2026-06-12 | Group student finance pages — 整理学生财务页面分组 |
| `ef24a43` | 2026-06-12 | Add student ledger view — 新增学生账本页面 |
| `e8eb951` | 2026-06-11 | Fix finance category table events and active status — 修复分类表事件 |
| `a4c6055` | 2026-06-11 | Improve Fee Setup UI — 优化收费设置界面 |
| `7dab9a0` | 2026-06-11 | Connect finance categories to expenses and fees — 关联分类与收支 |
| `e74cfb8` | 2026-06-11 | Fix finance category review issues — 修复分类审查问题 |

---

## 三、测试结果

### 3.1 测试通过项

| 测试项 | 结果 | 备注 |
|--------|------|------|
| Finance Dashboard 页面 | ✅ 通过 | `/finance-dashboard`、含日期筛选 |
| Finance Report 页面 | ✅ 通过 | `/finance-report`、含日期筛选 |
| Finance Report Export | ✅ 通过 | Excel 可下载、可打开 |
| Outstanding Fees 页面 | ✅ 通过 | 筛选功能正常 |
| Outstanding Fees Export | ✅ 通过 | Excel 可下载、可打开、列标题完整 |
| Student Ledger 页面 | ✅ 通过 | 列表和详情页正常 |
| Student Ledger Print | ✅ 通过 | 打印页面可正常打开和打印 |
| P2 修复复测 | ✅ 通过 | 无回归问题 |
| 菜单 Phase 1 | ✅ 通过 | 所有入口均可正常打开 |

### 3.2 关键验证

- **无 P0 / P1 阻塞问题** — 核心功能全部可用
- **Console / Network 无新增错误** — 前端无报错
- **金额公式验证通过**：
  - Total Income = Compulsory + Optional ✅
  - Net Income = Total Income - Total Expense ✅
  - Outstanding 不计入 Income ✅
  - Optional 不计入 Outstanding ✅
  - 无 double count ✅
- **Excel Export** — 可正常下载并用 Excel / openpyxl 打开
- **Student Ledger Print** — 页面可打开并支持浏览器打印

---

## 四、核心金额规则

这是财务模块最重要的业务规则，所有开发和测试必须遵守：

| # | 规则 | 说明 |
|---|------|------|
| 1 | **Total Income = Compulsory Income + Optional Income** | 总收入 = 必缴 + 可选 |
| 2 | **Net Income = Total Income - Total Expense** | 净收入 = 总收入 - 总支出 |
| 3 | **Outstanding 不计入 Income** | 欠费是应收未收，不是已收收入 |
| 4 | **Optional Paid / Optional Income 不减少 Outstanding** | 可选费用与欠费无关 |
| 5 | **Outstanding = max(0, Expected - Compulsory Paid)** | 欠费不会为负数 |
| 6 | **Expected 只来自 compulsory fee items** | 应缴金额不含可选费用 |
| 7 | **Compulsory Paid 只统计 `compulsory_fees.status=Success`** | 失败的付款不计入 |
| 8 | **Optional Paid 只统计 `optional_fees.status=Success`** | 同上 |
| 9 | **student_id 使用 `users.id`** | 不是 students.id |
| 10 | **避免 double count** | 同一笔付款不能重复计入多个统计 |

---

## 五、已知问题 / 遗留问题

以下问题已知但不阻塞上线，后续阶段处理：

### 5.1 Uncategorized Income 仍有历史数据

- **现象：** Finance Report 中部分收入显示为 "Uncategorized"
- **原因：** 历史 fee item / payment 没有配置 `Report Category`（即 `finance_category_id` 为空）
- **影响：** 不影响金额正确性，只是分类显示不全
- **代码确认：** 非 double count、非新支付分类失败
- **建议：** 后续通过数据配置或回填处理（Phase 3）

### 5.2 ExpenseCategory 和 FinanceCategory 并存

- **现状：** 系统同时存在 `expense_categories` 表和 `finance_categories` 表
- **说明：** `FinanceCategory` 正在作为统一的收入/支出分类；`ExpenseCategory` 可能是旧模块
- **建议：** 不建议现在删除 ExpenseCategory，需要先排查所有依赖（Phase 5）

### 5.3 旧 Income Summary 名称混淆

- **现状：** 已在菜单中改名为 "Fee Collection"
- **说明：** 业务逻辑未改动，仍指向 `fees.paid.index`
- **建议：** 后续评估是否与 Student Ledger / Finance Report 做更深层整合（Phase 5）

### 5.4 Payroll / Transportation Expense 仍在独立模块

- **现状：** Payroll 和 Transportation Expense 没有合并进 Finance
- **说明：** 这些模块有独立的费用记录，不在 Finance Report 统计范围内
- **建议：** 后续评估是否纳入统一 Finance Report（Phase 5）

---

## 六、下一阶段计划

### Phase 2：菜单结构进一步整理

**目标：** 菜单更清晰，但仍不改业务逻辑

- Finance 下建立更清晰的分组：
  - **概览：** Finance Dashboard
  - **学生财务：** Student Finance / Student Ledger
  - **收费设置：** Fee Setup
  - **支出：** Expenses
  - **报表：** Reports
  - **设置：** Settings
- 只移动菜单位置，不改 route / controller
- 不删除旧功能
- 不合并 Expense 到 Finance

### Phase 3：数据配置完善

**目标：** 降低 Uncategorized 占比

- 补全历史 fee items 的 Report Category（`finance_category_id`）
- 降低 Uncategorized 在 Finance Report 中的占比
- 检查真实学校数据是否有分类缺失
- **安全策略：** 做任何数据回填前，先只读检查，确认影响范围

### Phase 4：报表和导出优化

**目标：** 提升使用体验

- Finance Report 增加快捷日期选择：本月、上月、本季度
- Outstanding Fees Export 增加班级/年级分组
- Student Ledger Print 可后续支持 PDF 下载
- Finance Dashboard 可后续增加图表（如收入趋势图）

### Phase 5：功能整合评估

**目标：** 评估是否需要更深层的功能整合

- 评估 Fee Collection、Student Ledger、Outstanding Fees 是否需要统一入口
- 评估 ExpenseCategory 是否可以逐步被 FinanceCategory 替代
- 评估 Expense Report 是否移到 Finance → Expenses 下
- **重要：** 任何合并前必须先做完整的依赖排查，确保不破坏现有功能

---

## 七、老板版总结

### 第一阶段完成了什么

1. **财务总览（Finance Dashboard）** — 老板可以一眼看到总收入、总支出、净收入、欠费总额和收费率。
2. **收支报表（Finance Report）** — 财务可以按日期和分类查看收入支出明细，还能导出 Excel。
3. **欠费名单（Outstanding Fees）** — 财务可以看到每个学生的欠费情况，支持筛选和导出 Excel。
4. **学生账本（Student Ledger）** — 可以查看单个学生的完整账单，支持打印。
5. **菜单已整理** — Finance 和 Expenses 菜单命名更清晰，新增了 Student Ledger 入口。

### 当前状态

- **无阻塞问题** — 所有核心功能均通过测试，可以上线使用。
- **金额规则清晰** — Total Income、Net Income、Outstanding 的计算公式都已确认正确。
- **Excel 导出正常** — 所有导出功能都可正常下载和打开。

### 下一步

| 阶段 | 内容 | 预计影响 |
|------|------|----------|
| Phase 2 | 菜单进一步分组整理 | 不改功能，只调菜单 |
| Phase 3 | 补全历史数据分类 | 让报表分类更完整 |
| Phase 4 | 报表和导出优化 | 加快捷日期、分组导出 |
| Phase 5 | 功能整合评估 | 谨慎评估，先排查依赖 |

### 哪些不要动

- 不要动收费保存逻辑
- 不要动收据逻辑
- 不要动多币种保存逻辑
- 不要删除旧功能
- 不要修改数据库结构
- 合并功能前必须做依赖排查
