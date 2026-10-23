# SeAT source and deployment

This repository records the SeAT source revisions used for the Triglavian Consortium deployment and provides a repeatable way to restore them. Upstream SeAT source remains authored and maintained by the EVE SeAT project; `sources.lock.json` pins the exact commits used here.

## Restore the source set

Run `bash ./restore-seat-source.sh /opt/seat-source` on a Linux host with Git installed. The script checks out the pinned SeAT, SeAT Docker, and official package repositories under that directory, then writes the local `packages/override.json` used by the Docker development mount.

Do not commit a server `.env` file or generated credentials here. Keep deployment secrets on each server.
