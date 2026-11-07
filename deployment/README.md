# Yodamen deployment tools

The application source remains in this repository's backend and frontend folders.
These deployment folders contain maintained tools, not separate application copies.

- installer: build an archive with clean new-client setup or SQL-dump restore for a new computer. Read
  [installer instructions](installer/README.md) and [verification](installer/VERIFICATION.md).
- patch: build updates for existing Docker installations. Read
  [patch instructions](patch/PATCH.md) and [verification](patch/VERIFICATION.md).

On the publisher computer, use Python 3 from the repository root:

```sh
python3 deployment/installer/package-installer.py --output /private/path/releases --version UNIQUE_INSTALLER_VERSION
python3 deployment/patch/package-patch.py --output /private/path/releases --version UNIQUE_PATCH_VERSION
```

Use a unique version for every different build because both tools tag images as
yodamen-local:VERSION. Keep generated packages outside Git. Builders take filtered
tracked backend/frontend working files, including tracked edits, and record the
source digest and whether app changes were uncommitted. Client release packages
should be reproducible from an accepted commit; local test packages may include
working changes as recorded in their manifest. If new backend/frontend files are
untracked, builders refuse to omit them silently. Commit or stage them first, or
pass --include-untracked explicitly for a test package; the manifest records it.

Both archives include Mac and Windows launchers. Docker/internet are prerequisites;
Windows additionally needs WSL Ubuntu integration. Neither archive contains client
SQL dumps, uploads, runtime .env files, publisher tools or the private signing key.
Clean installers enable protected browser onboarding without a dump. Restore mode
takes data inputs separately; a patch preserves installed data.
Public key verification stays in the backend. Generate activation codes on the
publisher computer using tools/issue-license.php and the protected external key.
