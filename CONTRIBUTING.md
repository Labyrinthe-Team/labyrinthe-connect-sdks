# Contributing

Thanks for helping! Issues and pull requests go to this monorepo. The `labyrinthe-connect-php` and
`labyrinthe-connect-laravel` repositories are read-only mirrors: changes pushed there are overwritten.

## Working on a package

```bash
cd php && composer install && composer check        # lint + PHPStan + tests
cd laravel && composer install && composer check    # uses ../php through a Composer "path" repository
cd node && npm ci && npm run check                  # lint + types + tests + build + publint + attw
cd postman && npm ci && npm run check               # collection up to date with the spec (npm run generate to fix)
cd widget-otp && npm ci && npm run check            # lint + types + tests + build + size budget + publint + attw
cd python && pip install -r requirements-dev.txt -e . && ruff check . && ruff format --check . \
  && mypy && pytest && python -m build && twine check --strict dist/*   # lint + mypy --strict + tests + build
cd dotnet && dotnet format --verify-no-changes && dotnet build -c Release -warnaserror && dotnet test -c Release \
  && dotnet pack src/Labyrinthe.Connect -c Release -o artifacts   # .NET SDK 8+ (global.json rolls forward)
cd java && ./mvnw verify                            # JDK 11+ (starter + Spotless on 17+): -Xlint:all -Werror, tests, SpotBugs, Javadoc
cd wordpress && composer install && php bin/install-wp-tests.php && composer check && composer build \
  && php bin/smoke-test.php   # PHP 8.2+ to build (8.1+ to run), MySQL: WPCS + PHPStan + WordPress test suite + zip
```

Every package has a CI workflow triggered by path filters (`.github/workflows/<package>.yml`). Before opening a
pull request: tests green, static analysis clean, `composer validate --strict` (PHP), `publint` / `attw` (npm) or `twine check --strict` (PyPI), CHANGELOG updated under
`[Unreleased]`. Public code and docblocks are in English; READMEs exist in English and French.

## Changing the API: spec first, then SDKs

1. The backend changes first. Then update `openapi/openapi.yaml` and bump `info.version` (SemVer).
2. `cd openapi && npm ci && npm run lint && npm run check:routes`, then the contract test against a local backend
   (`LABYRINTHE_API_KEY=lsk_test_… npm run test:contract`).
3. `cd postman && npm run generate` (commit the regenerated collection: `postman.yml` checks it). The HTML docs are
   rebuilt and deployed to GitHub Pages by `docs.yml` on every push to `main` touching `openapi/**`.
4. Update each SDK: types, enums, error mapping, docs. The SDK contract tests (e.g.
   `php/tests/Contract/OpenApiContractTest.php`) fail when enums, limits or operations drift from the spec.
5. An API limit that makes integration harder is written down in `BACKEND-TODO.md` with a proposal — it is never
   worked around in an SDK.

## Releasing

Tags are per package and SemVer:

| Tag in this repository | Effect |
|---|---|
| `php-v1.2.3` | `php/` is pushed to `labyrinthe-connect-php` with the tag `v1.2.3` → Packagist `labyrinthe/connect-php` 1.2.3 |
| `laravel-v1.2.3` | `laravel/` is pushed to `labyrinthe-connect-laravel` with the tag `v1.2.3` |
| `node-v1.2.3` | `node/` is published to npm as `@labyrinthe/connect` 1.2.3 (`node-release.yml`; a prerelease such as `node-v1.3.0-beta.1` gets the `next` dist-tag) |
| `widget-otp-v1.2.3` | `widget-otp/` is published to npm as `@labyrinthe/otp-widget` 1.2.3 (`widget-otp-release.yml`, same `NPM_TOKEN` secret and `npm` environment as `node-release.yml`) |
| `python-v1.2.3` | `python/` is published to PyPI as `labyrinthe-connect` 1.2.3 (`python-release.yml`, Trusted Publishing; prereleases use PEP 440: `python-v1.3.0rc1`) |
| `dotnet-v1.2.3` | `dotnet/` is published to NuGet as `Labyrinthe.Connect` and `Labyrinthe.Connect.AspNetCore` 1.2.3, with symbol packages (`dotnet-release.yml`, NuGet Trusted Publishing or `NUGET_API_KEY`) |
| `java-v1.2.3` | `java/` is published to Maven Central as `cd.labyrinthe:labyrinthe-connect` and `cd.labyrinthe:labyrinthe-connect-spring-boot-starter` 1.2.3, signed, with sources and Javadoc (`java-release.yml`) |
| `wordpress-v1.2.3` | `wordpress/` is built into `labyrinthe-connect.zip` (GitHub release) and deployed to the wordpress.org SVN (`trunk/` + `tags/1.2.3/`) as the `labyrinthe-connect` plugin (`wordpress-release.yml`, after Plugin Check) |

