# Test plan

Tests are intentionally documented before production integration because SeAT's actual model names, permission middleware and ESI services must be verified against the deployed version.

Required automated coverage:
- A logged-in user can see every character actually linked to that SeAT user.
- A user cannot see assets belonging to a different SeAT user by changing character IDs or filters.
- Corporation assets require explicit corporation permission.
- Asset Explorer reads only local database tables and does not call ESI.
- All rows include a character owner for personal character assets; corporation rows retain corporation ownership.
- Incomplete or failed ESI pagination never deletes or reconciles prior snapshot rows.
- Duplicate sync jobs for the same owner are serialized or safely deduplicated.
- Expired price data is marked stale and does not silently appear current.
- Large inventories are paginated and searchable without loading all rows into memory.
