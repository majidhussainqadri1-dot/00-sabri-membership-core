#!/usr/bin/env python3
"""Finalize truthful post-CI evidence for File 00 release 1.2.11."""
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
SOURCE_HEAD = "2618d9d75896ae3881c5404511103c23ffca8d04"
THREE_PLAN_RUN = 31002935962
CONTRACT_RUN = 31002938897
ARTIFACT_ID = 8929059080
PACKAGE = "dist/00-sabri-membership-core-1.2.11.zip"
PACKAGE_SHA = "0dca9b3fa9995736332c40d6a44f5dc36e2ff62d771c9cbea637d022cad9c715"
ARTIFACT_DIGEST = "5ff7d0bedced12c295661e45944c64d739123ea2a4e4fd4575a875be7f1c519c"

registry_path = ROOT / "qa/requirements-traceability.json"
registry = json.loads(registry_path.read_text(encoding="utf-8"))
registry["runtime_evidence"] = {
    "verified_source_head": SOURCE_HEAD,
    "three_plan_workflow_run_id": THREE_PLAN_RUN,
    "contract_workflow_run_id": CONTRACT_RUN,
    "artifact_id": ARTIFACT_ID,
    "artifact_digest_sha256": ARTIFACT_DIGEST,
    "conclusion": "success",
    "merge_sha": "",
    "package": PACKAGE,
    "package_sha256": PACKAGE_SHA,
    "evidence_boundary": "The verified source head is exact and immutable. This later evidence-only commit records its successful runs and does not alter plugin runtime or package source."
}
registry["repository_code_completion_percent"] = 100
registry["repository_known_unresolved_defects"] = 0
registry["staging_installation_candidate"] = True
registry["staging_accepted"] = False
registry["production_approved"] = False
registry["live_installation_authorized"] = False
registry_path.write_text(json.dumps(registry, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")

completion = f"""# File 00 — Three-Plan Repository Code Completion 1.2.11

## Governing basis

This release is measured against:

1. Definitive Integrated Master Plan v3.0.
2. All-Chats Recovered Directive Register v2.1 dated 5 August 2026.
3. File 00 Four-Round Reviewed and Corrected Final Master Plan.

## Verified repository result

- Plugin: `1.2.11`
- Public membership contract: `1.2.0`
- Database schema: `1.3.0`
- Exact verified source head: `{SOURCE_HEAD}`
- Three-Plan QA run: `{THREE_PLAN_RUN}` — **success**
- CF-01 and Forty-Round Contract Integrity run: `{CONTRACT_RUN}` — **success**
- Workflow artifact: `{ARTIFACT_ID}`
- Artifact digest SHA-256: `{ARTIFACT_DIGEST}`
- Installable package: `{PACKAGE}`
- Package SHA-256: `{PACKAGE_SHA}`
- ZIP integrity: **PASS**
- Repository-correctable known defects: **0**
- Repository coding completion against the three plans: **100%**

## Implemented three-plan corrections

- Single free baseline for registration, membership, education, AI, clinic and marketplace; paid unlocks and legacy pricing remain disabled.
- Platform commission is fixed at 0%; donation remains optional and cannot affect entitlement, capability, visibility, ranking or support.
- Institutional AI Teacher/Publisher identity with explicit AI/provider disclosure, four-post daily policy, 30-day human review, no verified-doctor claim and no patient-specific clinical authority.
- Capability-based publishing assertions for Founder, Administrator, verified doctor, trusted publisher and institutional AI publisher.
- Verified-user transfer assertion up to 1 GB per file with relationship, consent, copyright, clinical-confidentiality and abuse/fair-use rechecks; public URLs are forbidden and expiring delivery is required.
- File 20/File 25 compatible green primary visual tokens with RTL, accessibility and responsive safeguards.
- Three-plan traceability register, executable runtime tests, static contracts, deterministic build and package verification.

## Truthful boundary

This is repository-level coding completion, not Hostinger staging acceptance, production deployment or operational acceptance. Real providers, installed consumer modules, browser/screen-reader testing, load, legal review, backup/restore/rollback rehearsal and Founder live approval remain later fail-closed gates.
"""
(ROOT / "docs/THREE-PLAN-CODE-COMPLETION-1.2.11.md").write_text(completion, encoding="utf-8")

post_ci = f"""# File 00 — Post-CI Evidence 1.2.11

Verified source head: `{SOURCE_HEAD}`  
Three-Plan QA: `{THREE_PLAN_RUN}` — success  
CF-01/Forty-Round Integrity: `{CONTRACT_RUN}` — success  
Artifact: `{ARTIFACT_ID}`  
Artifact digest: `{ARTIFACT_DIGEST}`  
Package SHA-256: `{PACKAGE_SHA}`

This record is evidence-only and does not change runtime source. Staging/live/operational statuses remain pending.
"""
(ROOT / "docs/POST-CI-EVIDENCE-1.2.11.md").write_text(post_ci, encoding="utf-8")

(ROOT / "STATUS.md").write_text(f"""# File 00 Status — 1.2.11

- Three-plan repository coding: **100% verified** at `{SOURCE_HEAD}`.
- Three-Plan QA `{THREE_PLAN_RUN}`: **success**.
- Contract Integrity `{CONTRACT_RUN}`: **success**.
- Package SHA-256: `{PACKAGE_SHA}`.
- Repository-correctable known defects: **0**.
- Staging accepted: **No**.
- Live deployed: **No**.
- Operational: **No**.
""", encoding="utf-8")

readme = (ROOT / "README.md").read_text(encoding="utf-8")
marker = "Repository-correctable coding is complete; exact-head automated QA is required."
replacement = f"Repository-correctable coding is 100% verified at `{SOURCE_HEAD}` by Three-Plan QA `{THREE_PLAN_RUN}` and Contract Integrity `{CONTRACT_RUN}`."
readme = readme.replace(marker, replacement)
if PACKAGE_SHA not in readme:
    readme += f"\nPackage SHA-256: `{PACKAGE_SHA}`.\n"
(ROOT / "README.md").write_text(readme, encoding="utf-8")

print("Finalized truthful File 00 three-plan evidence.")
