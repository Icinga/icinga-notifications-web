#!/bin/bash

set -e
set -o pipefail

apt update
apt install -y wget

wget -O schemas/icingaweb.sql https://github.com/Icinga/icingaweb2/blob/main/schema/pgsql.schema.sql
