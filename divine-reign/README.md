# Divine Reign — Guristas Ratting & Finance

Native SeAT module for the single character DivineReign DivineSpirit.

- Start/stop sessions in MC6-5J.
- Reads positive bounty journal rows from SeAT character_wallet_journals (bounty_prizes / bounty_prize).
- Captures SeAT character_assets at session start/stop.
- Values assets with Jita region 10000002 market aggregates from Fuzzwork; buy maximum preferred, sell minimum fallback.
- Manual Dread Guristas kill, hauler spawn/kill, and Estamel sighting/kill buttons.
- Dedicated dr_ tables and Guristas dark-green operations console.

MC6-5J is resolved through SeAT solar_systems. Enter the Astrahouse Keepstar structure ID in the page settings to include structure assets and nested containers. System assets are included automatically.

ESI journal and asset rows are SeAT's synchronized cache and can lag gameplay. This module does not invent missing data or force ESI refreshes.

Deploy: cd /opt/rigauth && bash ./deploy-divine-reign.sh

The deployment script fast-forwards main, backs up touched SeAT files, applies the sidebar/routes patch, installs module files, migrates, clears caches, verifies routes, and restarts the front service. All records use the existing SeAT database.
