#!/usr/bin/env bash
# Pull, commit everything as "update" and push to master.
set -euo pipefail
cd "$(dirname "$0")"

git add ./
git commit -m "update"
git push origin master
