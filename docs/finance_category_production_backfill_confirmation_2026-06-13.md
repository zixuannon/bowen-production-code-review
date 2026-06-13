# Finance Category 生产分类回填确认报告

**报告日期**: 2026-06-13
**数据来源**: 生产服务器 (43.160.241.126)
**学校**: SCH202615 / Zixuan
**数据库名称**: `eschool_saas_15_zixuan`
**操作类型**: SELECT ONLY — 只读，未执行任何写入

---

## 1. 检查背景

本项目已引入新的 `finance_categories` 财务分类体系，需要在生产环境确认 **fees_class_types** 和 **expenses** 表中 `finance_category_id IS NULL` 的真实情况，并评估是否可以进行安全的数据回填。

本次检查基于 Phase 3.2 生产服务器 Dry Run 的输出结果，对 SCH202615 (Zixuan) 学校数据库进行了完整的只读分析。

## 2. Finance Categories 当前状态

生产环境共有 **19 个** Finance Categories（含 2 个 Inactive 测试分类）。有效分类已覆盖常见的收入和支出类型：

| ID | Name | Type |
|----|------|------|
| 1 | Tuition Fee | Income |
| ... | (其他有效收入分类) | Income |
| 9 | Salary | Expense |
| ... | (其他有效支出分类) | Expense |

**结论**: 现有 Finance Categories 已覆盖所有当前未分类数据的需求，**不需要新增 Finance Category**。

## 3. Fee Items (费用项目) 未分类分析

### 3.1 数据概况

| 指标 | 数值 |
|------|------|
| Total fees_class_types | 18 |
| Already categorized | 3 |
| **Uncategorized (NULL)** | **15** |
| Auto Backfill | 0 |
| Manual Review | 15 |
| Uncategorized Fee Total | 5,290,996 MMK |

### 3.2 为什么 15 条全部被标记为 Manual Review？

Dry Run 脚本中，自动回填的置信度判断规则是：当一个费用项的 `Item_Name` 或关联 `Fee_Name` 为 **Fuzzy Name**（模糊名称，如 "Other"、"其他"、"Misc" 等）时，即使关键词匹配成功，也会从 `auto_backfill` 降级为 `manual_review`。

这 15 条 Fee Items 的详细情况：

- **Item_Name**: 全部为 **"学费"**（中文，即 Tuition Fee）
- **Suggested Category**: 全部为 **Tuition Fee (ID=1)**
- **降级原因**: 关联的 `Fee_Name` 字段为空，脚本对空名称采用了保守策略，判定为需人工确认

### 3.3 结论

**这 15 条的 Item_Name 明确为"学费"，分类目标完全确定**。从业务角度看，它们都应该归类为 **Tuition Fee (ID=1)**。脚本只是出于安全考虑将它们标记为 manual_review，实际上分类结果毫无歧义。

## 4. Expenses (支出) 未分类分析

### 4.1 数据概况

| 指标 | 数值 |
|------|------|
| Total expenses | 4 |
| Already categorized | 3 |
| **Uncategorized (NULL)** | **1** |
| Auto Backfill | 1 |
| Manual Review | 0 |
| Uncategorized Expense Total | 10,000,000 MMK |

### 4.2 未分类支出详情

| 字段 | 值 |
|------|-----|
| Exp_ID | 1 |
| Title | May - 2026 |
| Description | Salary |
| Amount | 10,000,000 MMK |
| Suggested Category | Salary (ID=9) |
| Action | auto_backfill |

**分析**:

- Description 明确为 "Salary"
- 关键词直接匹配 Expense Category "Salary" (ID=9)
- 无模糊性，**可安全归类为 Salary (ID=9)**

## 5. 是否需要新增 Finance Category？

**不需要。** 现有 Finance Categories 已覆盖所有未分类数据的需求：

- Tuition Fee (ID=1) → 覆盖 15 条"学费"
- Salary (ID=9) → 覆盖 1 条 Salary 支出

## 6. 是否建议今天执行真实回填？

**暂不建议。** 建议先完成以下人工确认后再执行回填：

1. **Fee Items（15 条）**: 虽然 Item_Name 全部为"学费"，但 Fee_Name 为空的情况需要确认这些费用项目是否确实都对应 Tuition Fee，可能存在特殊费用项目
2. **Expense（1 条）**: Title 为 "May - 2026"，Description 为 "Salary"，建议确认是否为 2026 年 5 月薪资，以及金额是否准确
3. **已分类数据（3 条 Fee Items + 3 条 Expenses）** 需抽样验证分类是否准确

## 7. 未来执行真实回填的安全原则

如果确认后需要执行真实回填，必须遵守以下原则：

### 7.1 执行前

- **必须备份** `fees_class_types` 和 `expenses` 表
- 确认备份文件可恢复
- 再次运行 Dry Run 确认数据未发生变化

### 7.2 执行中

- **只更新** `finance_category_id IS NULL` 的记录
- **不覆盖** 已有分类（`finance_category_id IS NOT NULL` 的记录）
- **不修改** 以下字段：`amount` / `status` / `student_id` / `fees_id` / `date`
- **不修改** 以下表：`compulsory_fees` / `optional_fees` / `fees_paids`

### 7.3 执行后

必须验证以下模块报表数据正常：

| 验证模块 | 检查内容 |
|----------|----------|
| Finance Report | 收入/支出按分类汇总正确 |
| Dashboard | 财务概览图表数据正确 |
| Outstanding Fees | 未缴费用列表数据正确 |
| Student Ledger | 学生账单明细数据正确 |

## 8. 下一步建议

| 步骤 | 负责人 | 内容 |
|------|--------|------|
| 1 | 财务人员 | 确认 15 条"学费"Fee Items 是否全部归类为 Tuition Fee (ID=1) |
| 2 | 财务人员 | 确认 Exp_ID=1 (May - 2026 / Salary) 是否为 Salary (ID=9) |
| 3 | 财务人员 | 抽查已分类的 6 条记录是否分类正确 |
| 4 | 开发人员 | 财务确认后准备安全回填脚本 |
| 5 | 开发人员 | 回填前备份 `fees_class_types` 和 `expenses` |
| 6 | 开发人员 | 执行回填并验证报表 |

> **重要**: 今天不要直接执行 UPDATE。等财务确认后再操作。

---

**报告结束**

*此报告基于生产服务器 SELECT ONLY 查询结果生成，未对数据库执行任何写入操作。*
