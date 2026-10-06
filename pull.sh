#!/usr/bin/env bash
# Pull the latest master.
set -euo pipefail
cd "$(dirname "$0")"

git pull origin master
