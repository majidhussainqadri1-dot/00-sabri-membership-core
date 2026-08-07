# File 00 Release 1.2.13 — Advanced Identity & Trust

This release extends File 00 with F00-EXT-001 through F00-EXT-020 while preserving the 7 August 2026 latest-central constitution.

## Compatibility

- Plugin release: 1.2.13
- Database schema: 1.3.0 (no physical schema migration)
- Public membership contract: 1.2.0 (unchanged)
- CF-01 contract: 1.0.0 (unchanged)
- Advanced trust contract: 1.0.0 (new)
- File 26 projection contract: 1.0.0 (unchanged)

The implementation uses existing canonical File00 tables, privacy-safe user metadata/options and the tamper-evident audit subsystem, so no new database table owner is introduced.

## New capability families

Identity assurance levels; File02 passkey/WebAuthn assurance adapter; adaptive step-up; periodic reverification; critical identity change; claim provenance/freshness; consent dependency graph; guardian succession; duplicate-account merge governance; compromised-account containment; revocation propagation SLA; contract negotiation; privacy-minimal assertions; selective disclosure; verifiable-credential adapter; delegated authority; dual-control break-glass; service identities; account continuity states; trust/security timeline.

## Status honesty

Repository coding and automated QA are release gates. Hostinger staging acceptance, live deployment and operational evidence remain separate and must not be inferred from this release document.
