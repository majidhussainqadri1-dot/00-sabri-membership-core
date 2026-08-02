# File 00 — Release 1.2.4

## Purpose

Release 1.2.4 converts the four-round reviewed File 00 master plan into repository-governed traceability and corrects authorization defects found by auditing the 1.2.3 runtime against `F00-R001`–`F00-R100`.

## Version state

- Plugin: `1.2.4`
- Contract: `1.1.3`
- Database: `1.2.0` — unchanged; no structural migration
- Staging approval: **Pending**
- Production approval: **No**

## Corrected defects

1. Removed broad Administrator bypasses from File 00 protected capabilities, File 00 admin actions and protected REST mutations.
2. Replaced broad `smc_*`/`sa_*` recovery-prefix logic with exact allowlists.
3. Preserved public/safe reading while enforcing protected mutations contextually.
4. Added guardian- and contact-aware effective eligibility plus current-session two-factor enforcement.
5. Locked ordinary Founder reassignment after configuration.
6. Derived localized client age policy from the canonical server policy.
7. Added checksum-governed master-plan artifact registry and 100-requirement traceability.

## New evidence

- `source/sabri-membership-core/includes/class-smc-authorization.php`
- `qa/authorization-boundary-contract.mjs`
- `qa/authorization-boundary-runtime.php`
- `qa/master-plan-traceability-contract.mjs`
- `qa/requirements-traceability.json`
- `docs/FILE-00-IMPLEMENTATION-TRACEABILITY-1.2.4.md`
- `docs/FOUR-ROUND-RUNTIME-AUDIT-1.2.4.md`

## Deployment boundary

A green GitHub Actions run authorizes a staging candidate only. No direct live replacement is authorized. Hostinger staging and the pending gates in `STATUS.md` remain mandatory.
