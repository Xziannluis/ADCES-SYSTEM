# Department Access Control - FIXED

## What Was Wrong

1. **Main Issue**: In `reports.php`, the `$report_department` was only passed to `getEvaluationsForReport()` for leaders (`$is_leader`), not for coordinators or deans
   - This meant coordinators and deans saw ALL completed evaluations, not just their department
   - Jessie Mahinay (CCIS) appeared in CAS reports

2. **Complex Queries**: The previous fix used overly complex nested queries that didn't work correctly
   - Tried to join with `teacher_assignments` and `evaluator_assignments` 
   - Caused collation issues and incorrect results

## Solution Implemented

### 1. **reports.php** - Pass department to all users
```php
// BEFORE:
$report_department = $is_leader ? $raw_department : '';

// AFTER:
$report_department = $raw_department;  // Pass to ALL users
```

This ensures:
- Leaders see all evaluations in the selected department
- Coordinators/Deans see all evaluations in their department
- Department boundary is strictly enforced

### 2. **Simplified Queries** - Use `e.department` not `t.department`
```php
// CORRECT: Filter by evaluation's department field
WHERE e.department = :department
  AND e.status = 'completed'
  AND e.overall_avg IS NOT NULL
  AND e.overall_avg > 0

// WRONG: Filtering by teacher's primary department allows cross-dept leakage
WHERE t.department = :department
```

The key insight: **Evaluations have their own department field** (`e.department`), which is where they were conducted. This is separate from the teacher's primary department (`t.department`).

### 3. **Evaluation.php** - Simplified to use `e.department`
- Removed complex nested queries
- Just filter: `WHERE e.department = :department`
- Clean, simple, reliable

## Results

**Before Fix:**
- ✗ CAS reports showed Jessie Mahinay (CCIS teacher)
- ✗ Teachers appeared in wrong department reports
- ✗ No proper department boundary

**After Fix:**
- ✓ CCIS reports show: Jessie Mahinay with 4 evaluations
- ✓ CAS reports show: No completed evaluations (correct, they're in draft)
- ✓ Department filter properly enforced

## Files Modified
- `/evaluators/reports.php` - Pass department to all users, simplified queries
- `/models/Evaluation.php` - Simplified getDepartmentStats() and getEvaluationsForReport()

## Testing
Run test to verify: `php test_simplified.php`

```
✓ Found 1 teachers in CCIS with completed evaluations:
  - Jessie Mahinay
✓ Found 4 completed evaluations in CCIS
```

## Key Learning
Use `e.department` (evaluation's recorded department) for filtering, not `t.department` (teacher's primary department). The evaluation table has the authoritative department information for where the evaluation was conducted.

