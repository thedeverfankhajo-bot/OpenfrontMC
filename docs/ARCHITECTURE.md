# Architecture

OpenFrontMC is a modular PocketMine-MP API 5 plugin. The command/UI layer delegates game rules to domain services; the SQLite layer persists server-authoritative state.

## Runtime modules

- Main: validates required PHP extensions and owns plugin lifecycle and command dispatch.
- Core/Bootstrap: loads resource configuration, builds services, registers listeners, schedules periodic game ticks, and closes persistence.
- World: managed lobby/match world discovery and loading plus deterministic territory metadata generation.
- Country: country creation and lookup, leader membership, and ranking query.
- Territory: map metadata, terrain categories, server-side bounds/adjacency/ownership checks, score updates, statistics, and history.
- Economy: resource normalization, capacity checks, atomic debit + domain mutation callbacks, income, and transaction ledger.
- Building: database-backed building records, location ownership, limits, levels, upgrade, demolition, and production calculations.
- Research: validated acyclic technology tree, prerequisites, resource costs, unlock persistence, and production modifiers.
- Diplomacy: treaty offer/respond/end/expiry workflow with per-country request throttling and history.
- Match: persisted state machine, capacity, one-active-match-per-player rule, ready flags, same-match rejoin, atomic result persistence, and configurable victory evaluation.
- Language: English/Persian YAML localization.
- Security: input validation and action rate limiter.
- Storage: SQLite schema and transaction boundary.

## Data and thread ownership

World and player operations remain on PocketMine main thread. Periodic game economy and diplomacy service work runs via ClosureTask; it performs database operations and never accesses worlds from worker threads. SQLite calls use prepared statements. Resource debit and related domain mutations share an explicit transaction.

## World and map model

The plugin loads or creates the managed worlds openfront_lobby and openfront_world using PocketMine public WorldManager API and WorldCreationOptions. The map generator emits deterministic territory metadata keyed by the configured seed. Default territory grid is 16×16 with 16 blocks per territory, describing a 256×256 strategic map. The territory board is rendered incrementally in the managed match world through public chunk-population and block-placement APIs; logical building records are not yet visualized as block structures.

## Security boundaries

Commands check their dedicated permissions; admin actions additionally require openfront.admin. Country identity and membership, map bounds, claim adjacency, terrain, and resource balances are looked up server-side. Players cannot provide country IDs or resource balances to change ownership. Block placement and breaking are cancelled in managed worlds except for actors with openfront.admin.build. This listener is a basic build-protection layer, not a comprehensive anti-cheat or movement boundary. Victory rules run on a scheduled task and use shared campaign country/territory/economy values; per-match state isolation is not yet implemented.

## Current extension points

Add new game rules as domain services and keep PocketMine event adapters thin. New buildings and technologies should be data-defined. Any world mutation must use documented API 5 methods on the main thread and be scheduled in bounded batches. Schema changes should use explicit versioned migrations.
