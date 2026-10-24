# SSO notes

## Integrated Market section

The Market section at /market is part of SeAT itself. It uses SeAT's normal authenticated session and server-side access checks. It does not require TRIGLAVIAN_MARKET_SSO_SECRET, TRIGLAVIAN_MARKET_SSO_CALLBACK, or SEAT_SSO_SHARED_SECRET for its native pages.

## Legacy standalone Market UI

The signed assertion bridge documented in older revisions was used by the former standalone Market collector UI at market.triglavianconsortium.com. The Market integration patch removes that bridge from the active SeAT route table for the integrated pages. Do not use these legacy settings to configure the native Market section.

EVE SSO used by the collector to authorize a character for ESI structure-market data is a separate process. Do not remove its EVE client configuration unless the data collector itself is being retired or replaced.

Keep all environment secrets out of Git.
