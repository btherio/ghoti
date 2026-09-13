# Enabling Apache Vhosts management

The module is **disabled by default**, including its menu, PHP bootstrap, RPC endpoints, theme assets, privileged-helper calls and certificate watcher. Enabling is an explicit administrator choice in Site Settings. No privileged helper or scheduled task is installed automatically.

There are two separate controls:

1. **Site Settings → Enable Apache Vhosts module** loads the optional module.
2. **Apache Vhosts → Settings → Allow changes** permits its write operations when the privileged helper is installed. This also defaults to off on a new installation.

Turning the module off preserves its saved settings and does not remove existing Apache sites or certificates. Re-enabling restores those settings, including a previously enabled Allow changes switch. To return to read-only operation first turn Allow changes off.

## 1. Enable read-only inspection

1. Sign in as an administrator and open **Workspace → Site Settings**.
2. Select **Enable Apache Vhosts module**, save, and reload the page.
3. Open **Workspace → Apache Vhosts → Settings**.
4. Verify the helper path, drop-in directory, read-only Apache configuration path, document-root base and log directory. Leave **Allow changes** off.

Without the privileged helper, the module can inspect configuration files readable by the web-server user. Certificate inspection and commands such as Test config require the helper. Do not loosen permissions on private certificate keys to make inspection work.

## 2. Prepare the server for privileged operations

The supplied helper targets an Apache installation with Arch-style paths and the `http` service account. Have a server administrator review it before granting privileges. On other systems, adjust the installed helper's command paths, `DROPIN_DIR`, `HTTPD_SERVICE`, and the sudoers username. The PHP settings and installed helper must agree: changing a path in the web form does not rewrite the root-owned helper.

Before installation:

- Back up the Apache configuration and confirm `apachectl configtest` succeeds.
- Ensure Apache includes the intended drop-in directory, for example `IncludeOptional conf/conf.d/*.conf` for the supplied `/etc/httpd` layout. Review duplicate includes before editing the active configuration.
- Confirm PHP can use `proc_open` and the database driver; privileged operations use `sudo -n`.
- Install/configure certbot separately if certificate issuance or renewal is needed. Confirm DNS, HTTP challenge reachability and the selected webroot before requesting a certificate.

Run these commands as a server administrator from the repository root, after adjusting paths/users for your installation:

```sh
sudo install -o root -g root -m 0755 mod/vhosts/ghoti-vhosts-helper /usr/local/sbin/ghoti-vhosts-helper
sudo install -o root -g root -m 0440 mod/vhosts/sudoers.ghoti-vhosts /etc/sudoers.d/ghoti-vhosts
sudo visudo -c
sudo -u http sudo -n /usr/local/sbin/ghoti-vhosts-helper ping
sudo -u http sudo -n /usr/local/sbin/ghoti-vhosts-helper configtest
```

Keep the helper and its parent directories root-controlled, and never make the helper writable by PHP. The sudoers rule grants execution of this specific helper; do not replace it with unrestricted sudo. Stop and correct any sudoers/configuration errors before proceeding.

## 3. Allow changes deliberately

1. Return to **Apache Vhosts → Settings** and confirm that the helper reports available.
2. Check paths again, then select **Allow changes** and save.
3. Use **Test config** and **Live map** to inspect the current configuration before editing.
4. Create or modify a vhost and review the result. The helper tests configuration changes and keeps backups under `/var/lib/ghoti-vhosts/backups`; it reloads Apache after successful changes.

Importing existing vhosts rewrites server configuration. Review the import plan and keep a separate backup first. Adopted files retain directives that the form cannot represent and remain read-only in the editor; do not expect the form to round-trip arbitrary Apache configuration.

## 4. Optional certificate monitoring and email

1. Configure working SMTP in **Mail Settings**.
2. In **Apache Vhosts → Settings**, configure the certificate contact and notification recipient, then enable notifications if desired. Notifications default to off.
3. Run the watcher once in dry-run mode as the web-server account, using an absolute installation path:

```sh
sudo -u http /usr/bin/php /path/to/ghoti/mod/vhosts/vhosts.certwatch.php --dry-run
```

The CLI PHP configuration must have the required database extension enabled. Dry-run sends no notifications and saves no certificate snapshot.

4. If the result is correct, optionally add a daily job to the administrator's crontab, adjusting paths and account:

```cron
0 7 * * * sudo -u http /usr/bin/php /path/to/ghoti/mod/vhosts/vhosts.certwatch.php --quiet
```

The watcher observes certificate state; it does not replace certbot's renewal timer. Site-wide critical-log alerts are a separate setting from vhost certificate notifications.

## 5. Disable again

Clear **Enable Apache Vhosts module** in Site Settings, save, and reload. New requests do not load/register the module; its watcher exits without contacting the helper, sending mail or updating certificate state. Existing Apache sites continue to operate, and externally configured certbot renewal jobs continue independently.

To revoke host-level capability as well, a server administrator can remove the dedicated watcher schedule and the module's sudoers rule. Disabling a CMS preference does not uninstall server software or revoke privileges granted outside the CMS.
