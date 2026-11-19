# Security model

- Database values use SQLite prepared statements.
- Country names, tags, resource names, map dimensions, and match capacity are validated.
- Territory claims check country membership, neutral land, adjacency, and gold inside a transaction.
- Active match membership is unique per player.
- Managed worlds deny normal block placement and breaking.
- No external callbacks, webhooks, credentials, or player secrets are used.

This is an MVP security baseline. A public production server still needs broader event coverage, audit review tooling, persistent rate limiting, and load testing.
