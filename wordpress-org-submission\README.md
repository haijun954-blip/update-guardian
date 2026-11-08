# Update Guardian

Update Guardian helps WordPress administrators recover from plugin updates that break the site. It saves the previous plugin files before an update, collects evidence after the update, and gives you a rollback action from WordPress admin.

Use Update Guardian when an update causes:

- character corruption or encoding problems
- broken layouts
- Elementor editor failures
- missing features or runtime errors
- a blank page or WordPress critical error

The plugin is designed for WordPress site administrators who need a fast local recovery path without waiting for a hosting provider or editing files over SFTP.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- Write access to `wp-content`
- PHP `ZipArchive` for compressed snapshots

Without `ZipArchive`, Update Guardian uses an uncompressed snapshot directory. Confirm that your web server blocks direct access to `wp-content/update-guardian/`.

## Install

1. In WordPress admin, open **Plugins > Add New Plugin**.
2. Select **Upload Plugin** and choose `update-guardian-0.1.0.zip`.
3. Install and activate the plugin.
4. Open **Update Guardian** and confirm that **Emergency recovery** shows **Ready**.
5. Run one plugin update on a staging site and verify that the snapshot and rollback action appear.

## How it works

1. Before a plugin update, Update Guardian copies the installed plugin directory.
2. After the update, it records the old and new versions.
3. It checks the home page and REST API for HTTP errors, blank responses, critical errors, and invalid UTF-8.
4. It captures PHP fatal errors and browser script errors when they can be traced to a plugin path.
5. It calculates a risk score from the available evidence and update timing.
6. You can restore the previous files from the **Updates** tab.

If several plugins were updated in one request, use **Roll back batch** to restore the whole batch.

## Emergency recovery

Update Guardian installs a small must-use plugin. The must-use plugin loads before regular WordPress plugins and provides a token-protected recovery page.

Use this entry point when a failed update prevents you from opening WordPress admin:

1. Open the recovery link sent to the administrator email.
2. Confirm the plugin and version shown on the page.
3. Select **Restore the previous version**.
4. Refresh the site after the success message appears.

Each recovery link is single-use. You can also generate a new link from the update record in WordPress admin.

## Settings

The default settings work for most sites.

- **Block the plugin update:** stops an update when Update Guardian cannot create a snapshot.
- **PHP fatal errors:** records fatal errors that can be associated with a recent plugin update.
- **Plugin script errors:** records browser errors reported by signed-in administrators.
- **Monitoring window:** controls how long errors remain associated with a recent update.
- **Email an emergency recovery link:** sends recovery links to the site administrator after updates.
- **Retention days:** removes old snapshots after the configured period.
- **Maximum snapshots:** limits the number of stored snapshots.

## Data and privacy

Update Guardian runs on your WordPress site. It does not send plugin files, error messages, or site data to an external service.

Snapshots are stored in:

```text
wp-content/update-guardian/backups/
```

The plugin adds `.htaccess`, `web.config`, and `index.php` protection files to the storage directory.

For Nginx, add this rule:

```nginx
location ^~ /wp-content/update-guardian/ {
    deny all;
    return 403;
}
```

Diagnostic records contain plugin metadata, error summaries, and request paths. Recovery tokens are stored as hashes.

## Limitations

- Update Guardian restores plugin files. It does not reverse database migrations, remote configuration changes, or cache changes performed by an update.
- Updates made through SFTP, Git, a hosting control panel, or manual file replacement are not intercepted.
- The current release does not perform screenshot-based visual regression testing.
- A CDN, WAF, or host policy can block loopback requests. Update Guardian reports this condition instead of treating it as a healthy result.
- Themes and WordPress core updates are not covered in version 0.1.0.

## Troubleshooting

### Emergency recovery is unavailable

Deactivate and reactivate Update Guardian. The plugin reinstalls the must-use recovery loader during activation.

### Snapshot creation fails

Confirm that WordPress can write to `wp-content/update-guardian/` and that the account running PHP has enough storage.

### Health checks cannot reach the site

Confirm that the host allows loopback HTTP requests to the site URL. Error evidence and manual rollback still work when loopback checks are blocked.

### A rollback reports a file permission error

The web server user must be able to rename directories inside `wp-content/plugins/`. Correct the ownership or permissions and retry the rollback.

## Development

The plugin has no Composer or Node dependencies at runtime. PHP follows the WordPress coding style.

Release validation includes:

- WordPress official Plugin Check
- PHP syntax validation for every PHP file
- JavaScript syntax validation
- plugin activation on a clean WordPress site
- snapshot restoration through admin and the emergency entry point
- Chinese and English locale checks

## Support

Report bugs and feature requests through the GitHub issue tracker:

<https://github.com/haijun954-blip/update-guardian/issues>

## License

Update Guardian is licensed under GPLv2 or later.

