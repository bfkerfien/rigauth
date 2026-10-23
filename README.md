# SeAT source and deployment

This public repository records the SeAT source revisions used for the Triglavian Consortium deployment and provides a repeatable way to restore them. Upstream SeAT source remains authored and maintained by the EVE SeAT project; `sources.lock.json` pins the exact commits used here.

## Restore the source set

On a Linux host with Git installed, run:

```sh
git clone https://github.com/bfkerfien/rigauth.git
cd rigauth
sudo bash ./restore-seat-source.sh /opt/seat-source
```

The script checks out the pinned SeAT, SeAT Docker, and seven official package repositories under `/opt/seat-source`, then writes `seat-docker/packages/override.json` for the Docker development mount. It exits without changing anything if the destination already exists.

Do not commit a server `.env` file or generated credentials here. Keep deployment secrets on each server.
