#!/bin/bash
# Upload workspace to FTP, excluding local credentials and VCS metadata
source ftp_credentials.local
# Exclude .git, ftp creds, and common local files that shouldn't be published
EXCLUDES=("--exclude .git" "--exclude .git/*" "--exclude ftp_credentials.local" "--exclude .github" "--exclude .gitignore")

lftp -u "$FTP_USERNAME","$FTP_PASSWORD" "$FTP_HOST" << EOF2
mirror -R . "$FTP_DIR" ${EXCLUDES[*]}
quit
EOF2
