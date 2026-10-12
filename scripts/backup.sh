#!/bin/sh
# Super Wheels and Tyres – nightly backup for DirectAdmin hosting.
#
# Usage: backup.sh <database_name> <path_to_swt-app>
# Needs a .my.cnf (chmod 600) next to this script with [client] user/password/host.
# Writes to the folder this script lives in (keep it OUTSIDE public_html).

set -eu

DB="${1:?database name required}"
APP="${2:?path to the swt-app folder required}"
DIR="$(cd "$(dirname "$0")" && pwd)"
KEEP_DAYS="${KEEP_DAYS:-14}"
KEEP_WEEKS="${KEEP_WEEKS:-8}"
STAMP="$(date +%Y-%m-%d_%H%M)"

umask 077
mkdir -p "$DIR/db" "$DIR/files"

# 1. Consistent database dump (InnoDB, no table locks while the shop keeps trading)
mysqldump --defaults-extra-file="$DIR/.my.cnf" --single-transaction --routines --triggers --no-tablespaces \
    "$DB" | gzip -9 > "$DIR/db/$DB-$STAMP.sql.gz"
echo "$(date '+%F %T') database dump OK: $DIR/db/$DB-$STAMP.sql.gz"

# 2. Weekly archive of uploaded files and .env (Sundays)
if [ "$(date +%u)" = "7" ]; then
    tar -czf "$DIR/files/files-$STAMP.tar.gz" -C "$APP" storage/app/private .env
    echo "$(date '+%F %T') file archive OK: $DIR/files/files-$STAMP.tar.gz"
fi

# 3. Retention
find "$DIR/db" -name "*.sql.gz" -type f -mtime +"$KEEP_DAYS" -delete
find "$DIR/files" -name "*.tar.gz" -type f -mtime +"$((KEEP_WEEKS * 7))" -delete
