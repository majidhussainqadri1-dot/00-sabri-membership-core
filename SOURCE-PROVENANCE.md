# Source Provenance — File 00

The current corrective branch derives from audited baseline `3a84c32a6ddad151f2ed09d244fa8aa536a58108`, the merged v1.2.32 head `116f2d45bf3c67889b1d5fa08aa4e225ecb21b8e`, the verified 32-finding GitHub code-only corrective line, and subsequent Hostinger live-test evidence. Runtime identity is `1.2.33`, database schema `1.4.4`, public contract `1.2.1`.

The Hostinger testing sequence exposed activation, action transport, origin/nonce, TOTP diagnostics, audit schema bootstrap, missing-tail recovery, and historical audit-key transition paths that were not fully exercised by the original fresh-install CI environment. These fixes are preserved in repository source rather than only in local test ZIPs.

The v1.2.33 correction specifically implements the historical-key verifier that v1.2.32 documentation described but its single-key runtime did not fully realize. It remains subject to final live confirmation against the site's existing audit record 16. Source provenance and repository merge must not be represented as live production acceptance.
