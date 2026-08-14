# Staging Environment — Setup & Deploy Flow

Goal: every deploy hits a production-identical staging site first, so deploy
and migration breakage is caught before agents ever see it. Same Hostinger
account = same PHP version, LiteSpeed, and WAF behaviour as production.

## One-time setup (hPanel, ~30 minutes)

1. **Subdomain** — hPanel → Domains → Subdomains → create
   `staging-crm.base-fare.com` with document root
   `domains/base-fare.com/public_html/staging-crm/public`
   (the `/public` suffix matters — same layout as production).
2. **Clone the repo** (SSH):
   ```
   cd ~/domains/base-fare.com/public_html
   git clone https://github.com/johnmofficial16-prog/basefare-crm.git staging-crm
   cd staging-crm && git checkout dev
   composer install --no-dev
   ```
3. **Staging database** — hPanel → Databases → create `u501549865_crmstage` +
   its own user. NEVER point staging at the production DB.
   Seed it from a production backup:
   ```
   gunzip < ~/domains/base-fare.com/public_html/crm/storage/backups/nightly_<latest>.sql.gz \
     | mysql -u <stage_user> -p u501549865_crmstage
   ```
4. **.env** — copy `.env.example`, fill staging DB credentials, and set:
   `APP_ENV=production`, `APP_DEBUG=true` (production code paths, visible
   errors), `APP_URL=https://staging-crm.base-fare.com`.
   Copy the SAME `ENCRYPTION_KEY_A/B` as production **only if** the staging DB
   was seeded from a production dump (otherwise stored cards won't decrypt —
   which is also a fine privacy choice for staging).
5. **Access control** — staging holds a copy of production data. Add an
   `.htaccess` basic-auth gate on the staging docroot, or at minimum keep the
   IP restriction middleware active. Do not index: hPanel → add
   `staging-crm` to robots disallow (or rely on the auth gate).
6. **Migrations ledger** — run `php hostinger_migrate.php` once; the seeded DB
   already contains `schema_migrations` from the production dump, so it will
   simply report "Nothing to migrate".

## Every-deploy flow (replaces direct-to-prod pulls)

```
push origin dev
  → SSH: cd ~/domains/base-fare.com/public_html/staging-crm
         git pull origin dev && php hostinger_migrate.php     # staging first
  → click through the changed screens on staging-crm.base-fare.com
  → SSH: cd ~/domains/base-fare.com/public_html/crm
         git pull origin dev && php hostinger_migrate.php     # then production
```

Rule of thumb: migrations and bootstrap/middleware changes ALWAYS go through
staging. Pure view tweaks may go direct if urgent — judgement call.

## Refreshing staging data

Monthly (or before big tests): re-seed from the latest nightly backup, then
re-run `php hostinger_migrate.php`. Staging drift is normal; stale staging
data is fine, stale staging *schema* is not.
