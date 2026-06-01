# Access Control Restriction - Implementation Summary

## Issue Resolved
**Before:** Coordinators could see ALL teachers in their department  
**After:** Coordinators can see ONLY teachers they are explicitly assigned to

## Real-World Example: Marlon Timogan

### Before Changes:
- Marlon (Chairperson of CCIS) could view **12 CCIS teachers**
- This included Lani Jane Lumogda (even though he wasn't her coordinator)
- Any coordinator in a department could see all teachers in that department

### After Changes:
- Marlon can now see **only 1 teacher** (Jessie Mahinay)
- This is the teacher he's explicitly assigned to via `teacher_assignments` table
- Marlon **cannot** see Lani Jane Lumogda anymore

## Files Modified

### 1. evaluators/observation_plan.php
**Line 2440-2465:** Updated coordinator evaluation query
- **Old:** `WHERE (e.department = :department OR ... t.department = :department)`
- **New:** `JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :evaluator_id`

**Line 2585-2605:** Updated coordinator scheduled teachers query
- **Old:** Department-based filtering  
- **New:** `JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :coord_evaluator_id`

### 2. evaluators/observation_plan_print.php
**Line 130-152:** Updated coordinator query for print view
- **Old:** Department-based filtering
- **New:** `JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :evaluator_id_print`

## How It Works

The new queries require BOTH conditions:
1. `JOIN teacher_assignments ta` - Links coordinator to teacher
2. `ta.evaluator_id = current_user_id` - Ensures only assigned teachers are visible

```sql
FROM teachers t
JOIN evaluations e ON e.teacher_id = t.id
JOIN teacher_assignments ta ON ta.teacher_id = t.id 
  AND ta.evaluator_id = :evaluator_id
WHERE ...
```

## Access Pattern Comparison

| Page | Role | Before | After |
|------|------|--------|-------|
| observation_plan.php | Coordinator | All dept teachers | Only assigned teachers |
| observation_plan_print.php | Coordinator | All dept teachers | Only assigned teachers |
| evaluation.php | Coordinator | Only assigned teachers | ✓ Already correct |
| reports.php | Coordinator | Only assigned evals | ✓ Already correct |

## Related Files (Already Secure)
- `evaluation.php` - Already uses assignment-based access
- `reports.php` - Already scopes to assigned teachers and evaluators

## Database Table Requirements
These changes rely on the `teacher_assignments` table:
- `evaluator_id` - User ID of the coordinator
- `teacher_id` - ID of the assigned teacher
- Teachers must have explicit assignments for coordinators to see them

## Impact
✓ **More Secure:** Restricts data visibility to assigned scope only  
✓ **Consistent:** Aligns with evaluation.php access pattern  
✓ **Granular:** Allows cross-department assignments when needed  
✓ **Prevents Data Leakage:** Coordinators can't accidentally browse all dept teachers
