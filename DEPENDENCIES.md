# Dependency Inventory

## Runtime

File 00 bundles no third-party PHP or JavaScript library. It uses WordPress core APIs, OpenSSL AES-256-GCM, the configured filesystem, and MySQL advisory locks.

## Development only

| Package | Purpose | Distribution |
|---|---|---|
| `php-parser` | Parse every PHP source file under the declared PHP 7.4 grammar | Not included in the WordPress ZIP |

The deterministic builder uses Python's standard-library `zipfile` module. No Python package is included in the release.
