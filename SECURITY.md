# File 00 — Current Source Security Constitution

**Repository source only:** plugin **1.2.44**, schema **1.4.5**, public contract **1.2.3**. This is not an attestation of Hostinger deployment or live database migration.

File00 uses deny-by-default membership action authorization, current age/guardian evidence, versioned and bounded identity assurances, guarded session revocation, key generation separation, authenticated tamper-evident audit entries/tail locking, private encrypted documents, path/symlink constraints, retention and privacy controls. The authentication ceremony, login/password recovery, passkey and step-up authenticators are **owned by File02**. Founder directive **10 August 2026** retired File00's own MFA/TOTP and authenticator-recovery requirements; any remaining historical code is not proof of an active File00 factor.

**Legacy publishing migration:** File04 authorization is purpose-, source-, operator- and digest-bound. The File00-owned unknown-author sentinel cannot sign in by password **or preexisting session cookie**, even if a DB failure left both metadata markers absent. Its successful creation requires persisted no-login/attribution markers and audit-completion attestation; an unaudited orphan cannot be recycled as a verified identity without a successful recovery audit. A failed database deletion remains an operator recovery incident, not a verified full rollback. Synthetic fault fixtures do **not** substitute for a real MySQL outage/recovery test.

**Audit integrity:** Missing audit-tail recovery is permitted only after complete guarded chain validation. Historical audit keys are verification-only and may authenticate immutable rows without rewriting them; unknown key generations fail closed. If a caller owns a transaction, audit readiness is read-only: CREATE/ALTER/bootstrap must finish beforehand to prevent silent MySQL DDL commits.

**Release and evidence discipline:** Source candidate, current main, connected companion source, artifact, staging, live deployed plugin and database are distinct. Never label a source fix live-resolved until deployed-package parity, migration state and live retest are verified. Browser/RTL/accessibility, backup/restore, exact package SHA, independent security review and Founder acceptance remain open until independently evidenced. Historic 1.2.33 source/MFA claims are superseded in this document; no live state is inferred.

**Live incident status:** Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔
