# Source Provenance — File 00

The current corrective branch derives from audited baseline `3a84c32a6ddad151f2ed09d244fa8aa536a58108` and incorporates the verified 32-finding GitHub code-only corrective line plus subsequent Hostinger live-test fixes materialized through runtime `1.2.32`, database schema `1.4.3`, public contract `1.2.1`.

The Hostinger testing sequence exposed activation, action transport, origin/nonce, TOTP diagnostics, audit schema bootstrap, missing-tail recovery, and historical audit-key transition paths that were not fully exercised by the original fresh-install CI environment. These fixes are preserved in repository source rather than only in local test ZIPs.

The current `v1.2.32` historical-key transition remains subject to final live confirmation against the site's existing audit record 16. Source provenance and repository merge must not be represented as live production acceptance.
