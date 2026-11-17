# Using particle-academy/fancy-git-bitbucket — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-git-bitbucket
```

- Requires PHP `^8.4`, `particle-academy/fancy-git` (`>=0.1 <2.0`) and
  `guzzlehttp/guzzle` `^7.15.1`; Composer pulls them in.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). This package is the registry entry
`fancy-git-bitbucket-php`. Server-side packages are found with
`search-backend-packages` (stack `php`); `install-instructions` gives the exact
commands for the slug.

## API essentials

```php
use FancyGit\Bitbucket\BitbucketProvider;
use FancyGit\Provider\ProviderRegistry;
use GuzzleHttp\Client;

$bitbucket = new BitbucketProvider(new Client, $token);
$providers = (new ProviderRegistry)->register($bitbucket);
```

- Constructor: a Guzzle `ClientInterface`, a token (a string, a callable
  returning one, or `null`), and a base URL (defaults to
  `https://api.bitbucket.org/2.0`).
- `BitbucketProvider` implements fancy-git's `FancyGit\Provider\GitProvider`
  (`kind()` is `'bitbucket'`): `identify`, `repository`, `listReviews`,
  `getReview`, `createReview`, `compare`, `checks`. Results are fancy-git's
  normalized arrays.
- `identify($remote)` returns a ref only for `bitbucket.org` remotes, otherwise
  `null`.

## Traps

- **Bitbucket Cloud only.** Bitbucket Data Center is a separate future adapter
  and is not supported here.
- No issue methods: this adapter does not implement fancy-git's
  `IssueProvider`.