1. Move `[Unreleased]` to the new version in the package CHANGELOG; update the version constant
   (`php/src/Version.php`, `LabyrintheServiceProvider::VERSION`, `node/package.json` + `node/src/version.ts`, `widget-otp/package.json` + `widget-otp/src/version.ts`, `python/pyproject.toml` + `python/src/labyrinthe_connect/_version.py`, `dotnet/Directory.Build.props` + `LabyrintheClient.Version`, the `<version>` of `java/pom.xml` and of each `java/*/pom.xml` — `Version.VERSION` is read from the pom).
2. When the PHP SDK version changes, update the `versions` mapping of the path repository in
   `laravel/composer.json` (and the `labyrinthe/connect-php` constraint if the Laravel package needs new features).
3. Merge to `main`, then `git tag php-v1.2.3 && git push origin php-v1.2.3`.

`split.yml` needs the `MONOREPO_SPLIT_TOKEN` secret (a fine-grained token with *Contents: read and write* on both
mirrors). Each mirror is registered once on Packagist, with the GitHub hook enabled.

`node-release.yml` needs the `NPM_TOKEN` secret: an npm *Automation* (or granular, publish-only) token of a
member of the `@labyrinthe` npm organisation, stored in the `npm` environment of this repository. The package is
published with `npm publish --provenance --access public` (provenance requires a public repository).

`python-release.yml` needs no secret: it publishes with PyPI Trusted Publishing (OIDC). Register it once as a
trusted publisher of the `labyrinthe-connect` project on PyPI (owner `Labyrinthe-Team`, repository
`labyrinthe-connect-sdks`, workflow `python-release.yml`, environment `pypi`) and create the `pypi` environment in
this repository (required reviewers recommended). Pinned dev tools live in `python/requirements-dev.txt` (universal
lock, regenerated from `requirements-dev.in` with `uv pip compile --universal`).

`dotnet-release.yml` publishes with NuGet Trusted Publishing (OIDC, `NuGet/login`): add a trusted publishing policy
on nuget.org (repository `Labyrinthe-Team/labyrinthe-connect-sdks`, workflow `dotnet-release.yml`, environment
`nuget`), set the repository variable `NUGET_USER` to the owning nuget.org profile and create the `nuget`
environment. Fallback: a push-only `NUGET_API_KEY` secret in that environment (used when present). Reserve the
`Labyrinthe` ID prefix on nuget.org before the first release.

`java-release.yml` publishes with the Sonatype `central-publishing-maven-plugin` (profile `release` of
`java/pom.xml`). Verify the `cd.labyrinthe` namespace once on central.sonatype.com, then add to the `maven-central`
environment of this repository: `MAVEN_CENTRAL_USERNAME` / `MAVEN_CENTRAL_PASSWORD` (a Central Portal *user token*),
`GPG_PRIVATE_KEY` (armored private key, public key on keys.openpgp.org) and `GPG_PASSPHRASE`.

`wordpress-release.yml` deploys with `10up/action-wordpress-plugin-deploy`. Once the plugin is accepted on
wordpress.org (slug `labyrinthe-connect`), create the `wordpress-org` environment with the secrets `SVN_USERNAME`
(wordpress.org username of a committer of the plugin) and `SVN_PASSWORD` (the *SVN password* generated in the
wordpress.org profile, not the account password). Banners, icons and screenshots go in `wordpress/.wordpress-org/`.
Before tagging, set the same version in the plugin header and `LABYRINTHE_CONNECT_VERSION`
(`wordpress/labyrinthe-connect.php`), `Stable tag` (`wordpress/readme.txt`) and `wordpress/blocks/otp-login/block.json`
(the build fails otherwise), and update the readme changelog. When the PHP SDK version changes, update the
`versions` mapping of the path repository in `wordpress/composer.json` too.

## Adding a package

1. Create `<name>/` with `README.md` + `README.fr.md`, `CHANGELOG.md`, `LICENSE` (MIT), `src/`, `tests/`
   (mocked HTTP + contract tests against `openapi/`), `examples/`.
2. Follow the shared API and rules of [`PROMPT.md`](PROMPT.md) (idempotency, safe retries, typed errors, webhook
   verification, secret masking, test mode).
3. Add `.github/workflows/<name>.yml` with path filters and the supported version matrix; add a mirror to
   `split.yml` if the registry needs one.
4. Add the package to the table of the root `README.md`.
