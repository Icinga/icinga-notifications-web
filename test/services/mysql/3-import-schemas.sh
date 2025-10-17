#!/bin/bash

set -e
set -o pipefail

echo
for f in schemas/*; do
  db=$(basename $f .sql)
  case "$f" in
    *.sql)    echo "$0: running $f"; "${mysql[@]}" -c "CREATE DATABASE $db;"; "${mysql[@]}" "$db" < "$f"; echo ;;
    *)        echo "$0: ignoring $f" ;;
  esac
  echo
done
