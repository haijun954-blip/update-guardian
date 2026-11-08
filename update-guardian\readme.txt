=== Update Guardian ===
Contributors: updateguardian
Tags: rollback, plugin update, diagnostics, elementor
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Snapshot WordPress plugin updates, identify likely regressions, and restore the previous plugin files.

== Description ==

Update Guardian creates a file snapshot before WordPress updates a plugin. After the update it records version changes, collects PHP and browser errors that can be traced to a plugin directory, probes the site health, and offers one-click rollback.

A small must-use recovery loader remains available if a broken update prevents the normal admin screen from loading.

== Installation ==

1. Upload the plugin ZIP and activate it.
2. Open "更新守护" in the WordPress admin.
3. Verify that "紧急恢复" is ready.
4. Run a plugin update and confirm that a snapshot and rollback action are available.

== Frequently Asked Questions ==

= Does it roll back database migrations? =

No. It restores plugin files. Database migrations and remote configuration changes are not reversed automatically.

= Does it track manual file replacements? =

No. Only updates run through the WordPress upgrader are intercepted.

= What should an Nginx site do? =

Deny direct access to `/wp-content/update-guardian/`.

== Changelog ==

= 0.1.0 =

* Initial WordPress MVP.
* Pre-update plugin snapshots.
* PHP fatal and browser error correlation.
* Single and batch rollback.
* Token-protected emergency recovery entry.

