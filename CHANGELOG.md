
# Changelog

This changelog is written for everyone, not just developers. It explains what changed in simple terms.


## 2026-02-10 — v2.1.2
- Added a warning next to the captcha on the main form, telling users to refresh the page if the captcha fails or expires to avoid issues.

## 2026-02-11 — v2.1.3
- Improved provider submission reliability: automatic retries when a submission returns empty or placeholder results.
- Added a homepage Discord button — clickable, accessible, and styled to be subtle and non-intrusive.
- UI polish and small layout tweaks for better mobile/desktop experience.
- Deployment and security fixes: cleaned server JSON output and improved deployment reliability.
- Minor bug fixes and clearer help text.

## 2026-02-08 — v2.1.1
- Added a security check (CAPTCHA) to the homepage and admin login to help block bots.
- Changed the layout so the CAPTCHA fits better on the homepage.
- Improved login and provider submission security with server checks.
- You can no longer upload M3U files; now you just enter channel counts for comparisons.
- The site form and scripts were updated to only accept channel counts, not full playlists.
- Fixed a bug in provider count checking and made loading settings more reliable.
- Updated the help text to make it clearer how the site works and what info is private.
- Made sensitive files safer by changing file permissions and adding protection rules.
- Improved some button text and loading messages to make things clearer.
- Uploaded these updates to the live site.

If you need a more technical changelog, just ask!
