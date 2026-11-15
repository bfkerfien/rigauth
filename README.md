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

The restore script exits without changing anything if its destination already exists. It applies the header and Fittings patches, then uses the idempotent navigation helper to register Admin Setup, Reactions, Exploration, and the Market SSO bridge routes and sidebar links. Admin Setup manages designated T2 stations and fittings and is available to SeAT superusers plus the explicitly authorized `turaneau` account. Exploration adds interactive Branch, Tenal, and Venal system maps; progress is shared across authenticated SeAT users, green marks are stored in the SeAT database, and red exclusions are permanent code-defined entries. Supporting controllers, views, routes, and migrations are copied from `custom/seat-web/`. The package autoload override maps only the customized SeAT Web package so the image's matching upstream dependencies remain in use. Divine Reign is deployed separately with `deploy-divine-reign.sh`.

Do not commit a server `.env` file or generated credentials here. Keep deployment secrets on each server.


See [`SEAT_SSO_SETUP.md`](./SEAT_SSO_SETUP.md) for configuring the SeAT-to-Market SSO bridge. The shared signing secret belongs only in server environment files, never in this repository.
