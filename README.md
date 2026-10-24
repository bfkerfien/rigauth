# SeAT source and deployment

This repository tracks the SeAT source revisions used for the Triglavian Consortium deployment. sources.lock.json records pinned upstream commits. Clone with `git clone --recurse-submodules https://github.com/bfkerfien/rigauth.git`.

The tracked customizations add the Fittings section, management pages, and Triglavian Admin Setup. The Market integration branch adds a native Market section at `/market` inside SeAT with Shame List, Under 30, 18 Percent, and Doctrine Health tabs. It uses SeAT's authenticated session directly; the old standalone Market SSO bridge route is no longer included in the active route table.

Market access is limited to SeAT superusers and the SeAT role titled `Marketeers`. Market-owned data uses `mkt_`-prefixed tables in SeAT's existing configured database. See `docs/MARKET-SEAT-INTEGRATION.md` for known gaps and safe deployment steps. Never commit server .env files or credentials.
