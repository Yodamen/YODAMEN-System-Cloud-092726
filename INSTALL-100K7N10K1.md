# YODAMEN 100K7N10K1 — Sidebar Active Highlight Real Fix

This patch supersedes 100K7N10K, which was packaged without the Sidebar source files.

Changes:
- Only the most-specific matching submodule receives the active class.
- Replaces NavLink with Link so route partial matching cannot create a second active navigation state.
- Keeps the current Customers / Operations / Sales navigation order.
- Does not replace global CSS or any page code.

Install from the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N10K1-SIDEBAR-ACTIVE-HIGHLIGHT-REAL-FIX.zip -d .
cd frontend
npm run build
```
