#!/bin/bash

set -e
set -o pipefail

echo
for f in schemas/*; do
  db=$(basename $f .sql)
  case "$f" in
    *.sql)    echo "$0: running $f"; psql --username "$POSTGRES_USER" -c "CREATE DATABASE $db;"; psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" < "$f"; echo ;;
    *)        echo "$0: ignoring $f" ;;
  esac
  echo
done
