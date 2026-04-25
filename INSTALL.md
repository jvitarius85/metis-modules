# Install Metis

Metis uses a browser-based installer. No manual SQL import is required for a
normal install.

## Requirements

- PHP 8.1 or newer
- MariaDB or MySQL with InnoDB
- Apache with rewrite support, or Nginx with equivalent rewrite/protection rules
- Writable `storage/` and `system/config/`
- PHP extensions commonly required by Metis: `mysqli`, `json`, `mbstring`,
  `openssl`, `pdo`
- `ZipArchive` recommended for updates and backups
- `libsodium` or OpenSSL recommended for encrypted secrets and future encrypted
  backup support
- Outbound HTTPS recommended for release checks and GitHub metadata

Baseline hosting recommendation:

- 2 GB RAM minimum, 4 GB preferred
- 2 CPU cores minimum
- 60 GB storage minimum, 100 GB preferred
- PHP memory limit of 256 MB minimum, 512 MB preferred
- OPcache enabled

## Install Steps

1. Upload or clone Metis:

   ```bash
   git clone https://github.com/jvitarius85/metis.git
   ```

2. Point the web root to the Metis root directory.

3. Open the site URL in a browser.

4. Complete the installer:

   - review system prechecks
   - repair writable folders when available
   - enter database credentials
   - verify database connectivity
   - enter basic branding
   - create the first administrator
   - begin installation

5. The installer creates configuration, installs core and module tables, enables
   protections, writes the install lock, and redirects to `/admin/`.

## Apache and Nginx

Apache installs rely on `.htaccess` rewrite and protection rules. The server must
allow those rules to run.

Nginx does not read `.htaccess`. If Metis is installed on Nginx, equivalent
server rules must be configured by the server administrator. Metis should not be
allowed to expose private paths such as:

- `system/config/`
- `system/src/`
- `storage/logs/`
- `storage/backups/`
- `storage/runtime/`

## After Install

Log in to `/admin/`, review Settings, configure integrations, confirm backups,
and verify cron/update health for the production environment.
