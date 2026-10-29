# Security patches

This fork tracks [ErugoOSS/Erugo](https://github.com/ErugoOSS/Erugo) (current base: see [`.upstream-version`](.upstream-version))
and adds the fixes below on top of it. Most were proposed upstream by community contributors and never merged.
Commit hashes refer to the `php-fix` branch.

## Application fixes

### Authentication & accounts
| Commit | Fix | Author |
|---|---|---|
| `18691ab` | Reject invalid credentials and refuse login for disabled accounts | kali2 |
| `0439373` | Rate limiting on authentication endpoints | kali2 |
| `baf2193` | Remove privileged fields from the `User` mass-assignment allowlist | kali2 |
| `1a07d28` | Extend that protection to every user-creation path | kali2 |
| `f2b518e` | Fix `admin` / `must_change_password` being silently dropped on update | kali2 |

### Access control
| Commit | Fix | Author |
|---|---|---|
| `f81db83` | Restrict the `system.smtp` settings group to admins | kali2 |
| `3a52370` | Guard admin-only settings tabs against stale URL hash navigation | kali2 |
| `58765d8` | Single-file download on password-protected shares (reworked from upstream #188) | moutonnoireu |

### Path traversal & file handling
| Commit | Fix | Author |
|---|---|---|
| `50c3722` | `realpath` boundary check in `SharesController::downloadFile` | kali2 |
| `706995f` | Tighten that check with `DIRECTORY_SEPARATOR` | kali2 |
| `f3d1ee9` | Replace shell `zip` execution with PHP `ZipArchive` | kali2 |
| `c3f72c1` | Verify background-upload MIME type from magic bytes | kali2 |
| `f109ee6` | Validate theme file content, 512 KB size limit | kali2 |

### XSS & injection
| Commit | Fix | Author |
|---|---|---|
| `8f8f255` | Sanitize markdown output and SVG provider icons | kali2 |
| `abd4a4c` | Sanitize `provider.icon` SVG before `v-html` render | kali2 |
| `8dbcfee` | Replace `v-html` with text interpolation in `fileInput.vue` | kali2 |
| `0e14bf1` | Sanitize CSS color values (CSS injection) | kali2 |

### Information disclosure & resource limits
| Commit | Fix | Author |
|---|---|---|
| `7a39444` | Strip exception details from HTTP error responses | kali2 |
| `f443830` | Same for `BackupsController` and `TusdHooksController` | kali2 |
| `00bf471` | Clamp the stats `days` parameter (unbounded DB queries) | kali2 |
| `eab5a12` | `request_terminate_timeout` in the FPM pool, read/write timeouts on `php_fastcgi` | SIMVIA-lucas-sovre |

## Image & dependencies
| Commit | Change |
|---|---|
| `6f6f442` | Base image `alpine:3.19` (end of life) → `alpine:3.24`, PHP 8.3.36 (CVE-2026-109618, CVE-2026-109619) |
| `84e8951` | tusd 2.10.1, twig/twig 3.30.0 (Trivy CRITICAL: grpc, Go stdlib, twig) |
| `39aa964`, `e0de951`, `fb4e386` | Composer and npm dependency updates (guzzle, phpseclib, commonmark, vite, vue…) |
| `a38f990` | Dependabot, Trivy scan in CI, weekly upstream sync |

Every image is scanned with Trivy before publishing (build fails on fixable CRITICAL vulnerabilities)
and rebuilt from scratch every week.
