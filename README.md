# SeAT source and deployment

This public repository tracks the SeAT source revisions used for the Triglavian Consortium deployment. `sources.lock.json` records the exact upstream commits, and `upstream/` contains Git submodules pinned to those same commits. The source remains authored and maintained by the EVE SeAT project.

## Clone the complete source set

```sh
git clone --recurse-submodules https://github.com/bfkerfien/rigauth.git
```

This checks out SeAT, SeAT Docker, and the seven official package repositories under `upstream/`. To restore the same revisions into a deployment layout under `/opt/seat-source`, run:

```sh
cd rigauth
sudo bash ./restore-seat-source.sh /opt/seat-source
```

The restore script exits without changing anything if its destination already exists. It applies the tracked customizations in `patches/`: the character portrait and name move to the top right beside Sign Out, the Fittings section and shopping-list tabs are added, and a superuser-only Admin Setup page manages designated T2 stations and fittings. Supporting controller, view, route, and migration files are kept in `custom/seat-web/` and copied into the reconstructed web package. The package autoload override is saved at `seat-docker/packages/override.json`.

## Market section

The `market/seat-integration` branch adds a native `/market` page inside SeAT with Shame List, Under 30, 18 Percent, and Doctrine Health tabs. It uses SeAT's authenticated session directly and restricts access to SeAT superusers and a SeAT role titled `Marketeers`.

Market reuses the existing `fit_` collector snapshot and fitting/doctrine tables in SeAT's configured database to avoid duplicating existing data. Any future Market-only tables must use the `mkt_` prefix and must also live in SeAT's configured database. See `docs/MARKET-SEAT-INTEGRATION.md` for tab behavior and verification steps.

`SEAT_SSO_SETUP.md` documents the legacy bridge used by the former standalone Market UI. It is not needed by the integrated Market pages. EVE SSO used by the collector to retrieve ESI market data is a separate concern.

Do not commit a server `.env` file or generated credentials here. Keep deployment secrets on each server.

## Potchven Finances section

The `finances/seat-integration` branch adds a native SeAT dashboard at `/potchven-finances`, restricted to SeAT accounts linked to EVE character Turaneau. Finance data is imported into SeAT's database using the `fin_` table prefix. See `docs/FINANCES-SEAT-INTEGRATION.md` for deployment and the guarded one-time import. Live ESI synchronization still needs to be ported from the standalone application; the dashboard currently reads imported data and supports manual site recording.
