#!/bin/bash
# Upload workspace to FTP, excluding local credentials and VCS metadata
source ftp_credentials.local
# Exclude .git, ftp creds, and common local files that shouldn't be published
# Exclude .git, ftp creds, and other local files that shouldn't be published
EXCLUDES=("--exclude-glob .git" "--exclude-glob .git/*" "--exclude-glob ftp_credentials.local" "--exclude-glob .github" "--exclude-glob .gitignore" "--exclude-glob iptv-detective-backup.bundle" "--exclude-glob .venv" "--exclude-glob '*.pyc'")

# Mirror options: continue partial uploads, avoid symlink operations and permissions changes, use small parallelism
MIRROR_OPTS=("--continue" "--no-symlinks" "--no-perms" "--parallel=2" "--verbose")

lftp -u "$FTP_USERNAME","$FTP_PASSWORD" "$FTP_HOST" << EOF2
mirror -R ${MIRROR_OPTS[*]} . "$FTP_DIR" ${EXCLUDES[*]}
quit
EOF2
