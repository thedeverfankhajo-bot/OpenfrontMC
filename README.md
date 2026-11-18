# OpenFrontMC

OpenFrontMC is an original multiplayer territorial strategy game mode for Minecraft Bedrock servers running PocketMine-MP API 5.

> **Development status:** initial implementation is being established. Features are documented as available only when they are implemented and tested.

## Goals

- Dedicated, plugin-managed lobby and match worlds.
- Seeded procedural maps represented by server-authoritative territories.
- Persistent countries, economy, buildings, research, diplomacy, matches, and statistics.
- Secure commands and event-driven gameplay with API 5 only.
- English and Persian localization.

## Requirements

- PocketMine-MP with API 5
- PHP version required by the installed PocketMine-MP release
- PHP SQLite3 support

## Installation

1. Download the release PHAR (when published).
2. Place it in the server's `plugins/` directory.
3. Start PocketMine-MP and review `plugin_data/OpenFrontMC/config.yml`.
4. Use `/openfront help` in game.

## Documentation

- [Player guide](docs/PLAYER_GUIDE.md)
- [Server administrator guide](docs/ADMIN_GUIDE.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Security model](docs/SECURITY.md)
- [Testing](docs/TESTING.md)

## License

MIT. See [LICENSE](LICENSE).

## Compatibility

The plugin manifest targets PocketMine-MP API 5. Compatibility is determined by the APIs actually used and the automated checks; the API declaration alone is not a compatibility guarantee.

## Bug reports

Please include the OpenFrontMC version, PocketMine-MP version, PHP version, relevant sanitized logs, and concise reproduction steps. Never publish access tokens, passwords, player credentials, or private server data.
