# Labyrinthe Connect SDKs

Official integration packages for [Labyrinthe Connect](https://labyrinthe-rdc.com), the SMS and OTP platform for
the DR Congo and Central Africa. Send an SMS or verify a phone number in less than 10 minutes, whatever your stack.

*Les README de chaque package existent aussi en français (`README.fr.md`).*

## Packages

| Folder | Package | Registry | Status |
|---|---|---|---|
| [`php/`](php) | `labyrinthe/connect-php` | [Packagist](https://packagist.org/packages/labyrinthe/connect-php) | 1.2.0 — stable |
| [`laravel/`](laravel) | `labyrinthe/connect-laravel` | [Packagist](https://packagist.org/packages/labyrinthe/connect-laravel) | 1.2.0 — stable |
| [`openapi/`](openapi) | OpenAPI 3.1 specification | — | 1.3.0 — source of truth |
| [`postman/`](postman) | Postman v2.1 collection + environments | — | 1.3.0 — generated from the spec |
| [`node/`](node) | `@labyrinthe/connect` | [npm](https://www.npmjs.com/package/@labyrinthe/connect) | 1.2.0 — stable |
| [`python/`](python) | `labyrinthe-connect` | [PyPI](https://pypi.org/project/labyrinthe-connect/) | 1.2.0 — stable |
| [`widget-otp/`](widget-otp) | `@labyrinthe/otp-widget` (`<labyrinthe-otp>` + React + server handlers) | [npm](https://www.npmjs.com/package/@labyrinthe/otp-widget) | 1.2.0 — stable |
| [`dotnet/`](dotnet) | `Labyrinthe.Connect` + `Labyrinthe.Connect.AspNetCore` | [NuGet](https://www.nuget.org/packages/Labyrinthe.Connect) | 1.2.0 — stable |
| [`java/`](java) | `cd.labyrinthe:labyrinthe-connect` + `labyrinthe-connect-spring-boot-starter` | [Maven Central](https://central.sonatype.com/artifact/cd.labyrinthe/labyrinthe-connect) | 1.2.0 — stable |
| [`wordpress/`](wordpress) | WordPress / WooCommerce plugin `labyrinthe-connect` (order SMS, OTP verification and login) | [wordpress.org](https://wordpress.org/plugins/labyrinthe-connect/) | 1.2.0 (SDK PHP 1.2.0, API 1.3.0) — ready, not yet on wordpress.org |

The npm, PyPI, NuGet and Maven Central packages are published directly from this repository (tags `node-vX.Y.Z`, `widget-otp-vX.Y.Z`, `python-vX.Y.Z`, `dotnet-vX.Y.Z`, `java-vX.Y.Z`). PHP packages are developed here and published through read-only mirrors
(`labyrinthe-connect-php`, `labyrinthe-connect-laravel`) that Packagist reads. Open issues and pull requests in
this repository.

## Five-line start (PHP)

```php
$client = new Labyrinthe\Connect\Client('lsk_test_…');   // test key: nothing is sent, nothing is billed
$message = $client->sms->send(to: '0812345678', body: 'Hello from Labyrinthe Connect!');
echo $message->status->value;                             // "queued"
```

## Five-line start (Node.js / TypeScript)

```ts
import { Labyrinthe } from '@labyrinthe/connect';

const client = new Labyrinthe({ apiKey: 'lsk_test_…' });   // test key: nothing is sent, nothing is billed
const message = await client.sms.send({ to: '0812345678', body: 'Hello from Labyrinthe Connect!' });
console.log(message.status);                                  // "queued"
```

## Five-line start (Python)

```python
from labyrinthe_connect import Labyrinthe

client = Labyrinthe(api_key="lsk_test_…")                  # test key: nothing is sent, nothing is billed
message = client.sms.send(to="0812345678", body="Hello from Labyrinthe Connect!")
print(message.status)                                       # "queued"
```

## Five-line start (.NET)

```csharp
using var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_…" }); // test key: nothing is sent
var message = await client.Sms.SendAsync("0812345678", "Hello from Labyrinthe Connect!");
Console.WriteLine(message.Status);                                                              // Queued
```

## Five-line start (Java)

```java
LabyrintheClient client = LabyrintheClient.builder().apiKey("lsk_test_…").build(); // test key: nothing is sent
Message message = client.sms().send("0812345678", "Hello from Labyrinthe Connect!");
System.out.println(message.status());                                              // queued
```

## Principles shared by every SDK

- **The API contract is [`openapi/openapi.yaml`](openapi/openapi.yaml).** SDK types, errors, headers and webhook
  events follow it exactly; contract tests keep them aligned. Known API limits are listed in
  [`BACKEND-TODO.md`](BACKEND-TODO.md) and are never worked around in an SDK.
- **Same API in every language**: `sms.send / sendMany / estimate / get / list`, `balance.get`, `senderIds.list`,
  `otp.send / verify`, `Webhook.constructEvent`, with names adapted to each language.
- **Never a double SMS**: every send carries an `Idempotency-Key`; automatic retries only happen when replaying is
  safe, never for OTP after a network error or a 5xx.
- **Typed errors** with a stable `code`, the HTTP status and the error `details`. A feature shut down by the
  platform (`503 FEATURE_DISABLED` / `OTP_SERVICE_DISABLED`) is a dedicated `FeatureDisabled` error exposing
  `feature`, `blockedBy`, the admin message and `until`; it is only retried after `Retry-After`, never in a loop.
- **Secure by default**: the API key, OTP codes and full phone numbers never reach logs, dumps or exception messages;
  webhooks are verified in constant time on the raw body, with replay protection.
- **Test mode first**: `lsk_test_…` keys send nothing and bill nothing; every example uses them.
- Minimal dependencies, SemVer, a CHANGELOG per package, MIT license.

## Repository layout

```
openapi/              OpenAPI spec + lint, route check and contract scripts, HTML docs (GitHub Pages: docs.yml)
postman/              Postman collection generated from the spec (npm run generate / check, Newman)
php/                  labyrinthe/connect-php
laravel/              labyrinthe/connect-laravel (uses ../php through a Composer path repository)
node/                 @labyrinthe/connect (npm, published from this repository)
python/               labyrinthe-connect (PyPI, published from this repository, sync + asyncio)
widget-otp/           @labyrinthe/otp-widget (npm): <labyrinthe-otp> web component, React wrapper, server handlers
dotnet/               Labyrinthe.Connect + Labyrinthe.Connect.AspNetCore (NuGet, published from this repository)
java/                 cd.labyrinthe:labyrinthe-connect + Spring Boot starter (Maven Central, published from this repository)
wordpress/            labyrinthe-connect WordPress / WooCommerce plugin (bundles ../php prefixed, wordpress.org SVN)
.github/workflows/    one CI workflow per package (path filters), openapi.yml, split.yml (mirrors)
BACKEND-TODO.md       known API limits and proposals
CONTRIBUTING.md       tags, adding a package, changing the spec
```

## Support

connect@labyrinthe-rdc.com — security issues: please email rather than opening a public issue.
