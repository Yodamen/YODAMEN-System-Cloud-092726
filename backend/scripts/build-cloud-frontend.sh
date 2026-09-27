#!/usr/bin/env bash

set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJECT_ROOT="$(cd "$BACKEND_DIR/.." && pwd)"
FRONTEND_DIR="$PROJECT_ROOT/frontend"

echo "=== Building YODAMEN React frontend ==="

cd "$FRONTEND_DIR"

npm ci

VITE_API_URL="${VITE_API_URL:-/api}" npm run build

echo "=== Copying frontend build into Laravel public directory ==="

rm -rf "$BACKEND_DIR/public/assets"
rm -f "$BACKEND_DIR/public/index.html"

cp -R "$FRONTEND_DIR/dist/." "$BACKEND_DIR/public/"

echo "=== Frontend cloud build complete ==="

ls -lh "$BACKEND_DIR/public/index.html"
