# OpenFrontMC

OpenFrontMC is an original multiplayer territorial strategy game mode for Minecraft Bedrock servers running PocketMine-MP API 5.

> **Status: early MVP foundation (0.1.0).** Country creation, deterministic territory metadata generation, SQLite persistence, resource balances, match membership/state transitions, managed world loading, and basic block protection are implemented. This is not yet the complete game described in the roadmap.

## Requirements
- PocketMine-MP API 5
- PHP 8.1+
- PHP SQLite3 extension
- Composer for development and analysis

## Installation
1. Build a PHAR with PocketMine DevTools or a compatible build pipeline.
2. Put the PHAR in the server's `plugins/` directory.
3. Start PocketMine-MP and review `plugin_data/OpenFrontMC/config.yml`.
4. Use `/openfront help`.

## Commands
- `/openfront help`
- `/openfront country create <name> <TAG>`
- `/openfront country`
- `/openfront map`
- `/openfront economy`
- `/openfront join`
- `/openfront leave`
- `/openfront stats`
- `/openfront top`
- `/openfront admin status|reload`

## Permissions
- `openfront.command` — player commands (default true)
- `openfront.admin` — admin commands (default op)
- `openfront.admin.build` — build in managed worlds (default op)

## Worlds and persistence
The plugin creates or loads `openfront_lobby` and `openfront_world` through PocketMine's WorldManager. It never changes the server's default world. SQLite uses prepared statements, foreign keys, WAL mode, and transactions. Back up the plugin data directory and both managed world folders before maintenance.

## Development status
The map generator currently creates deterministic territory metadata; automatic terrain rendering is not enabled. Buildings, active research, diplomacy workflows, complete UI, scheduled income, and automated match lifecycle remain roadmap items. See [Architecture](docs/ARCHITECTURE.md), [Player guide](docs/PLAYER_GUIDE.md), [Admin guide](docs/ADMIN_GUIDE.md), [Security](docs/SECURITY.md), and [Testing](docs/TESTING.md).

## License
MIT. See [LICENSE](LICENSE).
