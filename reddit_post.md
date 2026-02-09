## 🚨 JOIN THE COMMUNITY 🚨  
### 👉 **[Join our Discord](https://discord.gg/zxUq3afdn8)** 👈  
**Discuss scans • Report findings • Help improve reseller detection • Contribute ideas**

---

## TL;DR

I built **IPTV Detective** — a **free, open-source** web tool that analyzes IPTV provider packages and helps identify resellers and similar offerings.  
**Xtream credentials (host, optional port, username, password) are used to fetch a provider's playlist for deeper analysis. Credentials are used in your browser and are never stored.** Counts-only/test flows are limited; the primary workflow requires Xtream credentials.

🔓 **Open Source:** https://github.com/Nigel1992/IPTV-Detective-2.0  
🌐 **Live Website:** The link to the running production site is in the **GitHub README**  
⚠️ **Important:** The database starts empty. Detection improves as more providers are scanned by the community.

---

## ⚠️ PRIVACY & SECURITY FIRST

**What this tool DOES:**
- ✅ Analyzes IPTV provider packages and metrics
- ✅ Compares submissions to detect resellers and similar providers
- ✅ Uses in-browser playlist fetching/parsing for privacy

**What this tool DOES NOT do:**
- ❌ NO credentials stored — credentials are used only in-memory by your browser to fetch a playlist
- ❌ NO streaming content accessed
- ❌ NO subscription details captured
- ❌ NO personal data stored

You can provide:
- Xtream fields (host, optional port, username, password) for deeper analysis (credentials never stored)
- Or use test/debug flows for development (limited and not a substitute for normal analysis)

---

## What does it do?

Ever wondered if your IPTV provider is a reseller or the original source?  
This tool analyzes and compares provider packages, metrics, and infrastructure to help you find out:

- Package similarity and reseller detection
- Price comparison between similar providers
- Community-driven database for better results
- In-browser playlist fetching/parsing for privacy

> Note: Detection improves as more providers are scanned and compared.

---

## How to use it

1. Enter Xtream credentials (host, optional port, username, password). The browser fetches the playlist directly and parses it locally.  
2. Click **Check & Compare** to get counts and similarity analysis.  
3. If you prefer not to provide credentials, use the test/debug flows (limited, intended for development only).

---

## Open Source & Transparent

Full source code:  
https://github.com/Nigel1992/IPTV-Detective-2.0

You can:
- Review all code
- Self-host the platform
- Verify no credential collection
- Contribute improvements

**Tech stack:**
- PHP + MySQL backend
- Bootstrap 5 frontend
- In-browser playlist parsing for privacy
- Cloudflare Turnstile CAPTCHA for security

---

## Optional Enhancement Features

The platform works out of the box, but you may optionally add your own API keys if self-hosting.

---

## Key Features

### Community-Driven Reseller & Similarity Detection

- Detects resellers by comparing package metrics and submissions
- Finds similar providers and price differences
- Early scans may show **"Not enough data yet"** until the dataset grows

---

### Similarity Scoring (0–100)

Scoring is based on:
- Package metrics
- Channel and VOD counts
- Community submissions

Higher score = more similar

---

## Privacy Guarantees

- Credentials are used in-memory for fetches and are not stored
- No stream access
- Only package metrics and summary data uploaded
- Optional provider name input
- No tracking, cookies, or analytics

Stored per scan:
- Provider summary metrics
- Optional provider name

No credentials. No playlists. No personal data.

---

## 💬 Join the Discussion & Help Improve Detection
### 🔗 **[Join our Discord community](https://discord.gg/zxUq3afdn8)**

- Share scan results  
- Compare providers  
- Suggest features  
- Help grow the detection database  

**The more people scan, the smarter the tool gets.**