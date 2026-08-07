# File 00 — 1.2.12 Latest-Central Corrective Release

This patch reconciles repository-owned File 00 behavior with the 6–7 August 2026 governing corpus without taking ownership from Files 02, 09, 24, 25 or 26.

## Corrective changes

- Exact single-free-tier and donor-neutral constitution remains fail-closed; no paid entitlement path is introduced.
- Exact File 00 fallback brand is Sabri Green `#087A4E`; File 25 remains global design-token owner.
- File 26 receives a privacy-minimal versioned membership projection only. File 00 creates no search/ranking index or query backend.
- File 09 is the canonical doctor-verification claim owner. The stale local `_spd_verification_status` fallback is removed.
- Membership security-state audit changes advance a per-user revalidation cutoff. A pre-change two-factor assertion is no longer current; existing hard restriction/contact/guardian paths continue to revoke sessions outright.
- Current traceability explicitly maps F00-CEN-01/02/03, File 26, current CV requirements and relevant acceptance journeys.

## Evidence boundary

Repository source, automated tests, deterministic package and exact-head CI are repository-correctable evidence. Hostinger staging, provider delivery, real browser/assistive-tech, restore/load drills, live deployment and operational monitoring remain separate gates.
