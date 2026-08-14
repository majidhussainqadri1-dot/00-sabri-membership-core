# File 00 — Sabri Membership Core

Current repository corrective candidate: runtime `1.2.44`, public membership contract `1.2.3`, database schema `1.4.5`, `smc.authentication-account` `1.1.0`.

The 1.2.44 candidate binds the active authentication-account provider directly to File 00's canonical account taxonomy and exact-pins the corrected File 02 1.2.4 source candidate for cross-repository compatibility testing. It preserves Founder-approved File 00 MFA retirement and introduces no new DB schema or public membership-contract shape.

```bash
npm ci --ignore-scripts
npm run verify
```

Repository tests/package/CI are source evidence only. Staging, deployed and operational states require separate exact evidence; GitHub success must not be treated as live resolution.
