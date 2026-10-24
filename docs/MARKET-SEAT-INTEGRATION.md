# Market inside SeAT

The native Market section is at /market. It uses SeAT's authenticated web session directly; every controller action permits a SeAT superuser or a SeAT role whose title is exactly Marketeers. The left navigation uses the same rule. Normal users are denied. Creating and assigning Marketeers remains an administrator step.

## Database and data reuse

The page reads existing collector snapshots and fitting/doctrine data from SeAT's configured database:
- fit_collection_runs
- fit_item_comparisons
- fit_market_type_volumes
- fit_market_orders
- fit_fits
- fit_doctrines
- fit_doctrine_assignments
- fit_items

These tables already belong to the SeAT Fittings integration, so the Market section reuses them rather than creating duplicate copies of market snapshots or doctrine/fitting records. No migration is required for this read-only Market page. Any future Market-only tables must use the mkt_ prefix and must be created in SeAT's configured database.

## Tab behavior

- **Shame List:** structure lowest sell price is more than 30% above Jita 4-4's lowest sell price.
- **Under 30:** markup is from 0% through 30%, inclusive.
- **18 Percent:** item volume is used to estimate transport at 500 ISK/m³; structure sale proceeds are reduced by 4.37% fees. The row qualifies when Jita acquisition plus transport is no more than 82% of net structure proceeds.
- **Doctrine Health:** for 100 ships per assigned fit, sums item demand by doctrine and compares it with available sell-order volume in the latest completed structure snapshot. Health is the percentage of required item types without a shortfall.

The 18 Percent calculation matches the legacy collector's documented calculation. The former collector's /under130 route rendered the same template as /over130; the integrated page implements the intended 0–30% rule explicitly.

## Authentication

Native Market routes are inside SeAT's authenticated web middleware and enforce the superuser/Marketeers rule server-side. They do not use the old standalone Market SSO bridge. EVE SSO used by the collector for ESI market-data access is a separate data-collection concern and is not replaced by SeAT web login.

## Deployment and verification

1. Review the market/seat-integration branch.
2. Rebuild the SeAT source with restore-seat-source.sh after applying the patch.
3. Confirm the Fittings schema has been installed in SeAT's configured database and contains the tables listed above.
4. Create/assign a SeAT role titled Marketeers in SeAT.
5. Test as a SeAT superuser, a Marketeer, and a normal user. The normal user must receive 403 from /market and must not see the sidebar entry.
6. Confirm the latest collector run has completed and that item volumes have been populated before validating 18 Percent results.
7. Do not treat the feature as production-ready until it has been tested against the live SeAT version and database.
