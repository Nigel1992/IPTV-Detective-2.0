## Changelog

All changes below are high-level and do not include any sensitive information (no keys, passwords, or server details).

### 2026-02-08 — v2.1.1
- Enabled and standardized Cloudflare Turnstile CAPTCHA on the public homepage and admin login (keys loaded from `config.php`).
- Moved and adjusted the Turnstile widget position on the homepage so it fits within the form layout.
- Re-enabled server-side Turnstile verification for admin logins and provider submissions.
- Removed M3U file upload/parse support from the UI and server: submissions now rely on client-provided counts and server-side comparisons.
- Updated `submit_provider.php` and related client JS to accept counts-only submissions and avoid accepting raw playlist uploads.
- Fixed a syntax/brace issue in `get_counts.php` and improved its configuration loading behavior.
- Updated help text in the site help modal to match `help.html` (clarifies how the site works and privacy notes).
- Tightened file permissions for sensitive files and added `.htaccess` rules to reduce risk of accidental exposure.
- Minor UI text and loading-state improvements (removed references to M3U in button text, etc.).
- Deployed updates to the configured FTP server.

If you want a more detailed developer-oriented changelog (with file-level diffs), I can prepare that separately — it will exclude any secrets.
