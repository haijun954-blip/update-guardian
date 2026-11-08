=== Update Guardian ===
Contributors: haijun954-blip
Tags: plugin rollback, plugin updates, error monitoring, elementor, recovery
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Save a snapshot before WordPress plugin updates, identify likely update regressions, and restore the previous plugin version with one click.

== Description ==

Update Guardian helps WordPress administrators recover from plugin updates that break a site.

The plugin saves plugin files before an update and keeps the previous version available for a one-click rollback. After the update, it records the version change, checks basic site health, and collects errors that can be traced to a plugin directory.

Use Update Guardian when a plugin update causes:

* Character corruption or encoding problems
* Broken page layouts
* Elementor editor failures
* Missing features or runtime errors
* A blank page or WordPress critical error

### Main features

* **Pre-update snapshots.** Copy plugin files before the WordPress upgrader replaces them.
* **Update records.** Save the plugin name, old version, new version, update time, and update batch.
* **Fault evidence.** Capture PHP fatal errors and browser script errors when they can be associated with a plugin path.
* **Health checks.** Check the home page and REST API for HTTP errors, blank responses, critical errors, and invalid UTF-8.
* **Risk scoring.** Combine error evidence, timing, and health results to prioritize investigation.
* **Single plugin rollback.** Restore one plugin to its previous version.
* **Batch rollback.** Restore every plugin updated in the same request.
* **Quarantine.** Keep the faulty files in a separate directory instead of deleting them.
* **Emergency recovery.** Use a single-use link when a fatal error prevents access to WordPress admin.

### How it works

1. Update Guardian copies the installed plugin files before the update.
2. WordPress installs the new version and Update Guardian records the version change.
3. A scheduled health check inspects the site response and diagnostic evidence.
4. You review the risk score and choose **Roll back** or **Roll back batch**.
5. Update Guardian moves the faulty version into quarantine and restores the snapshot.

Update Guardian installs a small must-use plugin during activation. This loader provides the emergency recovery page before regular plugins load.

### Data and privacy

Update Guardian runs locally on your WordPress site. It does not send plugin files, error messages, or site data to an external service.

Snapshots are stored in `wp-content/update-guardian/backups/`. Recovery tokens are stored as hashes. Diagnostic records contain plugin metadata, error summaries, and request paths.

For Nginx, block direct access to the storage directory:

`location ^~ /wp-content/update-guardian/ { deny all; return 403; }`

== Installation ==

1. In WordPress admin, open **Plugins > Add New Plugin**.
2. Select **Upload Plugin** and choose `update-guardian-0.1.0.zip`.
3. Select **Install Now**, then activate the plugin.
4. Open **Update Guardian** in the admin menu.
5. Confirm that **Emergency recovery** shows **Ready**.
6. Run one plugin update on a staging site and verify that a snapshot and rollback action appear.

The web server user must be able to write to `wp-content/update-guardian/` and rename directories inside `wp-content/plugins/`.

== Frequently Asked Questions ==

= Does Update Guardian roll back database changes? =

No. It restores plugin files. It does not reverse database migrations, remote configuration changes, or cache changes performed by the update.

= Does it track updates made over SFTP or Git? =

No. Version 0.1.0 protects updates run through the WordPress plugin upgrader.

= Why does a health check show an unknown result? =

Some hosts, CDNs, or firewalls block a site from requesting its own home page. Update Guardian reports the condition as unknown instead of treating it as healthy.

= Why can't I create a snapshot? =

Check write access to `wp-content/update-guardian/` and confirm that the PHP process has enough storage. If `ZipArchive` is unavailable, the plugin uses an uncompressed snapshot directory.

= How do I recover when WordPress admin does not load? =

Open the emergency recovery link sent to the administrator email. Each link is single-use. If email is unavailable, restore a host-level backup or use the command line to deactivate the plugin.

= Does Update Guardian work with Elementor? =

Update Guardian does not modify Elementor itself. It can preserve the previous files of Elementor or an Elementor add-on before an update. It also records browser errors that can be traced to the affected plugin directory.

= Is any data sent to an external service? =

No. Snapshots and diagnostic records remain on your WordPress site.

== Screenshots ==

1. Update Guardian dashboard with update and health status.
2. Update records with version changes, risk scores, and rollback actions.
3. Emergency recovery page available when WordPress admin is unavailable.
4. Protection settings for snapshots, monitoring, and retention.

== Changelog ==

= 0.1.0 =

* Added pre-update plugin file snapshots.
* Added update version and batch records.
* Added PHP fatal error and browser error evidence.
* Added home page and REST API health checks.
* Added single plugin and batch rollback.
* Added a token-protected emergency recovery entry point.
* Added snapshot retention and monitoring settings.

== Upgrade Notice ==

= 0.1.0 =

Initial public release.

