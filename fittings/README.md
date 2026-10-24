# Fittings

Native market and fitting tools for the Triglavian Consortium's SeAT installation.

This project is maintained entirely in `rigauth/fittings/`. It replaces the legacy standalone market service and is designed to appear inside the existing SeAT UI at `https://auth.triglavianconsortium.com`.

## Architecture goals

- Native SeAT navigation and authenticated session; no separate market hostname.
- Use the existing SeAT MariaDB server, with a dedicated `eve_market` schema and least-privilege database account.
- Keep all first-party project code, integration glue, migrations, tests, deployment instructions, and configuration templates under this directory.
- Keep credentials and server-specific `.env` files out of Git.
- Use EVE SSO/ESI only for character authorization and ESI data access that actually require it; do not redirect users through the legacy Reactions configuration.
- Preserve any existing market data until an explicit migration and validation plan is approved.
- Do not remove or disable the currently running legacy service until the replacement is tested in SeAT.

## Planned layout

- `seat/`: SeAT route/controller/view integration and installation tooling.
- `app/`: market and fitting application logic.
- `database/migrations/`: schema migrations for the dedicated market schema.
- `esi/`: EVE SSO and ESI clients, scopes, and callback handling.
- `config/`: safe configuration defaults and validation.
- `tests/`: automated tests.
- `docs/`: architecture, setup, deployment, and migration notes.

## Deployment principles

1. Inspect the exact SeAT source revision pinned by this repository before implementing framework integration.
2. Build and validate the new interface and data layer without changing the running service.
3. Configure the application to use the existing SeAT MariaDB container/network; do not add another database server.
4. Use SeAT's authenticated session for web access where possible. Keep any required EVE SSO callback on the configured SeAT host/path and document it explicitly.
5. Test database connectivity, authorization boundaries, migrations, and the complete sign-in flow before cutover.
6. Keep rollback available until the replacement has been verified.

## Secrets

Never commit `.env`, client secrets, signing keys, database passwords, session secrets, or access tokens. Use `.env.example` for variable names and safe example values only.
