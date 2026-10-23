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

The restore script exits without changing anything if its destination already exists. It applies the tracked customizations in `patches/`, including the top-right logout button, and saves the package autoload override at `seat-docker/packages/override.json`.

Do not commit a server `.env` file or generated credentials here. Keep deployment secrets on each server.
