# OpenFrontMC

OpenFrontMC is an original multiplayer territorial strategy game mode for Minecraft Bedrock servers running PocketMine-MP API 5. It uses abstract strategy-game rules, server-authoritative decisions, and persistent SQLite state.

> **Release status: development MVP (0.2.0), not production-certified.** This release adds playable country setup, deterministic territory metadata, server-side territory claiming, resource transactions, building economy, instant technology unlocks, diplomacy agreements, repeating income/production, match membership and state transitions, and bilingual chat commands. It is not yet the full multi-arena strategy game in the long-term specification.

## Requirements

- PocketMine-MP API 5 (the manifest declares API 5.0.0)
- PHP 8.1 or newer
- PHP SQLite3 extension
- Composer for development, tests, and static analysis

The plugin does not use PocketMine-MP API 4 or API 6 interfaces. It must be tested against the exact PocketMine build used by a server before public deployment.

## Installation

1. Build a PHAR using PocketMine DevTools or a compatible build pipeline.
2. Place the PHAR in the server plugins directory.
3. Start PocketMine-MP and review plugin_data/OpenFrontMC/config.yml.
4. Review the copied buildings/buildings.yml, research/technology.yml, and language files.
5. Use /openfront help.

The plugin creates or loads the managed worlds openfront_lobby and openfront_world; it does not replace or modify the server default world. The current procedural generator persists a deterministic 16×16 territory grid by default. Each territory represents a 16×16 block region, corresponding to 256×256 blocks of strategic map space. **The current build stores and displays strategic map metadata; it does not yet render that grid as a fully generated physical terrain map or place building structures as Minecraft blocks.**

## Commands

- /openfront help
- /openfront country create <name> <TAG>
- /openfront country
- /openfront country info <TAG>
- /openfront claim <x> <z>
- /openfront map
- /openfront economy
- /openfront build <type> <territory-x> <territory-z>
- /openfront upgrade <building-id>
- /openfront demolish <building-id>
- /openfront research list
- /openfront research unlock <technology-id>
- /openfront diplomacy list
- /openfront diplomacy offer <TAG> <ALLIANCE|TRADE|NON_AGGRESSION|PEACE>
- /openfront diplomacy accept|reject|end <treaty-id>
- /openfront join, /openfront leave, /openfront ready
- /openfront stats, /openfront top
- /openfront admin status|setup|start|pause|resume|stop|reload|debug

Territory coordinates are zero-based territory-grid coordinates, not block coordinates. A country may claim neutral land and subsequent claims must be orthogonally adjacent to owned territory. Buildings are logical game records linked to a claimed territory.

## Permissions

- openfront.command — player command entry point (default true)
- openfront.country — country commands (default true)
- openfront.territory — claim territory (default true)
- openfront.economy — view resource balances (default true)
- openfront.building — building commands (default true)
- openfront.research — technology commands (default true)
- openfront.diplomacy — diplomacy commands (default true)
- openfront.admin — administrative commands (default op)
- openfront.admin.build — break/place blocks in managed worlds (default op)

## Gameplay systems in 0.2.0

### Countries and territories

Countries are created by players, receive configured starting resources, and are stored with their leader/member record. Territory ownership is determined on the server and persisted with a history event and country score update. Claiming checks country membership, map bounds, terrain type, prior ownership, adjacency, and sufficient gold in one SQLite transaction.

### Economy

Countries own six resources: gold, food, materials, energy, research, and influence. Resource changes are normalized, capacity-limited, checked against negative balances, and logged to economy_transactions. Periodic configured income and building production are applied by a PocketMine scheduler task on the main thread.

### Buildings

Building definitions are loaded from resources/buildings/buildings.yml. The current service supports placement on owned land, per-country/per-territory limits, upgrade costs, levels, controlled demolition, production, and transaction rollback. Buildings are logical records, not yet a visual block structure.

### Research

Technology definitions are loaded from resources/research/technology.yml. The technology tree validates IDs, prerequisites, and cycles. Unlocks enforce prerequisites, debit resources atomically, record history, and apply configured production multipliers to buildings. Unlocks are immediate; research timers and progress queues are not implemented yet.

### Diplomacy

Alliance, trade, non-aggression, and peace requests can be offered to another country by tag, accepted/rejected by the recipient, ended by either participant, and expired by scheduled service processing. Requests have a configurable rate limit and expiry; time-limited active treaties use configured durations.

### Matches

The match service supports FFA, team, solo, and custom mode identifiers; capacity checks; unique active player membership; ready state; and guarded WAITING/COUNTDOWN/RUNNING/PAUSED/FINISHED transitions. The command layer currently operates a shared managed match world and provides an admin countdown. Independent simultaneous world instances, teams assignment, complete win-condition evaluation, automatic winner selection, and a full match reset flow remain incomplete.

### User interface

This release uses localized chat commands and textual map output. Inventory forms, action bars, interactive map overlays, and the full Bedrock menu suite are not yet implemented.

## Configuration

Edit plugin_data/OpenFrontMC/config.yml. Important settings include map seed and dimensions, territory size, claim cost, economy capacity, periodic income interval, starting resources, diplomacy request limits and treaty duration, match capacity, countdown length, and language (en_US or fa_IR). Economic capacity, starting balances, periodic income, and income interval are configured in plugin_data/OpenFrontMC/economy.yml. Building and research settings are configured in their respective resource YAML files.

Changing map generation settings does not overwrite an existing persisted map. Back up the database before deliberately regenerating or migrating map data. No automatic destructive map reset is exposed to players.

## Persistence and backups

SQLite uses prepared statements, foreign keys, WAL mode, busy timeout, and explicit transactions. Back up the entire plugin_data/OpenFrontMC folder and both managed world folders before upgrades or maintenance. Stop the server before copying a SQLite database together with its WAL files.

## Development and tests

Run Composer validation, install dev tools, then run the project test and analysis scripts. PHP syntax can be checked using PHP lint on all PHP files in src and tests.

The unit suite includes a SQLite-backed service integration test. Tests against a live PocketMine-MP server, Bedrock client behavior, chunk generation, and restart durability must be run on a real API 5 server; they are not claimed as passed by the repository CI unit suite.

## Known limitations

This is a development MVP, not a production-ready public-server release. It does not yet include complete membership invitations/roles, country leadership transfer, real terrain rendering, physical block buildings, trade settlement, research timers, multiple isolated matches, full victory condition evaluation, player reconnection restoration, inventory-form UI, complete teleport/arena boundary enforcement, or a PocketMine server integration test harness. Admin controls are intentionally narrow. Deploy to a test server first, review permissions, and keep verified backups.

## Documentation

- Architecture: docs/ARCHITECTURE.md
- Player guide: docs/PLAYER_GUIDE.md
- Server administrator guide: docs/ADMIN_GUIDE.md
- Security notes: docs/SECURITY.md
- Testing guide: docs/TESTING.md

## Bug reports

Open a GitHub issue with the PocketMine-MP build, PHP version, relevant OpenFrontMC log excerpt, exact command or reproduction steps, and whether the issue persists after a clean test-world restart. Never include server credentials, player authentication data, or private database files.

## License

MIT. See LICENSE.
