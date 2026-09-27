# Phase 7D — Update 82B Compensation Term Timeline Fix

The Update 82/82A regression suite exposed an environment-sensitive duplicate-key issue in `personnel_compensation_terms` under SQLite.

The compensation term table is unique on `(personnel_id, effective_from)`. Eloquent immutable date casts can serialize a date as a midnight timestamp in SQLite tests while MySQL DATE columns store the same value as `YYYY-MM-DD`. An exact `updateOrCreate` lookup can therefore miss the existing SQLite row before attempting an insert, which then violates the unique constraint.

Update 82B resolves the issue using date-aware matching and also corrects initial term dating so a newly created personnel record uses its hire date when no explicit compensation-effective date was supplied. This keeps historical compensation aligned with actual employment dates.

Approved compensation-date protections introduced in Update 82A remain unchanged.
