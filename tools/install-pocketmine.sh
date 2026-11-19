#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$(uname -s)" != "Linux" && "$(uname -s)" != "Darwin" ]]; then
  echo "The official PocketMine installer supports Linux and macOS only." >&2
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required." >&2
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "\${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_DIR="\${1:-$ROOT_DIR/.runtime/pocketmine}"
mkdir -p "$INSTALL_DIR"
INSTALL_DIR="$(cd "$INSTALL_DIR" && pwd)"

if [[ -f "$INSTALL_DIR/PocketMine-MP.phar" && -f "$INSTALL_DIR/start.sh" ]]; then
  echo "PocketMine-MP is already installed at: $INSTALL_DIR"
  echo "Remove that folder first to install a clean copy."
  exit 0
fi

echo "Installing the latest stable PocketMine-MP 5.x release..."
(
  cd "$INSTALL_DIR"
  curl --fail --location --silent --show-error https://get.pmmp.io | bash -s - -v 5
)

test -s "$INSTALL_DIR/PocketMine-MP.phar"
test -s "$INSTALL_DIR/start.sh"
test -x "$INSTALL_DIR/bin/php7/bin/php"

echo "PocketMine-MP API 5 runtime installed at: $INSTALL_DIR"
"$INSTALL_DIR/bin/php7/bin/php" -v | head -n 1
