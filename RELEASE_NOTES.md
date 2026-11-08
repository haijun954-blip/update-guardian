# Update Guardian v0.1.0

The first public release of Update Guardian.

## Added

- Plugin file snapshots before WordPress updates.
- Version, timestamp, and update-batch records.
- PHP fatal error and browser script error evidence.
- Home page and REST API health checks.
- Risk scoring for recently updated plugins.
- Single plugin and batch rollback.
- Quarantine for faulty plugin files.
- A single-use emergency recovery entry point.
- Email recovery links, monitoring, and snapshot retention settings.
- English administrator interface and WordPress.org documentation.

## Compatibility

- WordPress 6.0 or later
- PHP 7.4 or later
- WordPress 7.1 tested

## Validation

- WordPress official Plugin Check 2.2.0: `Checks complete. No errors found.`
- PHP syntax validation: passed for all PHP files.
- JavaScript syntax validation: passed.
- Snapshot creation and English emergency rollback: passed.

## Limitations

- Database migrations are not reversed.
- SFTP, Git, and control-panel file replacements are not intercepted.
- Screenshot-based visual regression testing is not included.
- Theme and WordPress core updates are not covered in version 0.1.0.

