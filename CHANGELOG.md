# Changelog

All notable changes to `particle-academy/fancy-git-bitbucket` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0:** breaking changes may land in MINOR releases.

## [Unreleased]

## [0.3.0] — 2026-11-19

### Changed

- Rebranded the display name to Fancy Dev Kit (README and package description). Package names, the npm scope, URLs and the `fancy-ui` MCP id are unchanged.
- **Docs:** `AGENTS.md` added: the guide to using this package (install, its entries on the Fancy MCP catalog server, API essentials, traps). `CONTRIBUTING.md` added for the contributor notes, with `CLAUDE.md` as a symlink to it. The README points to the MCP server and to `AGENTS.md`. No code changed; nothing to do.
- CI: re-pin org third-party allowlist to 2614224
- Tests moved to Pest (`^5.0`); `phpunit/phpunit` removed from require-dev (Pest brings it transitively). No runtime change.

### Fixed

- **`listReviews()`'s `nextCursor` is the page number.** It was the whole query
  string of Bitbucket's `next` URL (`state=OPEN&page=2&pagelen=2`), which, fed
  back as `cursor`, was sent as `page=state%3DOPEN...` and ended a walk early.
  It is now the `page` value, as the TypeScript twin returns it. If you stored
  cursors from earlier releases, start the walk again.
- **Bitbucket's failures are `GitException`s with the fancy-git code.** They
  were Guzzle exceptions with no code. Now 401/403 `auth`, 404 `not_found`,
  400/422 `invalid_argument`, 405 `unsupported`, 409 `conflict`, 429
  `rate_limited`, otherwise `unknown`, with the HTTP status as the exception
  code and Bitbucket's own message in the text; a transport failure is
  `unknown`. **If you caught `GuzzleHttp\Exception\ClientException` or
  `ServerException` from this adapter, catch `FancyGit\Error\GitException`
  instead.**
- **A GET that answers 5xx is retried, at most twice** (100 ms then 200 ms
  apart). A POST is never replayed. Found, with the two above, by the
  fancy-labs git-adapter test.

## [0.2.0] — 2026-08-07

### Changed

- **BREAKING — PHP 8.3 is no longer supported.** `require.php` moves from `^8.3` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.3, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.


### Changed

- Widened the `particle-academy/fancy-git` requirement from `^0.1.0` to `>=0.1 <2.0`, so a sibling
  minor release is an upgrade and not a resolver conflict. **No action needed** —
  widening a range only adds candidates; the version you have today still resolves.

  A caret on a `0.x` range locks the MINOR, so every one of these pinned a
  sibling at whatever it happened to be on the day it was written, and each
  sibling release then read as a conflict to Composer/npm rather than an
  upgrade. Nothing in this package was using an API the newer minors removed
  — the range was the whole problem.

## [0.1.1] - 2026-07-23

### Security

- Raised the `guzzlehttp/guzzle` floor from `^7.9` to `^7.15.1` and refreshed the
  lockfile to 7.15.1. This closes four Guzzle advisories that `^7.9` still
  allowed (Proxy-Authorization leaking to origin on cross-host redirect,
  host-only cookie scope not preserved, unbounded response cookies DoS, and URI
  fragments disclosed in redirect `Referer` headers).

  **Action:** run `composer update guzzlehttp/guzzle` to pull 7.15.1. The raised
  floor means `composer update` can no longer resolve a vulnerable Guzzle for
  consumers of this package.
