#!/usr/bin/env bash
set -Eeuo pipefail

PACK="${1:?Usage: bash tools/install-world-pack.sh <OpenFrontMC-WorldPack.zip> <pocketmine-server-dir>}"
SERVER_DIR="${2:?Usage: bash tools/install-world-pack.sh <OpenFrontMC-WorldPack.zip> <pocketmine-server-dir>}"

PACK="$(cd "$(dirname "$PACK")" && pwd)/$(basename "$PACK")"
SERVER_DIR="$(cd "$SERVER_DIR" && pwd)"

if [[ ! -s "$PACK" ]]; then
  echo "World pack not found: $PACK" >&2
  exit 1
fi
if [[ ! -s "$SERVER_DIR/PocketMine-MP.phar" ]]; then
  echo "Target directory is not a PocketMine-MP server: $SERVER_DIR" >&2
  exit 1
fi
if [[ -e "$SERVER_DIR/worlds/openfront_world" || -e "$SERVER_DIR/worlds/openfront_lobby" || -e "$SERVER_DIR/plugin_data/OpenFrontMC" ]]; then
  echo "OpenFrontMC world or plugin data already exists. Back it up and remove it before installing this pack." >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
unzip -q "$PACK" -d "$TMP_DIR"

for required in \
  "worlds/openfront_lobby/level.dat" \
  "worlds/openfront_lobby/db" \
  "worlds/openfront_world/level.dat" \
  "worlds/openfront_world/db" \
  "plugin_data/OpenFrontMC/openfront.sqlite3"; do
  if [[ ! -e "$TMP_DIR/$required" ]]; then
    echo "The archive is missing required entry: $required" >&2
    exit 1
  fi
done

mkdir -p "$SERVER_DIR/worlds" "$SERVER_DIR/plugin_data"
cp -a "$TMP_DIR/worlds/openfront_lobby" "$SERVER_DIR/worlds/"
cp -a "$TMP_DIR/worlds/openfront_world" "$SERVER_DIR/worlds/"
cp -a "$TMP_DIR/plugin_data/OpenFrontMC" "$SERVER_DIR/plugin_data/"

echo "OpenFrontMC LevelDB worlds and matching game database installed."
echo "Make sure plugins/OpenFrontMC.phar is present, then start PocketMine-MP."
