# Dev Scripts

This folder contains helper scripts for local development.

## `seed_users.php`
Safely inserts default users if they don’t exist and fixes placeholder passwords.

**Usage (browser):**

Open:
```
http://localhost/ADCES-SYSTEM/dev/scripts/seed_users.php
```

**Default logins created:**
- EDP: `edp_user` / `edp123`
- Teacher: `teacher_john` / `teacher123`

> This script is non-destructive and won’t delete existing users.
