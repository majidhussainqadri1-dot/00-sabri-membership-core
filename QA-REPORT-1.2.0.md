# File 00 — Sabri Membership Core 1.2.0 QA Report

## Scope

This report covers the local GitHub repair branch, reconstructed source, deterministic package, and the 181-finding baseline defect register. It does not claim Hostinger staging or production acceptance.

## Corrective result

The 181 baseline findings are mapped exactly once to implemented control groups in `qa/defect-map.json`. The source now separates canonical module ownership and adds enforceable controls for policy, eligibility, guardian consent, contact ownership, two-factor sessions, identity evidence, migrations, reviewer governance, privacy, lifecycle, accessibility, and packaging.

## Fresh automated cycle

| Check | Result |
|---|---:|
| Automated assertions | **561/561 passed** |
| Failed assertions | **0** |
| PHP files parsed under PHP 7 grammar | **10/10** |
| JavaScript syntax | **1/1** |
| Baseline findings with closure mapping | **181/181** |
| Source manifest mismatches | **0** |
| Forbidden 10 percent commission | **0** |
| Incorrect Founder spellings | **0** |
| Direct `wp_mail()` calls | **0** |
| Direct authentication-cookie creation | **0** |
| Dangerous runtime primitives | **0** |
| Duplicate File 03 profile owner | **absent** |
| Duplicate Files 04/06 knowledge owner | **absent** |
| Duplicate File 20 standalone shell | **absent** |
| WCAG AA tested color failures | **0** |

## Package cycle

| Check | Result |
|---|---:|
| Deterministic double build | **byte-identical** |
| ZIP entries | **14** |
| PHP files | **10** |
| JavaScript files | **1** |
| CSS files | **1** |
| ZIP size | **51,337 bytes** |
| ZIP CRC failures | **0** |
| Unsafe/path-traversal entries | **0** |
| Symlink entries | **0** |
| Runtime temp/backup remnants | **0** |
| Embedded manifest mismatches | **0** |
| Clean-extract assertions | **561/561 passed** |

## Checksums

```text
Baseline 1.0.1:
1418dff3410ebd66f6d440453f4bc4fe487828920d8fdaf8190df42844d426af

Corrective release 1.2.0:
f6a5f531be977b8f824852499c5d9fa0738791aab7b9b3354a934be7cfb88436

Clean source:
931512c0a5ed128c29006f35dd352fea9bfde67252f6e7c0f18055f144d548f0
```

## Correct status

> Local Corrective Completion and Deterministic Development Candidate — GitHub Review and Hostinger Staging Acceptance Pending

The remaining staging tests are listed in `STATUS.md` and `SECURITY.md`. No live installation is authorized by this local report.

**Date:** July 31, 2026

**Day:** Friday

**Time:** 8:42 AM Pakistan Standard Time (Asia/Karachi)
