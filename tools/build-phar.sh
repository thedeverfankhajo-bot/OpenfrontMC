#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "\${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT="\${1:-$ROOT_DIR/dist/OpenFrontMC.phar}"
OUTPUT_DIR="$(dirname "$OUTPUT")"
mkdir -p "$OUTPUT_DIR"
OUTPUT="$(cd "$OUTPUT_DIR" && pwd)/$(basename "$OUTPUT")"

if ! php -r 'exit(extension_loaded("yaml") && class_exists("Phar") ? 0 : 1);'; then
  echo "PHP extensions yaml and phar are required. Install PHP YAML support first." >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "Downloading the official PocketMine DevTools source..."
curl --fail --location --retry 3 --silent --show-error \
  https://github.com/pmmp/DevTools/archive/refs/heads/stable.tar.gz \
  --output "$TMP_DIR/devtools.tar.gz"
tar -xzf "$TMP_DIR/devtools.tar.gz" -C "$TMP_DIR"

DEVTOOLS_DIR="$TMP_DIR/DevTools-stable"
STAGING_DIR="$TMP_DIR/OpenFrontMC"
mkdir -p "$STAGING_DIR"
cp plugin.yml "$STAGING_DIR/plugin.yml"
cp -a src "$STAGING_DIR/src"
cp -a resources "$STAGING_DIR/resources"

echo "Building OpenFrontMC.phar with PocketMine DevTools..."
php -dphar.readonly=0 "$DEVTOOLS_DIR/src/ConsoleScript.php" \
  --make "plugin.yml,src,resources" \
  --relative "$STAGING_DIR" \
  --out "$OUTPUT"

echo "Checking PHAR contents..."
php -r '
$phar = new Phar($argv[1]);
$required = ["plugin.yml", "src/OpenFrontMC/Main.php", "resources/config.yml"];
foreach($required as $path){
    if(!isset($phar[$path])){
        fwrite(STDERR, "Missing PHAR entry: " . $path . PHP_EOL);
        exit(1);
    }
}
echo "Valid PHAR: " . $argv[1] . PHP_EOL;
' "$OUTPUT"

echo "Built: $OUTPUT"
