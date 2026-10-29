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

The restore script exits without changing anything if its destination already exists. It applies the tracked customizations in `patches/`: the character portrait and name move to the top right beside Sign Out, the Fittings section and shopping-list tabs are added, and a superuser-only Admin Setup page manages designated T2 stations and fittings. Supporting controller, view, route, and migration files are kept in `custom/seat-web/` and copied into the reconstructed web package. The SSO bridge also adds `/triglavian/sso/authorize` for the Market fitting manager; it requires an authenticated SeAT superuser and signs a short-lived assertion for the single configured callback. The package autoload override is saved at `seat-docker/packages/override.json`.

Do not commit a server `.env` file or generated credentials here. Keep deployment secrets on each server.


See [`SEAT_SSO_SETUP.md`](./SEAT_SSO_SETUP.md) for configuring the SeAT-to-Market SSO bridge. The shared signing secret belongs only in server environment files, never in this repository.


## Mining Ledger add-on

The proposed native SeAT Mining Ledger is maintained under [mining/](./mining/). It records mining operations and reported yields, accepts manual entries and CSV imports, caches estimated Jita sell prices, and applies personal-versus-corporation visibility rules. See [mining/README.md](./mining/README.md) for the import format, ESI limitations, access setup, and deployment procedure.

The Mining Ledger is a source overlay for the existing SeAT web package and uses the existing SeAT database. Its deployment script backs up modified files and does not create a separate service or database. Review the corporation ACL and test on the current deployment before granting access.
