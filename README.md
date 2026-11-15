# SeAT source and deployment

This repository tracks the pinned upstream SeAT source plus the Triglavian Consortium's SeAT modules. `sources.lock.json` records the upstream revisions; `upstream/` contains the corresponding Git submodules.

## Clone and restore the source

```sh
git clone --recurse-submodules https://github.com/bfkerfien/rigauth.git
cd rigauth
sudo bash ./restore-seat-source.sh /opt/seat-source
```

The restore script exits without changing anything if its destination already exists. It checks out the pinned upstream repositories, applies the small header/Fittings patches, and overlays the tracked custom source and Asset Manager package. The generated SeAT Docker override registers both package namespaces and the Asset Manager service provider.

The restored SeAT UI includes fittings and doctrines, reactions, Planetary Industry planning, the market browser, Potchven Finance, the mining ledger, the Triglavian Cookbook, Divine Reign, exploration maps, Triglavian administration, and Asset Manager. Their routes and pages run within SeAT authentication. The public SSO issuer and callback use `auth.triglavianconsortium.com`.

The Potchven Finance interface reads SeAT's `fin_*` tables. Existing records from the restored SeAT database are preserved. Scheduled ESI refresh requires the original private Finance configuration archive, including its token-encryption key and EVE client settings. That archive is not available on this host and secrets are never stored here.

Asset Manager's UI and local tables are included. Review its live SeAT authorization and ESI scopes before scheduling new data refreshes; never commit tokens or server environment files.

Do not commit a server `.env` file or generated credentials here. Keep deployment secrets on each server.
