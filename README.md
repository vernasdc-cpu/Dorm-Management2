# Heart Ladies Dormitory Management System

Modern, responsive, database-driven dormitory management web app for a ladies' dormitory.
Theme: pink `#FF00FF` / purple `#8F00FF`. Plain **PHP 8 + MySQL/MariaDB**, no build step, no paid dependencies —
runs in **Docker** locally and on **Hostinger** (shared or VPS).

## Features
- Role-based access: Super Admin, Admin Staff, Housekeeping, Resident
- Tenant portal: each tenant sees their own account-linked dashboard and records; roommate identities are not shown
- Tenant Profiles: Tenant/Resident & Tenant/Commercial records with 2x2 photo, floor/unit assignment, contract dates and payment status; assigned tenants appear in Room Details and automatically occupy one bed (remaining beds show Available). Profiles with an email also get a linked tenant portal account and welcome email.
- Housekeeping cleaning records: housekeepers log Date, Unit, Status (Clean/Unclean) and Remark/Note; super admin can manage all records
- Login with rate limiting, password hashing, CSRF, audit logs
- Dashboards per role with Chart.js charts
- Rooms + visual occupancy, dynamic Shared-room Max Occupants / Bed number fields, allocation / transfer / vacate / reserve (capacity-guarded); rooms with bills, contracts or other history are archived as Unavailable instead of being deleted
- Room Allocation includes Tenant Profiles in the Assign dropdown, a Tenant Profiles in Rooms list, and a Super Admin-only Clear History action for vacated/transferred records
- Contracts with file upload/download, auto Expiring-Soon/Expired computation, monitoring page (tenant name, email, nearest-expiry-first sorting, email reminder button); tenant-profile contracts are created and synced automatically
- Inventory (equipment / linen / cleaning supplies), transactions, low-stock alerts
- Repair/maintenance workflow with photo upload + resident notifications on status change
- Billing generator, payments, printable Invoice & Official Receipt, CSV exports
- Notifications center, reports (print/CSV), system settings, profile

## Demo accounts (created by installer)
| Role | Email | Password |
|---|---|---|
| Super Admin | superadmin@heartdorm.com | Super@123 |
| Admin Staff | admin@heartdorm.com | Admin@123 |
| Housekeeping | housekeeping@heartdorm.com | House@123 |


## Local development (Docker)

Prerequisites: Docker Desktop.

```bash
cd "Dorm Management2"
cp .env.example .env        # Windows: copy .env.example .env
# edit .env passwords first!
docker compose up -d --build
```

The app container waits for MySQL, then creates the schema, seed data and demo
accounts automatically the first time the database is empty. On startup it also
applies the idempotent tenant account-link and tenant-application billing migration
and the unit/room relationship migration to existing databases, ensuring account
link columns and units are present.
To apply it manually without running the installer:

```bash
docker compose exec app php scripts/migrate.php
```
Audit logs can be moved to Trash and restored for 7 days; expired entries are permanently
removed on the next Audit Logs visit.
Housekeeping records are shown by month and include supervisor inspection and verification.
Only housekeeping staff can add records and manage records they created. Supervisors (Admin
Staff and Super Admin) can edit verification and remarks only. Use the Housekeeping page to
export the selected month as CSV or print it.

Open: app http://localhost:8080 · phpMyAdmin http://localhost:8081 · Mailpit http://localhost:8025

Existing databases created before Tenant Profiles: import `database/03-tenant-profiles.sql`,
`database/04-tenant-profile-extensions.sql`, `database/05-housekeeping-records.sql`,
`database/06-tenant-beds.sql`, `database/07-tenant-contracts.sql`,
`database/08-tenant-billing-payments.sql` and
`database/12-tenant-profile-status.sql` (all are safe to run again).
Migration 06 adds tenant-to-bed links and refreshes room occupancy. Migration 07 links tenant
profiles to contracts and backfills profiles that already have contract dates.
Migration 08 creates the tenant billing payment submissions table.
Migration 12 adds an Active/Inactive profile status without changing payment status.
Migration 16 creates the tenant Reports/Maintenance table.
Migration 17 tracks when notifications are read. Tenant notifications are automatically
removed after 3 days when read, or after 7 days if unread, as the tenant uses the app.
Migration 14 adds units by floor and links existing rooms to the unit number before
their dash (for example, room `301-1` is linked to Unit `301`). New units can be
added from the Rooms page; use **Create Room** to add rooms to a unit.

Saving a tenant profile with both contract dates now automatically creates or updates its contract,
so it appears in **Contracts** and **Contract Monitoring** without a separate portal account.
Updating the contract dates in either place keeps the tenant profile in sync.

