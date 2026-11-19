#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER_DIR="${1:?Usage: bash tools/build-world-pack.sh <pocketmine-server-dir> [output-dir]}"
OUTPUT_DIR="${2:-$ROOT_DIR/dist}"
SERVER_DIR="$(cd "$SERVER_DIR" && pwd)"
mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd)"

PLUGIN_PHAR="$ROOT_DIR/dist/OpenFrontMC.phar"
if [[ ! -s "$PLUGIN_PHAR" ]]; then
  echo "Missing $PLUGIN_PHAR. Run tools/build-phar.sh first." >&2
  exit 1
fi
if [[ ! -s "$SERVER_DIR/PocketMine-MP.phar" || ! -s "$SERVER_DIR/start.sh" ]]; then
  echo "PocketMine-MP is not installed in: $SERVER_DIR" >&2
  exit 1
fi
PHP_BIN="$(find "$SERVER_DIR/bin" -type f -name php -perm -u+x -print -quit 2>/dev/null || true)"
if [[ -z "$PHP_BIN" ]]; then
  PHP_BIN="$(find "$SERVER_DIR/bin" -type l -name php -print -quit 2>/dev/null || true)"
fi
if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
  echo "The bundled Axolotl-PM PHP runtime is missing." >&2
  exit 1
fi
for command_name in zip; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "$command_name is required." >&2
    exit 1
  fi
done

mkdir -p "$SERVER_DIR/plugins"
cp "$PLUGIN_PHAR" "$SERVER_DIR/plugins/OpenFrontMC.phar"

if [[ ! -f "$SERVER_DIR/server.properties" ]]; then
  cat > "$SERVER_DIR/server.properties" <<'PROPERTIES'
server-name=OpenFrontMC World Builder
server-port=19132
server-portv6=19133
gamemode=survival
difficulty=normal
max-players=1
white-list=off
spawn-protection=0
level-name=world
view-distance=4
auto-save=true
enable-query=false
enable-rcon=false
PROPERTIES
fi

FIFO="$SERVER_DIR/.openfront-console"
LOG_FILE="$OUTPUT_DIR/world-builder.log"
rm -f "$FIFO"
mkfifo "$FIFO"

SERVER_PID=""
cleanup(){
  set +e
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    printf 'stop\n' >&9 2>/dev/null
    wait "$SERVER_PID"
  fi
  exec 9>&-
  rm -f "$FIFO"
}
trap cleanup EXIT

echo "Starting PocketMine-MP API 5 and waiting for map rendering to finish..."
(
  cd "$SERVER_DIR"
  bash ./start.sh
) < "$FIFO" > "$LOG_FILE" 2>&1 &
SERVER_PID=$!
exec 9>"$FIFO"

READY=0
for ((attempt=0; attempt<900; attempt++)); do
  if grep -Fq "OpenFrontMC terrain rendering completed" "$LOG_FILE"; then
    READY=1
    break
  fi
  if grep -Fq "OpenFrontMC could not start" "$LOG_FILE"; then
    break
  fi
  if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    break
  fi
  sleep 2
done

if [[ "$READY" -ne 1 ]]; then
  echo "The world was not fully rendered. PocketMine log follows:" >&2
  tail -n 120 "$LOG_FILE" >&2 || true
  exit 1
fi

echo "World rendering completed. Stopping the server cleanly..."
printf 'stop\n' >&9
set +e
wait "$SERVER_PID"
SERVER_STATUS=$?
set -e
SERVER_PID=""
exec 9>&-
rm -f "$FIFO"
trap - EXIT

if [[ "$SERVER_STATUS" -ne 0 ]]; then
  echo "PocketMine-MP exited with status $SERVER_STATUS." >&2
  tail -n 120 "$LOG_FILE" >&2 || true
  exit "$SERVER_STATUS"
fi

for world in openfront_lobby openfront_world; do
  if [[ ! -s "$SERVER_DIR/worlds/$world/level.dat" || ! -d "$SERVER_DIR/worlds/$world/db" ]]; then
    echo "Expected Bedrock LevelDB world is incomplete: $world" >&2
    exit 1
  fi
done

DB_FILE="$SERVER_DIR/plugin_data/OpenFrontMC/openfront.sqlite3"
if [[ ! -s "$DB_FILE" ]]; then
  echo "OpenFrontMC game database was not created." >&2
  exit 1
fi

"$PHP_BIN" -r '
$db = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
$integrity = $db->querySingle("PRAGMA integrity_check");
$rendered = (int) $db->querySingle("SELECT COUNT(*) FROM rendered_territories");
$territories = (int) $db->querySingle("SELECT COUNT(*) FROM territories");
$db->close();
if($integrity !== "ok" || $territories !== 256 || $rendered !== $territories){
    fwrite(STDERR, "World database check failed: integrity=" . $integrity . ", rendered=" . $rendered . "/" . $territories . PHP_EOL);
    exit(1);
}
echo "Verified SQLite database; rendered territories: " . $rendered . PHP_EOL;
' "$DB_FILE"

STAGING_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGING_DIR"' EXIT
mkdir -p "$STAGING_DIR/worlds" "$STAGING_DIR/plugin_data"
cp -a "$SERVER_DIR/worlds/openfront_lobby" "$STAGING_DIR/worlds/"
cp -a "$SERVER_DIR/worlds/openfront_world" "$STAGING_DIR/worlds/"
cp -a "$SERVER_DIR/plugin_data/OpenFrontMC" "$STAGING_DIR/plugin_data/"

cat > "$STAGING_DIR/WORLD-PACK.txt" <<'INFO'
OpenFrontMC prepared world pack
================================

World storage: Minecraft Bedrock LevelDB (PocketMine-MP world format)
PocketMine target: API 5.x
World folders:
  worlds/openfront_lobby
  worlds/openfront_world

The OpenFrontMC plugin data directory is included to keep the generated
territory map and the completed-render marker in sync with the world files.
Install OpenFrontMC.phar into the server plugins/ directory before starting.

Restore this archive into the root of a fresh PocketMine-MP server directory.
Do not extract over an existing OpenFrontMC world or plugin_data directory.
INFO

ARCHIVE="$OUTPUT_DIR/OpenFrontMC-WorldPack.zip"
rm -f "$ARCHIVE"
(
  cd "$STAGING_DIR"
  zip -q -r "$ARCHIVE" worlds plugin_data WORLD-PACK.txt
)

echo "Created: $ARCHIVE"
