# SeAT source and deployment

This repository tracks the SeAT source revisions used for the Triglavian Consortium deployment. sources.lock.json records pinned upstream commits. Clone with `git clone --recurse-submodules https://github.com/bfkerfien/rigauth.git`.

The tracked customizations add the Fittings section, its management pages, Triglavian Admin Setup, and the SeAT-to-Market SSO bridge for the old standalone collector. The Market integration branch adds a native Market section at `/market` inside SeAT. It uses SeAT's authenticated session (not a second Market login), shows Shame List, Under 30, 18 Percent, and Doctrine Health tabs, and limits access to SeAT superusers or the role named `Marketeers`.

Market-owned data belongs in SeAT's existing configured database. All new Market tables use the `mkt_` prefix. See `docs/MARKET-SEAT-INTEGRATION.md` for known gaps and safe deployment steps. Never commit server .env files or credentials.
