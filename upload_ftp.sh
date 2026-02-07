#!/bin/bash
source ftp_credentials.local
lftp -u $FTP_USERNAME,$FTP_PASSWORD $FTP_HOST << EOF2
mirror -R . "$FTP_DIR"
quit
EOF2