Adding a tenant profile with an email creates or links a tenant portal account and sends a welcome
email. New accounts use the tenant's name with spaces removed followed by `@123` as the initial
password; tenants can change it from **My Profile** after logging in. Use **Manage Accounts** from
Tenant Profiles to review tenant accounts and create missing accounts.

Email reminders use Mailpit by default in Docker. Mailpit catches outgoing messages locally, so no
real inbox is used; open http://localhost:8025 to read them. To deliver to real inboxes, replace the
`MAIL_*` values in `.env` with your SMTP provider's host, port, username, password and encryption,
then restart the app (`docker compose up -d`).

Useful:
```bash
docker compose logs -f app
docker compose exec db mysql -uheart_dorm -p heart_dorm_db
docker compose down        # keep data (volume persists)
docker compose down -v     # wipe data
```

### Backup / restore
```bash
docker compose exec db mysqldump -uroot -p"$DB_ROOT_PASS" heart_dorm_db > backup.sql
Get-Content backup.sql | docker compose exec -T db mysql -uroot -p"$DB_ROOT_PASS" heart_dorm_db
```

## Hostinger deployment

**Option A — Hostinger VPS (Docker supported):** copy project, set `.env`, `docker compose up -d --build`, run installer, point domain to server.

**Option B — Hostinger shared hosting (no Docker, recommended):**
1. hPanel → Websites → create site (PHP 8.1+), note document root (`public_html`).
2. Databases → create MySQL database + user; note host (usually `localhost`), db name, user, password.
3. Upload everything **except** `docker-compose.yml`, `Dockerfile`, `.env` via File Manager/Git/FTP.
4. On Hostinger set these env values — edit `config/app.php` fallback or create `.env` **above** `public_html`:
   `DB_HOST=localhost, DB_NAME=u123_heart, DB_USER=u123_heart, DB_PASS=***, APP_URL=https://yourdomain.com`
   Required for contract email reminders: `MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS, MAIL_ENCRYPTION, MAIL_FROM, MAIL_FROM_NAME`
   (use a real SMTP provider such as Hostinger Email, Gmail, Brevo or Mailgun; Mailpit is local-only).
   (App reads `.env` next to `config/`; never expose it in web root.)
5. hPanel → phpMyAdmin → import `database/01-schema.sql`, then `database/02-seed.sql`.
6. Create demo/production users: run `scripts/install.php` once via SSH (`php scripts/install.php`),
   or insert users manually with `password_hash()`-generated bcrypt hashes.
7. Set `uploads/` writable (755), keep `uploads/.htaccess` (blocks direct file access; downloads go through login-checked `index.php?page=download`).
8. Delete `scripts/install.php` from production after setup. Set `APP_ENV=production`.

Docker is then used only for local dev/testing; production uses Hostinger's native MySQL — same schema, no code changes.

## Project structure
```
index.php               front controller + router + RBAC map + secure download
config/app.php          .env loader + constants (no secrets in code)
config/database.php     PDO connection (prepared statements everywhere)
includes/helpers.php    escaping, CSRF, flash, audit, upload validation, contract status
includes/auth.php       session auth, login rate limit, permission matrix
includes/layout.php     sidebar/topbar layout, theme
pages/*.php             one file per module (dashboards, rooms, billing, ...)
assets/css, assets/js   theme + small JS helpers (Chart.js via CDN)
database/01-schema.sql  normalized schema + FKs + indexes
database/02-seed.sql    floors, room types, rooms, beds, inventory
scripts/install.php     one-shot installer: schema + seed + hashed demo users
scripts/bootstrap.php   first-run check used by Docker (installs only when users table is empty)
docker/entrypoint.sh    waits for DB, then runs the first-run bootstrap
uploads/                contracts / repairs / profiles / tenants (denied direct access; mail.log is also here)
```

## Security notes
- Passwords: `password_hash()`/`password_verify()` only; login rate-limited (5/10 min).
- All queries use PDO prepared statements; all output escaped via `e()`.
- CSRF tokens on every POST; file uploads validated by extension + MIME + 5 MB limit, stored with random names outside direct web access.
- `$_SESSION` cookies are HttpOnly + SameSite=Lax; audit log records user/action/module/record/IP.

## Test checklist (major workflows)
Login → role permissions → create resident (Users) → create Shared room with Max 3 (Bed 1/2/3) → add a tenant → room shows 2 beds available → allocate (Allocation) →
upload/download contract → add inventory + transaction → submit repair → update repair status (notification sent) →
generate billing → record payment → invoice/receipt print → contract expiration widget → send notification →
dashboards → backup/restore → `docker compose up -d` fresh start.
