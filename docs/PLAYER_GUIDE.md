# Player guide

## Getting started

- /openfront help — command list
- /openfront country create <name> <TAG> — create your country; name supports Unicode, tag is 2–4 letters/digits
- /openfront country — show your country
- /openfront country info <TAG> — view another country's public summary
- /openfront economy — view your six resource balances
- /openfront map — display the generated territory grid in chat

## Territories and buildings

Territory coordinates are zero-based grid coordinates, not block positions. Use /openfront claim <x> <z> to claim a neutral land territory. The first claim may be anywhere on land; later claims must touch your existing territory by an edge. Each claim costs the configured gold amount.

Build a logical building in one of your owned territories with /openfront build <type> <x> <z>. Building IDs are returned in chat. Use /openfront upgrade <building-id> or /openfront demolish <building-id> to manage it. Buildings are stored and produce resources, but are not yet rendered as visible block structures.

## Research

- /openfront research list — show technologies and status
- /openfront research unlock <technology-id> — unlock a technology when its prerequisites and resources are available

Research unlocks are instant in this release. Their configured effects improve building production.

## Diplomacy

- /openfront diplomacy list
- /openfront diplomacy offer <TAG> <TRADE|ALLIANCE|NON_AGGRESSION|PEACE>
- /openfront diplomacy accept <treaty-id>
- /openfront diplomacy reject <treaty-id>
- /openfront diplomacy end <treaty-id>

Only the target country can accept or reject a pending offer. Active timed treaties expire automatically. Peace agreements are permanent by default unless configured otherwise.

## Matches

Use /openfront join to join or create the current waiting match, /openfront ready to mark yourself ready, and /openfront leave to return to the lobby. The current build has one shared match world; it does not yet provide multiple isolated arenas, full team assignment, or automatic victory rules. Your match membership is retained if you disconnect and is restored to the match-world spawn when you rejoin.
