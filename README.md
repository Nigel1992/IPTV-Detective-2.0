# IPTV Detective 2.1

IPTV Detective is a focused web application for analyzing, comparing and moderating IPTV provider data. Built for researchers, moderators and community-curated collections, the app makes it easy to submit provider data, run similarity comparisons, and manage submissions from a secure admin interface.

Live demo: https://astrolume.infinityfreeapp.com/IPTV%20Detective/

## Highlights

- Clean provider submission flow with optional seller metadata
- Automated similarity scoring and grouping (tunable thresholds)
- Admin dashboard for fast moderation and bulk actions
- Deploy tooling (`deploy.sh`) with Semgrep pre-flight checks
- Security-minded defaults: .htaccess protections, logging disabled by default

## Quickstart

1. Copy the example config and fill in secrets:
	- Copy `inc/config.example.php` to `inc/config.php` and edit database and Turnstile keys.
	- Never commit `inc/config.php` to source control.

2. Run a local sanity check before deploying:
	- Ensure PHP syntax is valid: `find . -name "*.php" -exec php -l {} \;`
	- Run the included `deploy.sh` which performs Semgrep scans and uploads via `lftp`.

3. Deploy safely:
	- Use the `deploy.sh` script (it stages, scans, and uploads only production files).
	- Keep a local `.deploy.env` (gitignored) for FTP credentials; `deploy.sh` excludes `.deploy.env` from uploads.

4. Protect sensitive files on shared hosts (InfinityFree):
	- Upload the supplied `.htaccess` to the webroot to block access to `.git`, `*.log`, `.deploy.env`, and debug files.
	- If possible, move any `.git` or secret files outside the webroot via FTP or the host control panel.

## Security Notes

- Logging is disabled by default. Enable logging only after moving the `logs/` directory outside webroot and reviewing permissions.
- Always rename the admin entry point for added obscurity and protect it with Basic Auth or IP restrictions.
- `deploy.sh` runs an early Semgrep scan to block commits/uploads containing secrets or risky patterns.

## Files of Interest

- `index.php` — main UI and client-side logic
- `submit.php`, `submit_provider.php` — submission endpoints
- `get_comparisons.php`, `get_grouped_matches.php` — matching logic
- `inc/config.php` — site configuration (do not commit real values)
- `.htaccess` — recommended HTTP protections (already included)

## Want to help or contribute?

Contributions, bug reports and feature requests are welcome. Please open an issue or submit a pull request. When contributing, avoid committing secrets or local `.env` files.

---

Made with care — keep your deployment and credentials safe.
