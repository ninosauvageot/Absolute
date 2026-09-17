#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
# A separate build image leaves the existing chat runtime unchanged.
docker run --rm --user "$(id -u):$(id -g)" \
  -e npm_config_cache=/work/frontend/node_modules/.cache \
  --mount "type=bind,src=$(pwd),dst=/work" -w /work/frontend \
  node:22-bookworm-slim sh -c 'npm ci && npm run build'
