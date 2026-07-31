# Source Provenance

| Field | Value |
|---|---|
| File number | `00` |
| Module | `Sabri Membership Core` |
| Original archive | `00 sabri-membership-core-1.0.1.zip` |
| Archive SHA-256 | `1418dff3410ebd66f6d440453f4bc4fe487828920d8fdaf8190df42844d426af` |
| Archive size | `59,278` bytes |
| Archive entries | `24` |
| Source files inside archive | `20` |
| Extracted source size | `196,001` bytes |
| Declared plugin version | `1.0.1` |
| Baseline branch | `baseline/file-00-original-import` |
| Import date | `2026-07-29` |

## Initial controls

- ZIP integrity test passed locally.
- All nine PHP files passed local syntax lint.
- No embedded real password, API key, private key, access token, Hostinger credential, or patient record was found by the initial indicator scan.
- The README contains a placeholder example for `SMC_MASTER_KEY`; no production key is present.

## Limitation

This archive upload proves source custody only. It does not establish security, privacy, runtime, architecture, staging, or production approval.

## Baseline restoration and corrective lineage

The initial GitHub checkout copy of the archive was 15,008 bytes and failed ZIP integrity despite the repository recording the correct 59,278-byte checksum. On the controlled repair branch it was replaced by the verified attached original:

- Restored size: `59,278` bytes
- Restored SHA-256: `1418dff3410ebd66f6d440453f4bc4fe487828920d8fdaf8190df42844d426af`
- ZIP integrity: passed

Release `1.2.0` is rebuilt from the restored baseline as editable source. It does not overwrite or mislabel the preserved `1.0.1` evidence.
