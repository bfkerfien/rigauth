# Belt Ratting — SeAT Character Ratting Tracker

Native SeAT module for belt-ratting operations, available to every character authenticated to the signed-in SeAT user.

- Select any of the user's SeAT-authenticated characters.
- Enforces one active belt-ratting session across all of that user's authenticated characters.
- Session bounty journals, asset snapshots, event records, history, and ship-performance statistics are scoped to the selected character.
- Start/stop sessions in MC6-5J.
- Reads positive bounty journal rows from SeAT character_wallet_journals (bounty_prizes / bounty_prize).
- Captures SeAT character_assets at session start/stop.
- Values assets with Jita region 10000002 market aggregates from Fuzzwork; buy maximum preferred, sell minimum fallback.
- Manual Dread Guristas kill, hauler spawn/kill, and Estamel sighting/kill event recording.
- Dedicated dr_ tables and Guristas dark-green operations console.

MC6-5J is resolved through SeAT solar_systems. Enter the Astrahouse Keepstar structure ID in page settings to include structure assets and nested containers. System assets are included automatically.

ESI journal and asset rows are SeAT's synchronized cache and can lag gameplay. This module does not invent missing data or force ESI refreshes.

Deploy after merging this branch to main: `cd /opt/rigauth && bash ./deploy-divine-reign.sh`

The deployment script fast-forwards main, backs up touched SeAT files, applies the sidebar/routes patch, installs module files, migrates, clears caches, verifies routes, and restarts the front service. All records use the existing SeAT database.
