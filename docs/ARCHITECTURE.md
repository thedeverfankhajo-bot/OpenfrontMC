# Architecture

- `Main`: PocketMine plugin lifecycle and command entry point.
- `Core/Bootstrap`: dependency construction and startup/shutdown.
- `World`: managed world discovery/loading and deterministic map metadata generator.
- `Country`, `Territory`, `Economy`, `Match`: game domain services.
- `Storage/Database`: SQLite schema, prepared statements, and transaction boundary.
- `Listener`: server-authoritative block protection.
- `Security`: input validation and rate limiting primitive.

This is an MVP foundation. Buildings, technology unlocks, diplomacy workflows, countdown orchestration, and map block rendering remain incomplete and are not advertised as playable.
