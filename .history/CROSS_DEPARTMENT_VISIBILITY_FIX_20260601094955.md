# Cross-Department Data Visibility Fix

## Issue Summary
When printing classroom observation plans, teachers with cross-department assignments would appear in incorrect department views. Specifically:
- **Reginald Ryan Gosela** (primary dept: CCIS, but also assigned to SHS)
- When an SHS evaluator created an evaluation marked as SHS
- **Problem**: Query fallback logic would incorrectly show this teacher in CCIS reports based on teacher's PRIMARY department, regardless of where the evaluation actually belonged

## Root Cause
The observation_plan_print.php had a problematic fallback condition in its SQL queries:

```sql
-- WRONG:
OR (
    (e.department IS NULL OR e.department = '')
    AND (t.scheduled_department IS NULL OR t.scheduled_department = '')
    AND t.department = 'CCIS'  ← Checks TEACHER's department!
)
```

This fallback matched based on the **teacher's primary department**, not the **evaluator's department**. This caused:
- Teachers belonging to multiple departments to appear in ALL department views
- Cross-department evaluations to be misclassified
- Department access control to be bypassed

## Solution
Changed the fallback logic to check the **evaluator's department** instead:

```sql
-- FIXED:
OR (
    (e.department IS NULL OR e.department = '')
    AND (t.scheduled_department IS NULL OR t.scheduled_department = '')
    AND eu.department = :department  ← Checks EVALUATOR's department!
)
```

## Changes Made

### File: `evaluators/observation_plan_print.php`

#### 1. **Leader Query** (Lines 82-110)
**Before:**
```sql
eu.department = :department_primary
OR ((eu.department IS NULL OR eu.department = '') AND t.department = :department_primary_fallback)
```

**After:**
```sql
eu.department = :department_primary
-- Removed the fallback to t.department
```

**Rationale:** Leaders printing a specific department should only see:
- Evaluations where the scheduled_department matches
- Evaluations where the evaluator's department matches
- NOT based on teacher's primary department

#### 2. **Coordinator Query** (Lines 124-147)
**Before:**
```sql
OR (
    (e.department IS NULL OR e.department = '')
    AND (t.scheduled_department IS NULL OR t.scheduled_department = '')
    AND (t.department = :department_match3 OR td.department = :department_match4)
)
```

**After:**
```sql
OR (
    (e.department IS NULL OR e.department = '')
    AND (t.scheduled_department IS NULL OR t.scheduled_department = '')
    AND eu.department = :department_match3
)
```

**Rationale:** When evaluation has no explicit department set, only show it if:
- The evaluator is from that department (creator's intent)
- NOT based on where the teacher belongs

#### 3. **Regular Evaluator Query** (Lines 149-173)
**Before:**
```sql
OR (
    (t.scheduled_department IS NULL OR t.scheduled_department = '')
    AND t.department = :department_primary
)
```

**After:**
```sql
OR (
    t.department = :department_primary
    AND e.evaluator_id = :self_eval_id
)
```

**Rationale:** Regular evaluators should only see:
- Teachers they explicitly evaluated
- Teachers with explicit scheduled_department
- NOT all teachers in the school with that department

## Testing
To verify the fix works:

1. Check SHS DEAN print: Should NOT see CCIS teacher evaluations marked as SHS
2. Check CCIS DEAN print: Should NOT see SHS teacher evaluations
3. Check cross-department teacher: Only appears in department where evaluation was marked
4. Verify departments filter correctly in observation_plan_print.php

## Database Records Affected
- No data changes required
- Only query logic changed
- Existing evaluations with:
  - `e.department = 'SHS'` will only appear in SHS views
  - `e.department = 'CCIS'` will only appear in CCIS views
  - `e.department = NULL` will appear based on evaluator's department
