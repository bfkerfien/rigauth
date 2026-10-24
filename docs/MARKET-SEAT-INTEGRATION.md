# Market inside SeAT

The native Market section is at /market. Its left-navigation link and every controller action allow only a SeAT superuser or a SeAT role named exactly Marketeers. Normal users are denied. Assigning the Marketeers role is an administrator step.

All Market tables are created in SeAT's configured Laravel database and are prefixed mkt_. The migration does not create another database or modify existing SeAT tables. It covers the collector's runs, orders, price comparisons, EVE token storage, fit/doctrine data, Shame List, and doctrine-health snapshots.

The old standalone Market SSO bridge is not needed for these native pages: they use the SeAT authenticated session directly. The older /triglavian/sso/authorize endpoint remains in the repository only for the old standalone Market app until it is retired; do not use it for the integrated pages. Do not remove the standalone callback implementation from the old collector until no remaining deployed client depends on it.

Known gaps: the checked-in legacy /under130 route renders the same template as /over130, and /api/runs/{run_id}/profit18 returns prices and volume metadata rather than implementing an 18% threshold. No legacy Shame List table/route was found. The native pages surface recoverable data and the new Shame List, but existing Market rows and doctrine-health snapshots still need migration/import before production use.

No production deployment is performed by this branch.
