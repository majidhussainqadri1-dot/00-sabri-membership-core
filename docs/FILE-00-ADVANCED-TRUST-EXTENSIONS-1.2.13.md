# File 00 — Advanced Membership, Identity & Trust Extensions 2026

Release target: **1.2.13**  
Advanced-trust contract: **1.0.0**  
Database schema: **1.3.0 (unchanged)**  
Public membership contract: **1.2.0 (unchanged)**

## Constitutional boundary

File 00 remains the canonical membership and identity-assurance authority. Authentication ceremonies and passkey registration/authentication remain owned by File 02; File 00 consumes a versioned File 02 assurance claim. Professional credential truth remains File 09. Search/discovery/ranking remains File 26. No parallel owner, paid tier, donor privilege, search backend, authentication backend, or professional-verification backend is introduced.

## Stable extension requirements

| ID | Requirement | Implementation / invariant |
| --- | --- | --- |
| F00-EXT-001 | Identity Assurance Levels | Separate identity and authentication assurance levels with current membership/contact/guardian/identity/professional state. |
| F00-EXT-002 | Passkey / WebAuthn Assurance Contract | Versioned File 02 adapter; elevated claims require owner=`file02`, compatible contract and fresh verification. |
| F00-EXT-003 | Adaptive Step-Up Verification | Action-specific identity/authentication/hardware-backed requirements; File 24 risk context can strengthen, not weaken. |
| F00-EXT-004 | Periodic Reverification Engine | `verified_at`, `due_at`, overdue marker and bounded 200-subject cursor sweep. |
| F00-EXT-005 | Critical Identity Change Workflow | Legal identity/contact/guardian/government-ID changes set mandatory revalidation, revoke sessions and require explicit resolution. |
| F00-EXT-006 | Claim Provenance & Freshness | File00/File02/File09 ownership, issued/expiry times and revocation epoch. |
| F00-EXT-007 | Consent Dependency Graph | Capability-to-purpose graph from the existing consent ledger; extension filters may add but cannot remove baseline purposes. |
| F00-EXT-008 | Guardian Succession / Replacement | Audited request; completion requires a newly verified guardian consent distinct from the prior verified record. |
| F00-EXT-009 | Account Merge / Duplicate Resolution | Evidence-review + independent senior approval; duplicate becomes permanently inactive; canonical domain transfer is emitted to owners rather than copied into File 00. |
| F00-EXT-010 | Compromised-Account State | `security_recovery_required`/`contained` states revoke sessions and strip protected capabilities while preserving safe recovery/read paths. |
| F00-EXT-011 | Revocation Propagation SLA | Monotonic epoch plus invalidation event with 60-second consumer deadline. |
| F00-EXT-012 | Canonical Contract Negotiation | Semantic-version compatibility, minimum version, future/unsupported fail-closed and no downgrade flag. |
| F00-EXT-013 | Privacy-Preserving Assertions | Opaque subject plus boolean/minimal claims; no DOB, raw phone, address, guardian contact or identity document. |
| F00-EXT-014 | Selective Disclosure Proofs | Purpose-bound, audience-bound, File00-keyed short-lived proof; maximum TTL 300 seconds. |
| F00-EXT-015 | Verifiable Credential Adapter | External signed-credential adapter; File00 consumes verified metadata without becoming professional credential owner. |
| F00-EXT-016 | Scoped Delegated Authority | Allowlisted scopes, maximum 90-day expiry, current-actor binding, audit and revocation. |
| F00-EXT-017 | Founder / Institutional Break-Glass Governance | 15-minute request, two unique privileged approvals, fresh security challenges and one-time bounded consumption. |
| F00-EXT-018 | Non-Human / Service Identity Classes | Human, institutional-AI and approved service identities remain distinct; non-human subjects cannot inherit doctor identity. |
| F00-EXT-019 | Dormant / Deceased / Permanently Inactive Lifecycle | Authorship preserved; protected actions and sessions disabled until a governed active state exists. |
| F00-EXT-020 | User-Facing Trust & Security Timeline | Privacy-safe allowlisted events from the tamper-evident File00 audit chain; no audit details/private evidence exposed. |

## Security / privacy invariants

- Sensitive entitlement truth remains free and donor-neutral.
- Critical changes, containment, continuity and revocation fail closed.
- Current actor identity is bound to privileged mutation APIs to prevent confused-deputy invocation.
- File02 passkey/WebAuthn claims cannot elevate assurance unless owner/contract/freshness checks pass.
- Selective-disclosure proofs use File00 security key derivation and bounded lifetime.
- Baseline consent dependencies cannot be removed by downstream filters.
- Existing `SMC_Contracts::assertions()` directly enforces advanced containment/continuity for protected actions, rather than relying on an optional presentation filter.

## Acceptance evidence

New static contract: `qa/advanced-trust-contract.mjs`  
New runtime contract: `qa/advanced-trust-runtime.php`  
Machine traceability: `qa/advanced-trust-traceability.json`

Staging, live deployment and operational acceptance remain external gates and are not implied by this repository implementation.
