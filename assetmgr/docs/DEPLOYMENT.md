# Deployment checklist (not ready for production)

This directory must not be copied into the live SeAT runtime yet.

1. Identify the exact SeAT image tag, PHP/Laravel version, Composer setup and mounted package paths from `/opt/seat-docker`.
2. Review SeAT's official extension conventions for that exact version.
3. Wire SeAT-native user/character relationships and permission middleware; verify with automated tests.
4. Complete and test the ESI synchronization and price-cache jobs.
5. Build and test the package in a disposable environment using a copy of the current configuration and a separate test database.
6. Back up the existing database and confirm rollback steps.
7. Only then install the package, run its migration, and enable it with `SEAT_ASSETMGR_ENABLED=true`.
8. Keep synchronization disabled until tokens, queue workers, schedules, and authorization tests pass.
9. Verify health of existing SeAT services and test with two separate SeAT users to confirm private assets never cross account boundaries.

Never commit real EVE client secrets, refresh tokens, database passwords, or production environment files.
