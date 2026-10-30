# Changelog

Versions are `<upstream Erugo version>-sec.<n>`: `0.2.15-sec.1` is the first security release of this fork
based on Erugo 0.2.15. Image tags: `X.Y.Z-sec.N` (fixed), `X.Y.Z` (latest fork release for that upstream
version), `latest` (latest release), `edge` (current `php-fix` branch, not a release).

Every release image is rebuilt weekly from scratch to pick up Alpine and PHP security updates.

## [0.2.15-sec.1] - 2026-10-30

First release of the fork, based on Erugo 0.2.15.

### Security fixes
- **SMTP credentials readable by any signed-in user**, including reverse-share guests: admin-only
  setting groups are now refused and filtered out of wildcard reads.
- **Share password guessing** was unlimited: failed attempts are limited to 10 per share and IP per 10 minutes.
- 20+ community security fixes from upstream pull requests (authentication, path traversal, XSS,
  mass assignment, information disclosure), see [SECURITY-PATCHES.md](SECURITY-PATCHES.md).
- Laravel 12 (CVE-2026-114509 in Laravel 11), firebase/php-jwt 7, web-auth/webauthn-lib 5.3,
  symfony/yaml, psy/psysh and other dependency advisories.

### New, optional security features (off by default)
- Sign-in IP allowlist (IPv4/IPv6, CIDR), with `TRUSTED_PROXIES` support and a recovery command.
- Self-hosted ALTCHA proof-of-work on sign-in, password reset and registration forms.
- Two-factor authentication: authenticator app (TOTP), passkeys, recovery codes; admin reset.
- Admin policy requiring two-factor authentication for administrators or every user.
- Passwordless sign-in with a passkey.
- Encryption at rest of shared files (XChaCha20-Poly1305, key outside the storage volume).

### Image
- Alpine 3.24 (upstream: 3.19, end of life) and **PHP 8.5**.
- Production dependencies only; unused `imap` and `mongodb` extensions removed; Composer from its
  official image; tusd checked against pinned SHA-256; Node 22 for the asset build.
- Every image scanned with Trivy (fails on fixable CRITICAL vulnerabilities).

### Upgrading from `wardy784/erugo`
Back up the `storage` folder, replace the image with `ghcr.io/moutonnoireu/erugo-security:0.2.15-sec.1`
(or `latest`) and restart. The database is migrated automatically; existing data and settings are kept.
