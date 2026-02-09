# IPTV Detective 2.1

## What is IPTV Detective?

IPTV Detective is a web-based tool for analyzing, comparing, and managing IPTV provider data. It allows users to submit IPTV provider information, compare provider details, and manage submissions through an admin dashboard. The platform is designed for research, moderation, and community-driven IPTV data collection.

### Key Features
- Submit and store IPTV provider details using Xtream credentials
- Compare providers and group similar entries
- Admin dashboard for reviewing and moderating submissions
- Health and progress monitoring pages
- Secure admin login with CAPTCHA support
 - Choose a Seller source and provide optional Seller info (username or profile URL) when submitting providers; this metadata is stored with submissions and is visible in the admin dashboard and API.

## Setup Instructions

1. **Configure your database and credentials:**

1. **Configure your database and credentials:**
    - Copy `inc/config.example.php` to `inc/config.php`.
    - Edit `inc/config.php` and fill in your own database host, name, user, password, and Turnstile CAPTCHA keys.
    - Do NOT commit your real config.php to version control or share it publicly.
    - Set up your MySQL database and import any required schema (see `inc/db.php` for table expectations).
3. **Rename the admin dashboard:**
	- For security, rename `admin.php` to something unique (e.g., `admin_xyz123.php`).

4. **Deploy to your web server:**
	- Upload the files to your PHP-enabled web server.
	- Ensure the `logs/` directory is writable if you want to keep logs.

5. **Access the site:**
	- Visit the main page (`index.html`) to use the submission and comparison features.
	- Use your renamed admin dashboard to moderate and review submissions.

## Security Notice

**Always rename the admin dashboard file and keep your credentials private.**


**Never commit inc/config.php with real credentials to any public repository. Use inc/config.example.php as a template.**
## .gitignore
Sensitive files like `inc/config.php` and logs are excluded from version control.

## License
See LICENSE file if present.
