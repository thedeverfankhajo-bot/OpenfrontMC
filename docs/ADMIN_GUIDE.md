# Server administrator guide

## Requirements
PocketMine-MP API 5, PHP 8.1+, and the SQLite3 extension.

## Installation
1. Build a PHAR using PocketMine DevTools or a compatible build pipeline.
2. Place it in `plugins/`.
3. Restart the server and inspect the OpenFrontMC log.
4. Configure `plugin_data/OpenFrontMC/config.yml`.

## Worlds and backups
The plugin manages `openfront_lobby` and `openfront_world`. It does not alter the default world. Back up the plugin data directory and both managed world folders before maintenance.

## Permissions
- `openfront.command`: player commands (default true)
- `openfront.admin`: administrative commands (default op)
- `openfront.admin.build`: building in managed worlds (default op)

The current build blocks ordinary block placement and breaking in managed worlds. This is an initial protection layer, not a complete anti-cheat system.
