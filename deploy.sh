#!/bin/bash
# ==============================================================================
# DEPLOYMENT & INTEGRITY VERIFICATION SCRIPT
# Target: IPTV Detective
# ==============================================================================

# --- CONFIGURATION ---
# Source local .deploy.env if present
if [ -f ".deploy.env" ]; then
    # shellcheck disable=SC1091
    source ".deploy.env"
fi

# Read from env (allow overriding by exported vars)
FTP_USER="${FTP_USER:-}"
FTP_PASS="${FTP_PASS:-}"
FTP_HOST="${FTP_HOST:-}"
REMOTE_PATH="/htdocs/IPTV Detective/"
STAGING_DIR="/tmp/staging_upload_$(date +%s)"

# Fail fast if FTP credentials are missing
if [ -z "$FTP_USER" ] || [ -z "$FTP_PASS" ] || [ -z "$FTP_HOST" ]; then
    echo "[!] FTP credentials not found. Set FTP_USER, FTP_PASS, FTP_HOST. Aborting."
    exit 1
fi

# --- 1. PRE-COMMIT / PRE-UPLOAD SEMGREP CHECK ---
echo "[*] Running pre-upload Semgrep scan (pre-commit)..."
semgrep scan --config="p/secrets" --config="p/security-audit" --config="p/owasp-top-ten" --error . || {
    echo "[!] DEPLOY BLOCKED: Semgrep found security vulnerabilities. Fix before uploading."
    exit 1
}

# --- 2. REMOTE SECURITY AUDIT ---
echo "[*] Auditing Remote Server Security..."
REMOTE_DATA=$(lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "ls -Ra \"$REMOTE_PATH\"; quit")

LEAKY=$(echo "$REMOTE_DATA" | grep "^.rwxrwxrwx" | awk '{print $NF}')
SENSITIVE=$(echo "$REMOTE_DATA" | grep -E ".env|.git|.venv|*.sql|*.log")

if [ ! -z "$LEAKY" ] || [ ! -z "$SENSITIVE" ]; then
    echo "[!] SECURITY ALERT: Sensitive files or 777 perms found on remote!"
    # Allow non-interactive control via env vars:
    # - AUTO_FIX_REMOTE=yes  -> apply fixes automatically
    # - AUTO_FIX_REMOTE=no   -> do not apply fixes (non-interactive)
    # - NONINTERACTIVE=1     -> same as AUTO_FIX_REMOTE=no
    if [ "${AUTO_FIX_REMOTE:-}" = "yes" ]; then
        FIX_CONFIRM="yes"
    elif [ "${AUTO_FIX_REMOTE:-}" = "no" ] || [ "${NONINTERACTIVE:-}" = "1" ]; then
        FIX_CONFIRM="no"
    else
        read -p "[?] Apply security fixes (rm/chmod) to FTP? (yes/no): " FIX_CONFIRM
    fi
    if [ "$FIX_CONFIRM" == "yes" ]; then
        lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" <<EOF
        $(for f in $SENSITIVE; do echo "rm -r \"$REMOTE_PATH/$f\""; done)
        $(for p in $LEAKY; do echo "chmod 644 \"$REMOTE_PATH/$p\""; done)
        quit
EOF
    fi
fi

# --- 3. PRODUCTION FILTRATION & STAGING ---
echo "[*] Creating production-only staging..."
mkdir -p "$STAGING_DIR"
rsync -av --exclude='local test' ./ "$STAGING_DIR" \
    --exclude={'.git/','.venv/','__pycache__/','.env','.deploy.env','*.sql','*.log','*.db','.DS_Store','staging_upload*/','*.sh'}

# Safety check for .env files
if find "$STAGING_DIR" -name ".deploy.env" -print -quit | grep -q .; then
    echo "[!] .deploy.env leaked into staging! Scrubbing and aborting.";
    rm -rf "$STAGING_DIR"
    exit 1
fi

# --- 4. KEY INJECTION ---
echo "[*] Injecting production keys..."
CONF_FILE="$STAGING_DIR/inc/config/config.php"
if [ -f "$CONF_FILE" ]; then
    sed -i "s/__DB_PASS_LOCAL__/PROD_PASS_HERE/g" "$CONF_FILE"
    sed -i "s/__CAPTCHA_SECRET_LOCAL__/PROD_CAPTCHA_HERE/g" "$CONF_FILE"
fi

# --- 5. STAGING SECURITY SCAN ---
echo "[*] Running Final Staging Semgrep Scan..."
semgrep scan --config="p/secrets" --config="p/security-audit" --error "$STAGING_DIR" || {
    echo "[!] DEPLOYMENT BLOCKED: Post-injection security failure.";
    exit 1
}

# --- 6. PHP LINT CHECK ---
echo "[*] Validating PHP syntax..."
# Use NUL-delimited find to safely handle filenames with spaces
while IFS= read -r -d '' f; do
    php -l "$f" > /dev/null || { echo "[!] SYNTAX ERROR IN $f. Aborting."; exit 1; }
done < <(find "$STAGING_DIR" -name "*.php" -print0)

# --- 7. DEPLOYMENT ---
echo "[*] Deploying via lftp (Pass 1)..."
lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" <<EOF
set ftp:ssl-allow no
set xfer:clobber on
mirror -R "$STAGING_DIR" "$REMOTE_PATH" \
  --only-newer --ignore-time --continue --verbose \
  --exclude-glob ".deploy.env" --exclude-glob ".env"
quit
EOF

# --- 8. VERIFICATION (Pass 2) ---
echo "[*] Verifying remote integrity..."
VERIFY_RESULTS=$(lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e "
    set ftp:ssl-allow no;
    mirror -R --dry-run --ignore-time \"$STAGING_DIR\" \"$REMOTE_PATH\"; 
    quit" | grep -v "Total")

# --- 9. FINAL REPORT ---
echo "===================================================="
if [ -z "$VERIFY_RESULTS" ]; then
    echo "[+] DEPLOY SUCCESS: All files verified and matching."
else
    echo "[!] DEPLOY WARNING: The following files failed verification:"
    echo "$VERIFY_RESULTS"
fi
echo "===================================================="

# --- 10. CLEANUP ---
rm -rf "$STAGING_DIR"
echo "[+] Cleanup complete. Process finished."