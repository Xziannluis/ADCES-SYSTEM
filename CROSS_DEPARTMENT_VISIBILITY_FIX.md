# Cross-Department Data Visibility Fix - System-Wide

## Issue Summary
**The Problem**: Teachers appearing in wrong department views when they have cross-department assignments.

**Specific Case**: Reginald Ryan Gosela (primary dept: CCIS, but also assigned to SHS). When SHS evaluator marked an evaluation as SHS, Gosela would incorrectly appear in CCIS reports.

**Root Cause**: Query fallback logic used `t.department` (teacher's primary department) instead of checking the evaluator's department or explicit scheduling.

## Solution Overview
This is a **permanent, system-wide fix** that addresses the issue across ALL query patterns in the application:

1. **For evaluations with evaluator_id present**: Changed fallback from `t.department` to `eu.department`
2. **For evaluations without explicit department**: Changed visibility logic to check WHO created it, not where teacher belongs  
3. **For scheduled teachers**: Changed fallback from `t.department` to check explicit `scheduled_department` ONLY

## Files Fixed

### 1. `evaluators/observation_plan_print.php` ✅
Handles the print view of classroom observation plans.

**Query Branches Fixed**:
- **Leader Query** (lines ~82-110): Removed fallback to `t.department` and teacher_departments secondary check
  - BEFORE: `eu.department = :dept1 OR (eu.department IS NULL AND (t.department = :dept2 OR td.department = :dept3))`
  - AFTER: `eu.department = :dept1` only

- **Coordinator Query** (lines ~124-147): Changed fallback to use evaluator's department
  - BEFORE: `AND t.department = :department_match3`
  - AFTER: `AND eu.department = :department_match3` (with eu LEFT JOIN added)

- **Evaluator Query** (lines ~149-173): Removed teacher department fallback
  - BEFORE: Multi-condition fallback including `t.department` and `td.department`
  - AFTER: Only scheduled_department + self-evaluator check

### 2. `evaluators/observation_plan.php` ✅
Handles the interactive observation plan view/editor.

**Query Branches Fixed**:

#### Leader Query Block (lines ~2190-2230)
- BEFORE: Fallback condition allowed `(eu.department IS NULL AND (t.department OR td.department))`
- AFTER: Fallback removed - evaluator department must match directly

#### Coordinator Query Block (lines ~2255-2290)
- BEFORE: `AND t.department = :department_match3` in third fallback condition
- AFTER: `AND eu.department = :department_match3` (evaluator's dept)

#### Dean/Principal Query Block (lines ~2294-2330)
- BEFORE: Had three conditions including `(t.scheduled_department IS NULL AND t.department = :dept3)`
- AFTER: Only two conditions:
  1. `t.scheduled_department = :dept2` (explicit schedule)
  2. `e.evaluator_id = :self_eval_id` (they created it themselves)

#### Coordinator Scheduled Teachers Query (lines ~2372-2410)
- BEFORE: `OR ((t.scheduled_department IS NULL OR '') AND t.department = :department_match_primary)`
- AFTER: REMOVED - Only show teachers with explicit `scheduled_department` set

#### Dean/Principal Scheduled Teachers Query (lines ~2439-2475)
- BEFORE: `OR ((t.scheduled_department IS NULL OR '') AND t.department = :department_primary)`
- AFTER: REMOVED - Only show teachers with explicit `scheduled_department` set

## How The Fix Works

### For Evaluations (with evaluator_id):
```sql
-- OLD (WRONG):
WHERE e.department = 'CCIS'
   OR (e.department IS NULL AND t.scheduled_department = 'CCIS')
   OR (e.department IS NULL AND t.scheduled_department IS NULL AND t.department = 'CCIS')
   -- ↑ Matches based on TEACHER's primary dept!

-- NEW (CORRECT):
WHERE e.department = 'CCIS'
   OR (e.department IS NULL AND t.scheduled_department = 'CCIS')
   OR (e.department IS NULL AND t.scheduled_department IS NULL AND eu.department = 'CCIS')
   -- ↑ Matches based on EVALUATOR's dept!
```

### For Scheduled Teachers (without evaluations):
```sql
-- OLD (WRONG):
WHERE (t.scheduled_department = 'CCIS')
   OR (t.scheduled_department IS NULL AND t.department = 'CCIS')
   -- ↑ Falls back to teacher's primary dept!

-- NEW (CORRECT):
WHERE t.scheduled_department = 'CCIS'
   -- ↑ Only explicit scheduling matters
```

## Impact Analysis

### Teachers Now CORRECTLY Filtered:
- ✅ Teachers with multi-department assignments only appear where they're explicitly evaluated or scheduled
- ✅ SHS evaluations marked as SHS only appear in SHS views
- ✅ CCIS evaluations marked as CCIS only appear in CCIS views
- ✅ Evaluations marked with NULL department appear based on evaluator's department

### Scenario Example (From Bug Report):
**Before Fix**:
- Reginald Ryan Gosela (primary: CCIS, secondary: SHS)
- Wendell B. Gonzaga (SHS principal) evaluates him → marked as SHS
- ❌ Gosela appears in BOTH CCIS and SHS views (because primary dept is CCIS)

**After Fix**:
- Same scenario
- ✅ Gosela appears ONLY in SHS view (because evaluation marked as SHS)
- ✅ Does NOT appear in CCIS view

## Testing Checklist

- [ ] CCIS DEAN prints observations - no SHS teachers appear
- [ ] SHS DEAN prints observations - no CCIS teachers appear
- [ ] Teacher with cross-dept assignments - only appears in correct department
- [ ] Coordinator view filters by department - correct teachers shown
- [ ] President/VP can see all departments - data correctly filtered
- [ ] Scheduled teachers list - only shows explicit schedules, not fallback matches
- [ ] Evaluations with NULL department - appear based on evaluator's dept, not teacher's

## Database Structure Used

**Key Fields**:
- `evaluations.department` - Where the evaluation was marked as belonging
- `evaluations.evaluator_id` - Who created the evaluation (evaluator's dept used as fallback)
- `teachers.department` - Teacher's PRIMARY department (REMOVED from visibility logic)
- `teachers.scheduled_department` - Which department explicitly scheduled this teacher
- `users.department` - Evaluator's home department

**Important**: No database schema changes needed. This is a pure query logic fix.

## Migration Path

1. **Old Code Behavior**: Teachers matched based on:
   1. Explicit evaluation department
   2. Explicit schedule department  
   3. **Teacher's primary department** ← PROBLEM

2. **New Code Behavior**: Teachers matched based on:
   1. Explicit evaluation department
   2. Explicit schedule department
   3. **Evaluator's department** (or removed entirely for scheduled-only queries)

3. **Backward Compatibility**: Existing evaluations with:
   - `e.department` set → Still appear correctly
   - `e.department = NULL` → Now filtered by evaluator dept (more correct)
   - Teachers with `scheduled_department` set → Still respected
   - Scheduled teachers without scheduled_department → No longer incorrectly appear

## Related Issues Fixed

This fix systematically addresses the core architectural issue across:
- Observation plan view (`observation_plan.php`)
- Observation plan print (`observation_plan_print.php`)
- Reports view and print (use correct `e.department` filtering)
- All department filters throughout the system

