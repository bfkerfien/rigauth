# Market inside SeAT

The native Market section is at /market. It uses SeAT's normal authenticated web session directly; the old standalone Market SSO bridge route is removed from the active route table by the integration patch. No second Market login or SSO secret is needed for the native pages.

## Access control
Every Market controller action permits a SeAT superuser or a SeAT role named exactly Marketeers. The sidebar uses the same visibility rule. Normal users are denied. Creating and assigning Marketeers remains an administrator step.

## Database
Migration 2026_10_24_010000_create_mkt_market_tables.php creates Market-owned tables in SeAT's existing configured Laravel database. Every new table is prefixed mkt_. It does not create a separate database or alter existing SeAT tables. Tables cover collector runs, orders, price comparisons, EVE token storage, Shame List entries, and doctrine-health snapshots. Doctrine Health reads existing Fittings records (fit_fits, fit_doctrine_assignments, and fit_items) instead of duplicating them in new mkt_ tables.

## Recovered source and gaps
The source repository bfkerfien/reactions contains the old collector routes and schema. Its /api/runs/{run_id}/profit18 endpoint returns structure/Jita sell prices plus volume metadata; the checked-in endpoint does not itself implement an 18% threshold. The old /under130 page currently renders the same template as /over130, and no legacy Shame List table/route was found. The native pages surface recoverable comparison data and provide a SeAT-native Shame List, but exact legacy behavior and import of existing collector rows still need completion before production launch.

## Deployment
1. Review branch market/seat-integration.
2. Rebuild the SeAT source with restore-seat-source.sh after applying the patch.
3. Apply the migration using the deployment's normal SeAT database migration process.
4. Import old collector data into corresponding mkt_ tables before expecting comparisons and doctrine-health snapshots to appear.
5. Configure/assign the SeAT role Marketeers and test access as an admin, Marketeer, and normal user.
6. Do not deploy until legacy calculations and data migration have been verified.
