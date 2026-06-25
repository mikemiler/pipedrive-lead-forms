# Releasing / Updates

This plugin is **not** distributed through wordpress.org. Updates are delivered
via GitHub Releases and picked up automatically on every install by the bundled
[Plugin Update Checker](lib/plugin-update-checker/) (PUC) library.

## How updates reach a site

1. PUC polls the GitHub Releases API of `mikemiler/pipedrive-lead-forms`
   (configured via `PDLEAD_UPDATE_REPO` in `pipedrive-lead-forms.php`) on a
   WP-Cron schedule (~ every 12 h).
2. It compares the latest release tag with the installed `PDLEAD_VERSION`.
3. If the release is newer, WordPress shows the normal update notice under
   *Plugins* and *Dashboard → Updates*. "View details" is fed by `readme.txt`.
4. The user clicks update; WordPress downloads the **ZIP asset** attached to the
   release, extracts it and replaces the plugin folder.

## Cutting a new release

Keep these three in sync; the CI checks it and fails otherwise:

- `Version:` header in `pipedrive-lead-forms.php`
- `PDLEAD_VERSION` constant in `pipedrive-lead-forms.php`
- the git tag `vX.Y.Z` (leading `v` is stripped by PUC)

Steps:

1. Bump the version in both spots in `pipedrive-lead-forms.php`.
2. Add a `= X.Y.Z =` entry under `== Changelog ==` in `readme.txt` and update
   `Stable tag`.
3. Commit, then tag and push:

   ```bash
   git tag vX.Y.Z
   git push origin main --tags
   ```

4. The `Release` GitHub Action builds `pipedrive-lead-forms.zip` (top-level
   folder `pipedrive-lead-forms/`) and publishes it as a release asset. No
   manual zipping needed.

## Private repo (later)

If the repo becomes private, add authentication after `buildUpdateChecker(...)`:

```php
$update_checker->setAuthentication( PDLEAD_GITHUB_TOKEN );
```

Provide the token via a `wp-config.php` constant or server option, never commit
it. Use a fine-grained PAT with read-only access to this repo.
