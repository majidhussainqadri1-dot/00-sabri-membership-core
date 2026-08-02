# File 00 — Release 1.2.4

## Purpose

Release 1.2.4 converts the four-round reviewed File 00 master plan into repository-governed traceability and corrects authorization defects found by auditing the 1.2.3 runtime against `F00-R001`–`F00-R100`.

## Version state

- Plugin: `1.2.4`
- Contract: `1.1.2`
- Database: `1.2.0` — unchanged; no structural migration
- Corrective GitHub Actions verification: **Passed**
- Staging installation candidate: **Yes**
- Staging accepted: **No**
- Production approval: **No**

## Verified release evidence

- Corrective PR head: `5efab3d837700fd15d27b518a6e98942bd802af2`
- Verified PR merge ref: `1ce11cfdfca953d57cab3abd96a8c02faf8c6db8`
- Workflow run: `30732165567`
- Workflow job: `91454369095`
- Merged `main` commit: `1ef2a3898eafbe5b5c023ab24e42fcca1b89a472`
- Deterministic package SHA-256: `c22e05c4bd60fb2540715f507a11f905d1d01d9d44fd8b53bf9946e48bc7934a`
- Package verification: 0 unsafe entries, 0 symlinks, 0 manifest mismatches and 0 CRC failures.

## Corrected defects

1. Removed broad Administrator bypasses from File 00 protected capabilities, File 00 admin actions and protected REST mutations.
2. Replaced broad `smc_*`/`sa_*` recovery-prefix logic with exact allowlists.
3. Preserved public/safe reading while enforcing protected mutations contextually.
4. Added guardian- and contact-aware effective eligibility plus current-session two-factor enforcement.
5. Locked ordinary Founder reassignment and clearing after configuration.
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

The successful corrective GitHub Actions run authorizes a staging installation candidate only. No direct live replacement is authorized. Hostinger staging and every pending gate in `STATUS.md` remain mandatory.
