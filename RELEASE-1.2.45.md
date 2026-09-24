# File 00 — Sabri Membership Core 1.2.45

Repository/source correction for the File 04 legacy-publishing migration boundary.

## Changes

- Added a File 00-owned immutable platform UUID compatibility provider for File 04 authorship migration.
- Added a governed dedicated placeholder identity for deleted/unknown legacy authors.
- The placeholder is explicitly labelled, receives no publishing authority, and is blocked from interactive login.
- Preserved the Founder-approved retirement of File 00 MFA; authentication assurance remains outside this compatibility bridge.
- No database-schema version change. `SMC_DB_VERSION` remains `1.4.5`.

## Evidence boundary

This release is repository/source evidence only until packaged, deployed and verified in the target environment.
