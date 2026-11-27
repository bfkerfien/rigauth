# RIGAUTH Desktop

Standalone Windows 11 x64 Electron desktop application. No SeAT, Docker, PHP, Python, or Node installation is required on the target PC. Electron bundles the Chromium runtime and Node runtime; SQLite is stored under the current Windows user's application data.

## Build
Run `npm ci` then `npm run dist` on Windows 11 x64 (or use the GitHub Actions workflow). The installer is a one-click NSIS installer and creates a desktop shortcut.

## First launch
1. Click **Sync EVE universe**. The app retrieves official EVE region, constellation, solar-system coordinate, and stargate data from CCP ESI and caches it locally in SQLite.
2. Configure an EVE developer SSO application in Settings. Use callback URL `http://127.0.0.1:17841/callback`, authorization-code flow with PKCE, and scopes `esi-location.read_location.v1`, `esi-wallet.read_character_wallet.v1`, and `esi-assets.read_assets.v1`.
3. Connect your EVE character, then use Center on character.
4. Explore Branch, Tenal, Venal, and Geminate. Left click toggles explored; right click opens the scan/signature editor. Scan imports support EVE Probe Scanner tab-separated clipboard data, add/update, replace, and timestamp-only updates.

## Local data
The database `rigauth.sqlite` is automatically created in Electron's userData directory. It stores settings, systems, gates, explored markers, signatures, wormhole notes, scan history, manual cyno markers, finance sessions, tracked characters, and wallet journal entries. Back up the database by copying it after closing RIGAUTH.

## Important
The standalone application does not connect to, modify, or require the existing SeAT server. The EVE SSO Client ID and Secret must be supplied by the user from their own registered EVE developer application.
