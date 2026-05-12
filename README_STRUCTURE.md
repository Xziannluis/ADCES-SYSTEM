# ADCES-SYSTEM – Folder Structure

This project is currently organized to be **XAMPP-friendly** and to keep existing browser routes working.

## Web entrypoints (unchanged)
These are still in the project root so URLs like `http://localhost/ADCES-SYSTEM/login.php` continue to work:
- `index.php`
- `login.php`
- `install.php`

Role-based modules (also unchanged):
- `edp/`
- `evaluators/`
- `leaders/`
- `teachers/`

## Application code (existing)
- `auth/` – session/login helpers
- `config/` – DB + constants
- `controllers/` – controllers (e.g. export)
- `models/` – model classes
- `includes/` – layout partials (header/sidebar/footer)
- `assets/` – css/js/images used by the UI
- `uploads/` – uploaded teacher photos

## New: database assets
All database-related dumps/tools were moved under `database/`:
- `database/schema/` – schema-only dumps
- `database/seed/` – prefilled dumps
- `database/migrations/` – migration scripts
- `database/tools/` – setup helpers

## New: scripts
 One-off maintenance/admin scripts:
 - `dev/scripts/` (development/admin utilities; not used by the running site)

## New: tests
 Ad-hoc test pages/scripts:
 - `dev/tests/` (development tests; not used by the running site)

### Dev-only folders

- `dev/archive/` (old experiments / scratch files; safe to ignore in production)
## Notes
If you later want an even cleaner structure (like moving web entrypoints into `public/`), we can do that too, but it requires updating Apache/XAMPP document root or adding rewrite rules.
