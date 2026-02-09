#!/bin/bash
# Example deploy script (safe-to-commit). Do NOT commit real credentials.
# This demonstrates the secure deployment flow used by deploy.sh

# Source local .deploy.env (if present) or use exported env vars
if [ -f ".deploy.env" ]; then
  # shellcheck disable=SC1091
  source ".deploy.env"
fi

: ${FTP_USER:?"FTP_USER not set"}
: ${FTP_PASS:?"FTP_PASS not set"}
: ${FTP_HOST:?"FTP_HOST not set"}

REMOTE_PATH="/htdocs/IPTV Detective/"
STAGING_DIR="/tmp/staging_upload_$(date +%s)"

# Stage with exclusions (ensures .deploy.env and .env are not copied)
mkdir -p "$STAGING_DIR"
rsync -av --progress ./ "$STAGING_DIR" \
    --exclude={'.git/','.venv/','__pycache__/','.env','.deploy.env','*.sql','*.log','*.db','.DS_Store','staging_upload*/','*.sh'}

# Remove any stray .deploy.env from staging
rm -f "$STAGING_DIR/.deploy.env"

# Run basic checks (semgrep/php lint omitted here for brevity)

# Upload using lftp mirror with explicit excludes as a last line of defense
lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" <<EOF
set ftp:ssl-allow no
set xfer:clobber on
mirror -R "$STAGING_DIR" "$REMOTE_PATH" \
  --checksum --verify --only-newer --continue --verbose \
  --exclude-glob ".deploy.env" --exclude-glob ".env" --exclude-glob "*.env" --exclude-glob ".git*"
quit
EOF

echo "Deployed (example). Replace values in .deploy.env locally and run your local deploy.sh."