# Server administrator guide

## Requirements and installation

OpenFrontMC 0.2.0 targets PocketMine-MP API 5 and PHP 8.1+ with SQLite3 enabled. Build a PHAR using PocketMine DevTools or a compatible build pipeline, place it in plugins, and restart the server. Review plugin_data/OpenFrontMC/config.yml, buildings/buildings.yml, and research/technology.yml.

## Managed worlds and backups

The plugin creates or loads openfront_lobby and openfront_world. It does not replace or modify the server default world. The strategy grid is persisted in SQLite; terrain painting is not yet enabled. Back up plugin_data/OpenFrontMC and both managed world folders before maintenance. Stop the server before copying SQLite database and WAL files.

## Economy and game data

Use the config file to tune map seed, territory-grid size, claim cost, starting resources, resource capacity, income interval, periodic income, diplomacy request limits, treaty durations, match capacity, and countdown. Building costs and production live in buildings/buildings.yml; research costs, prerequisites and effects live in research/technology.yml.

A change to map settings affects future map creation only. The current default-map bootstrap does not regenerate an existing map automatically. Do not delete the database to reset a live game without a verified backup.

## Administrative commands

- /openfront admin status — plugin-managed world and current match status
- /openfront admin setup — reports managed worlds loaded
- /openfront admin start — starts the countdown for the current waiting match
- /openfront admin pause — pauses an eligible running match
- /openfront admin resume — resumes a paused match
- /openfront admin stop — finishes the current active match
- /openfront admin reload — reloads the main config; services holding cached config require restart to apply all changes
- /openfront admin debug — reports territory count and map metadata

openfront.admin defaults to operators. Grant it only to trusted server administrators. openfront.admin.build allows block placement/breaking in plugin-managed worlds and should be assigned sparingly.

## Safety and operations

Test configuration changes on a disposable server first. Monitor the server log for scheduled-economy or database errors. Keep PocketMine-MP and PHP up to date. The current release is a development MVP: it has no live terrain renderer, no isolated simultaneous arenas, no full movement/teleport boundary enforcement, and no automatic victory-condition evaluator. Do not advertise it as a finished competitive game mode.
