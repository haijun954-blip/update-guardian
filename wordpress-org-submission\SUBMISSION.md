# WordPress.org Submission

## Status

The English release is ready for WordPress.org review.

Official Plugin Check 2.2.0 result:

```text
Checks complete. No errors found.
```

The public submission form requires a WordPress.org account. A WordPress.org login is not stored in the local credential store, so the external directory submission still requires the site owner to sign in.

## Submission details

- Plugin name: Update Guardian
- Candidate slug: `update-guardian`
- Version: `0.1.0`
- Requires WordPress: 6.0
- Tested up to: 7.1
- Requires PHP: 7.4
- License: GPLv2 or later
- Repository: https://github.com/haijun954-blip/update-guardian
- SHA-256: `5A3A867769734F7C29B46185E618AB3A2F66FB7671C03F1B7A6A9BA7405C485E`

## Upload

Use this file in the WordPress.org plugin submission form:

```text
update-guardian-0.1.0.zip
```

The ZIP contains one top-level `update-guardian/` directory.

## Directory assets

Upload these files to the WordPress.org SVN `assets/` directory after the plugin is approved:

- `assets/icon-128x128.png`
- `assets/icon-256x256.png`
- `assets/banner-772x250.png`
- `assets/banner-1544x500.png`
- `assets/screenshot-1.png`
- `assets/screenshot-2.png`
- `assets/screenshot-3.png`
- `assets/screenshot-4.png`

## Submit

1. Sign in to WordPress.org.
2. Open <https://wordpress.org/plugins/developers/add/>.
3. Upload `update-guardian-0.1.0.zip`.
4. Confirm the plugin name, description, and GPL-compatible license.
5. Submit the plugin for review.
6. Monitor the WordPress.org email address associated with the account for review feedback.

After approval, WordPress.org creates an SVN repository. Add the plugin to `trunk/`, tag the release under `tags/0.1.0/`, and copy the directory assets into `assets/`.

## Verification

The release passed:

- WordPress 7.1.4 installation and activation
- English admin and emergency recovery flows
- snapshot creation and one-click rollback
- mobile layout checks
- PHP and JavaScript syntax checks
- WordPress official Plugin Check

