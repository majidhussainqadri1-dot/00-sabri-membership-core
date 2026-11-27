# Dependencies — File 00

File 00 owns canonical membership eligibility, identity assurance, guardian consent, verification governance, security assertions, and related audit evidence.

External delivery dependencies remain adapter-based:
- Email/SMS delivery readiness and receipt-bearing notification transport are supplied by File 19 / configured providers.
- WordPress/PHP/MySQL capabilities required by File 00 are validated by the runtime and integration gates.

Current unreleased source candidate (PR #66): runtime `1.2.47`, database schema target `1.4.5`, public contract `1.2.3`. This does not identify an installed or approved production release.

File 02 owns authentication and session/credential effects. The narrow same-account email collision decision is not a general recovery, collision or lockdown-release owner contract. Those three full owner workflows remain pending independent proof and approval.

Provider configuration and live delivery acceptance are separate from repository source merge.
