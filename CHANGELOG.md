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
- **tusd hook endpoint** accepted requests from any private address, i.e. from any visitor when Erugo
  runs behind a reverse proxy: it is now only served on an internal loopback listener and only accepts
  loopback connections.
- **Security headers**: Content-Security-Policy, `Referrer-Policy: no-referrer` (share links never leak
  to other sites), Permissions-Policy, Cross-Origin-Opener-Policy; `Server`/`X-Powered-By` removed.
  HSTS no longer sets `includeSubDomains; preload`, which forced HTTPS on every host of the domain.
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
- **FrankenPHP** (official `dunglas/frankenphp` Alpine image) with **PHP 8.5**, instead of Caddy +
  php-fpm on Alpine 3.19 (end of life) and PHP 8.3 upstream.
- **Unprivileged processes**: the server, tusd, the queue worker and the scheduler run as `erugo`
  (uid 1000). Root is only used at start to fix the ownership of `storage`, and is not needed at all
  with `user: "1000:1000"`. The application code is owned by root and read-only for `erugo`.
- The container can run with `read_only: true` (plus a `/tmp` tmpfs), `cap_drop: [ALL]` and
  `no-new-privileges`. New `ERUGO_PORT` variable (default `80`). See *Hardened deployment* in the README.
- The scheduler runs `schedule:work` instead of a root cron daemon; `sudo` and `cronie` removed.
  Docker `HEALTHCHECK` on `/api/health`.
- Production dependencies only; unused `imap` and `mongodb` extensions removed; Composer from its
  official image; tusd checked against pinned SHA-256; Node 22 for the asset build.
- **Supply chain**: images signed with cosign (keyless, Sigstore), SBOM (SPDX) and SLSA provenance
  attached; amd64 and arm64 built on native runners; GitHub Actions pinned to commit hashes.
- Every image scanned with Trivy (fails on fixable CRITICAL vulnerabilities), on pull requests too.

### Upgrading from `wardy784/erugo`
Back up the `storage` folder, replace the image with `ghcr.io/moutonnoireu/erugo-security:0.2.15-sec.1`
(or `latest`) and restart. The database is migrated automatically; existing data and settings are kept.
Same volume, same port (`80`); files in `storage` not owned by uid 1000 are fixed at start. If you set
`user:` yourself, use `1000:1000`. Run commands as `docker compose exec -u erugo app php artisan …`.
