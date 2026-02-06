
# IPTV Detective 2.0

## What is IPTV Detective?

IPTV Detective is a web-based tool for analyzing, comparing, and managing IPTV provider data. It allows users to submit IPTV provider information, compare provider details, and manage submissions through an admin dashboard. The platform is designed for research, moderation, and community-driven IPTV data collection.

### Key Features
- Submit and store IPTV provider details
- Compare providers and group similar entries
- Admin dashboard for reviewing and moderating submissions
- Health and progress monitoring pages
- Secure admin login with CAPTCHA support

## Example image

<img width="1325" height="1072" alt="image" src="https://github.com/user-attachments/assets/c3278a21-9b22-4aa1-b8c2-18fc1238ef0e" />

## Setup Instructions

1. **Clone the repository:**
	```sh
	git clone https://github.com/Nigel1992/IPTV-Detective-2.0.git
	cd IPTV-Detective-2.0
	```

2. **Configure your database and credentials:**
	- Copy `inc/config.php` and fill in your own database host, name, user, and CAPTCHA keys. The provided file is redacted for security.
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

## .gitignore
Sensitive files like `inc/config.php` and logs are excluded from version control.

## License
See LICENSE file if present.
