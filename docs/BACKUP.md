# Backup and restore

CollabSpace keeps its work in two places. Both have to be backed up, and restored together, or documents will point at pictures that are not there.

| What | Where | Holds |
| --- | --- | --- |
| The database | MySQL database `collabspace_v2` | users, groups, tasks, messages, documents and every version of them, activity (the contribution and evidence counts), rubrics, marks and approved snapshots |
| The private storage folder | `storage/app/private` | group chat files (`group-files/{group}/…`) and document pictures (`group-files/{group}/documents/{document}/…`) |

Also keep your `.env` (it holds the app key and the database settings) in a safe place **of its own**, never with the dumps and never in git.

A full backup is therefore: **the database dump + `storage/app/private` + a copy of `.env`**. The code is in git and is not part of this.

Nothing in here needs a real password. Where a command asks for one, type it when it prompts; do not write it into a script or a file in this repository.

## How often

- While the system is in use for a class: **once a day** (database and files).
- **Before** every migration, deployment, or `php artisan documents:purge-deleted` (without `--dry-run`).
- **Before and after marking**: a copy taken when marks are approved is the evidence trail for that moment.
- **Once a month**, restore a backup into a spare database and look at it. A backup that has never been restored is a hope, not a backup.

## Where NOT to put backups

- **Never inside the git repository** (including `backups/`, `storage/`, `database/`). `.gitignore` ignores `*.sql` and `backups/` as a safety net, not as permission.
- Never commit `.env` or any dump. A dump contains every student's name, email and work.
- Never inside the web root (`public/`) or any folder the web server shows.
- Keep them on another disk or machine (an external drive, or the university's storage), not only next to the project.

## Windows with XAMPP (development)

Use PowerShell. Replace `D:\collabspace-backups` with a folder outside the project. XAMPP's tools live in `C:\xampp\mysql\bin`. If MySQL listens on another port (for example 3307), add `-P 3307`.

### Back up

```powershell
$stamp = Get-Date -Format "yyyy-MM-dd_HHmm"
$dest  = "D:\collabspace-backups\$stamp"
New-Item -ItemType Directory -Force $dest | Out-Null

# 1. The database. --result-file writes the file itself, so PowerShell never re-encodes it
#    (redirecting with ">" makes a UTF-16 file that cannot be restored).
& "C:\xampp\mysql\bin\mysqldump.exe" -u root -p --single-transaction --routines `
    --default-character-set=utf8mb4 --result-file="$dest\collabspace_v2.sql" collabspace_v2

# 2. The private storage folder (group files and document pictures)
robocopy "C:\Users\ASUS\collabspaceV2\storage\app\private" "$dest\private" /MIR /NFL /NDL /NJH /NJS

# 3. The settings, to a separate, protected place (not beside the dump)
Copy-Item "C:\Users\ASUS\collabspaceV2\.env" "D:\collabspace-secrets\.env.$stamp"
```

`-p` with nothing after it asks for the password (XAMPP's default `root` user often has none; then leave `-p` out).

### Restore

```powershell
# 1. Stop the app (close the browser tabs, stop `php artisan serve` / Apache).

# 2. The database. Create it empty if it does not exist, then load the dump.
& "C:\xampp\mysql\bin\mysql.exe" -u root -p -e "CREATE DATABASE IF NOT EXISTS collabspace_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
& "C:\xampp\mysql\bin\mysql.exe" -u root -p --default-character-set=utf8mb4 collabspace_v2 -e "source D:/collabspace-backups/2026-11-10_1200/collabspace_v2.sql"

# 3. The files
robocopy "D:\collabspace-backups\2026-11-10_1200\private" "C:\Users\ASUS\collabspaceV2\storage\app\private" /MIR /NFL /NDL /NJH /NJS

# 4. Put .env back if it was lost, then
php artisan config:clear
```

(`source` takes forward slashes. `<` does not work in PowerShell, which is why it is not used.)

Check afterwards: sign in, open a group's Documents, open a document that has a picture, open the Marking page.

## Generic Linux server

Run as the user that owns the app, with the app at `/var/www/collabspace` and backups in `/srv/backups/collabspace` (outside the web root).

### Back up

```bash
stamp=$(date +%F_%H%M)
dest=/srv/backups/collabspace/$stamp
mkdir -p "$dest"

# 1. Database (asks for the password, or reads it from ~/.my.cnf with mode 600)
mysqldump -u collabspace -p --single-transaction --routines --default-character-set=utf8mb4 \
    --result-file="$dest/collabspace_v2.sql" collabspace_v2
gzip "$dest/collabspace_v2.sql"

# 2. Private files
tar -C /var/www/collabspace/storage/app -czf "$dest/private.tar.gz" private

# 3. Settings, kept apart and readable only by you
install -m 600 /var/www/collabspace/.env /srv/backups/secrets/.env.$stamp
```

### Restore

```bash
php /var/www/collabspace/artisan down

gunzip -c "$dest/collabspace_v2.sql.gz" | mysql -u collabspace -p collabspace_v2
tar -C /var/www/collabspace/storage/app -xzf "$dest/private.tar.gz"
chown -R www-data:www-data /var/www/collabspace/storage/app/private   # the web server's user

php /var/www/collabspace/artisan config:clear
php /var/www/collabspace/artisan up
```

Delete old backups on a schedule you can justify (for example keep 14 dailies and 6 monthlies), because each one is a full copy of student data.

## Deleted documents and the purge command

When a student deletes a document it is only hidden: the row, its versions and its pictures stay (so a lecturer can still read it, and what it earned still counts as evidence). Nothing removes it automatically.

`documents:purge-deleted` removes deleted documents for good, with their versions, picture rows and picture files, and the activity that referred to them (so their contribution credit goes too):

```powershell
php artisan documents:purge-deleted --days=365 --dry-run   # lists what would go, removes nothing
php artisan documents:purge-deleted --days=365             # removes it
```

- `--days` is required and has no default. Only documents deleted **more than** that many days ago are touched.
- It is never scheduled. Run it by hand, after marking is finished and **after a fresh backup**: a purge cannot be undone.

## Before the sections migration

`2026_11_11_000001_create_sections_and_enrolments` adds the `sections` and `enrolments` tables and a nullable `section_id` on `groups` and `announcements`, then **writes data**: every subject gets a default section called "Main", its groups are put in it, and every student already in one of its groups is enrolled in that subject. It changes no existing column and is safe to run twice, but it is a data migration, so **take a backup first** (the dump above), then:

```powershell
php artisan migrate
```

To undo it, restore the backup (the migration's `down()` drops the two tables and the two columns, and the enrolments with them).

## Subject files (project question, marking guide)

Files a lecturer attaches to a subject live in `storage/app/private/subject-files/{subject id}/`, under random names (the original name is only in the database). That folder is inside `storage/app/private`, so the **private-storage backup above already covers it**; back the database and that folder up together, because the table `subject_files` points at the files by path. Nothing is ever written under `public/` or `storage/app/public`. The migration `2026_11_12_000001_create_subject_files_table` is additive: take the usual backup, then `php artisan migrate`.

PHP limits (XAMPP: `C:\xampp\php\php.ini`, then restart Apache): `file_uploads = On`, `upload_max_filesize` at least `12M`, `post_max_size` at least `12M` (a file is capped at 10 MB by the application), and the `fileinfo` and `zip` extensions enabled (`extension=fileinfo`, `extension=zip`), because the content of a file is checked, not only its name. A request over `post_max_size` shows a friendly "That file is too large" page instead of a blank error.

## Before the assessments migration

`2026_11_14_000001_create_assessments_table` adds the `assessments` table and `rubrics.assessment_id`, replaces the rubric version key `unique(subject_id, version)` with `unique(assessment_id, version)` (versions and "current" are now counted **per assessment**), and then **writes data**: every subject gets one assessment called "Project" (due on its `project_deadline`, no start date) and all of its existing rubrics move into it. Marks, approvals, suggestions and frozen snapshots hang off a rubric, so they follow it unchanged. `subjects.project_deadline` is kept, and nothing in `activity_logs` is ever touched. It is safe to run twice, but **take a backup first** (the dump above), then `php artisan migrate`. Going back means restoring the backup: the `down()` removes the table and the column but does not renumber rubric versions.

## Before the per-assessment brief and files migration

`2026_11_15_000001_add_brief_and_files_to_assessments` adds `assessments.brief` and `subject_files.assessment_id`, then **writes data once, with the columns**: each subject's existing brief is copied into its first assessment (only where that assessment has no brief yet), and its existing files are assigned to that assessment, so what students read stays the same. `subjects.brief` is kept: it is read only as the fallback while every assessment's brief is empty. A file with no assessment means "whole subject" and shows under every assessment; deleting an assessment turns its files into whole-subject files rather than deleting them. Take a backup first (the dump above, and the private storage folder), then `php artisan migrate`. To go back, restore the backup.
