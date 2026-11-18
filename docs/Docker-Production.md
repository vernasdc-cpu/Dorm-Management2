# Docker VPS production setup

Keep the production environment file outside the project directory and the
Apache document root:

```sh
sudo install -d -o "$USER" -g "$USER" -m 700 /etc/heart-dorm
sudo cp production.env.example /etc/heart-dorm/production.env
sudo chown "$USER:$USER" /etc/heart-dorm/production.env
sudo chmod 600 /etc/heart-dorm/production.env
sudo nano /etc/heart-dorm/production.env
```

Set unique database passwords, a random `APP_KEY` with at least 32 characters,
your public `APP_URL`, and real SMTP settings. Do not copy the example values
unchanged. Generate an application key with `openssl rand -hex 32`. Start or
update the stack from the project directory:

```sh
docker compose --env-file /etc/heart-dorm/production.env up -d --build
```

The configured `APP_URL` is passed into the app container and is used in tenant
welcome emails as the portal link.

The app listens on `127.0.0.1:8080` by default. Put it behind a TLS-enabled
reverse proxy and forward requests only to that loopback address. Configure
the proxy to overwrite `X-Forwarded-For` with the connecting client's IP (for
Nginx: `proxy_set_header X-Forwarded-For $remote_addr;`). The app port is
loopback-only, so only the trusted host proxy should be able to reach it.
Keep MySQL unpublished. phpMyAdmin and Mailpit are optional Compose profiles
and bind to loopback only; do not enable them on a public interface.

For an existing MySQL data volume, changing `DB_PASS` in this file does not
change the password already stored in MySQL. Update the MySQL account password
to match before restarting the app with the new value.

On the first deployment, the bootstrap applies database migrations. For an
existing database, run:

```sh
docker compose --env-file /etc/heart-dorm/production.env exec app php scripts/migrate.php
```

After deployment, confirm that only HTTPS is publicly reachable and direct
requests to `/.env`, `/config/app.php`, `/scripts/migrate.php`, and
`/pages/tenants.php` are denied. Keep database and application backups outside
the public web root.

Uploads are excluded from the Docker build image; the existing `uploads`
directory is mounted at runtime and must be backed up separately.

The Housekeeping Others checklist uses the current date in Philippine time.
Each housekeeper can submit one checklist per day; existing historical entries
are preserved by the migration. Selecting Window, Wall Fan, or Exhaust Fan
requires the unit cleaned, which is included in the checklist report and CSV
export. Housekeepers can edit their own saved checklists without changing the
recorded date.
Each housekeeper can create one cleaning record per unit per Philippine day.
Other housekeepers can record the same unit, and historical duplicates remain
available for review and editing.

Deleted user accounts retain their history but no longer reserve their email.
Existing non-deleted accounts, including inactive accounts, must still have
unique emails. When an email is reused for a resident account, link its tenant
profile deliberately instead of relying on automatic email matching.
