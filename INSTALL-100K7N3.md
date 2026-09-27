# YODAMEN 100K7N3 — Funding Source Regression Test Alignment

This patch updates only `FundingSourceSeparationTest.php` to match the approved operating-fund policy:

- Owner's Capital is not an Operating Fund Source.
- Owner's Capital cannot directly pay an Expense.
- Owner funding must first be recorded through Cash Management → Business Funding.
- Cash on Hand remains unavailable for normal operational disbursements.
- Cash in Bank remains an eligible Operating Fund Source when configured accordingly.

No production code and no database migration are included.
