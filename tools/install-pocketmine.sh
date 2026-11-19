#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$(uname -s)" != "Linux" && "$(uname -s)" != "Darwin" ]]; then
  echo "The official Axolotl-PM installer supports Linux and macOS only." >&2
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required." >&2
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_DIR="${1:-$ROOT_DIR/.runtime/pocketmine}"
mkdir -p "$INSTALL_DIR"
INSTALL_DIR="$(cd "$INSTALL_DIR" && pwd)"

if [[ -f "$INSTALL_DIR/PocketMine-MP.phar" && -f "$INSTALL_DIR/start.sh" ]]; then
  echo "A PocketMine-compatible server is already installed at: $INSTALL_DIR"
  echo "Remove that folder first to install a clean copy."
  exit 0
fi

echo "Installing the latest stable Axolotl-PM (PocketMine API 5-compatible)..."
(
  cd "$INSTALL_DIR"
  curl --fail --location --silent --show-error https://get.axolotl-pm.org | bash -s -
)

test -s "$INSTALL_DIR/PocketMine-MP.phar"
test -s "$INSTALL_DIR/start.sh"

PHP_BIN="$(find "$INSTALL_DIR/bin" -type f -name php -perm -u+x -print -quit 2>/dev/null || true)"
if [[ -z "$PHP_BIN" ]]; then
  PHP_BIN="$(find "$INSTALL_DIR/bin" -type l -name php -print -quit 2>/dev/null || true)"
fi
if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
  echo "Axolotl-PM PHP runtime was not found under $INSTALL_DIR/bin." >&2
  exit 1
fi

echo "Axolotl-PM installed at: $INSTALL_DIR"
echo "Bundled PHP runtime: $PHP_BIN"
"$PHP_BIN" -v | head -n 1
