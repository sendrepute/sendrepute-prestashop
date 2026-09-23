> **Standalone source distribution:** this repository contains the integration runtime, documentation, and source packager. Upstream workspace/CMS/production-normalizer regression suites are deliberately not distributed here because they depend on private server code or isolated platform fixtures. Testing commands and historical verification evidence below describe upstream maintainer validation, not a self-contained test suite in this source-only checkout. No third-party registry publication is implied.

# SendRepute for PrestaShop

Install `dist/sendrepute-prestashop-0.1.0.zip` in **Modules > Module Manager > Upload a module**, then configure **SendRepute mail classification**.

## Compatibility and real mail contract

Version 0.1.0 intentionally supports PrestaShop **9.0.0, 9.0.1, 9.0.2, and 9.0.3 only** (PHP 8.1 through 8.4). PrestaShop 8 uses SwiftMailer and is not supported. Any other patch must be added to this exact tested list before it is claimed as supported.

The early `actionEmailSendBefore` hook contains unrendered variables. This module uses it only to match an exact opted-in template. Classification occurs at `actionMailAlterMessageBeforeSend`, where PrestaShop 9 has already substituted variables into a Symfony `Email` and installed subject, text, HTML, recipients, sender, and attachments. The original message object is never rebuilt, so recipients, headers, attachments, embedded parts, and provider/transport state remain intact.

The final hook has no cancellation return contract. Advisory mode therefore never claims to prevent sending. In block/fail-closed mode, the module throws an exception implementing both Symfony Mailer's `ExceptionInterface` and PrestaShop's `ModuleErrorInterface`. The latter prevents `Hook::callHookOn()` from swallowing or wrapping it; the former reaches the audited PrestaShop 9.0.x `Mail::send()` catch, which returns false before transport. This is source-grounded behavior, not a hook-return cancellation API, and compatibility must be re-audited outside the exact versions above.

## Configure safely

Set secrets in the server/PHP-FPM environment (never JavaScript or theme code):

```text
SENDREPUTE_API_BASE=https://api.example.invalid
SENDREPUTE_API_TOKEN=your-server-token
```

Use the canonical production API HTTPS origin. A literal IP, credentials, path, query, fragment, non-public DNS answer, redirect, invalid TLS certificate, or non-HTTPS URL is refused. DNS is resolved and pinned for the request. Requests have finite timeouts, one attempt, and no redirects/retries. A cURL write callback stops the transfer as soon as the response would exceed 256 KiB; it is not buffered unboundedly first.

The module starts disabled, consent starts off, and no templates are opted in. Enter exact template names only after reviewing their data. Password/reset, order confirmation, payment, and similar delivery-critical templates are deliberately not selected. Risk policy (`advisory` or threshold block) and API failure policy (`open` or `closed`) are independent.

The configuration page uses PrestaShop's documented legacy `HelperForm` fields and `generateForm()` helper. Because PrestaShop 9's Symfony bridge rotates its route token while redirecting a legacy `HelperForm` POST, the module generates a dedicated random configuration token in PrestaShop's signed/encrypted admin cookie and compares it in constant time. The offline legacy harness falls back to `Tools::getAdminTokenLite('AdminModules')`. No invented `Module::isTokenValid()` API is used.

“Check connection” performs only `/v1/account` and `/v1/pricing` calls and strictly validates pricing integers. It does not probe the paid classify permission. Each opted-in send can incur a classification charge. The API remains authoritative for scopes, billing, and spending limits.

Dual plain+HTML messages use the classifier's typed `displayedAlternatives` envelope. Both displayed bodies are submitted together in one bounded request and the service normalizes each fragment independently, returns the maximum risk, and unions audit findings in one charged receipt. The required legacy `body` exactly repeats the inert plain alternative; the aggregate limit counts this duplicate and both typed bodies. Original HTML is retained in the typed HTML part so links and visible-content audit remain complete. The original Symfony transport message is never changed. Subject, sender display name, and plain text have literal angle brackets represented as inert words while entity literals remain literal. A single legacy HTML body continues to use the deliberately narrow DOM extraction path.

The real API feature normalizer also pre-decodes quoted-printable-looking `=XX` values and soft line breaks, MIME-header base64, and whole-body base64. To keep displayed literals from changing meaning, ambiguous transfer-encoded text is rejected before payment, a displayed `Content-Transfer-Encoding: base64` phrase is neutralized in plain text, and supported plain bodies get a punctuation-only non-base64 prefix. Typed HTML permits normal templates, including style and hidden nodes, while preserving the original markup for the server's per-fragment visibility and audit logic. Tests run adapter output through the actual server `visibleEmailText` implementation, not a copied approximation. Attachments and recipients are neither transmitted for analysis nor modified. Logs contain bounded errors and result metadata, not bodies, recipients, tokens, or API responses.

## Build and test

```sh
php -l sendrepute/sendrepute.php
php -l sendrepute/classes/SendReputeClient.php
php tests/source-contract.php /path/to/PrestaShop-9.0.x
php tests/module-harness.php
composer install --working-dir=tests/real-mail --no-plugins --no-scripts
python3 tests/real-mail/run.py
../../scripts/node_modules/.bin/tsx --test tests/server-normalization.test.ts
python3 package.py
tests/run-installed.sh
```

Tests do not send external mail, contact SendRepute, or make paid calls.
The [offline mail matrix](tests/real-mail/README.md) runs unchanged upstream
`Mail::send()` from all four supported releases with real Symfony 6.4 dependencies
and an in-memory SMTP stream. Its runner disables native network/mail functions;
Composer dependency setup is separate. CI covers PHP 8.1–8.4.
The real harness owns a temporary MariaDB data directory and loopback ports,
downloads and installs each official prebuilt classic release distribution,
installs the module ZIP and uploads its update through the authenticated
PrestaShop admin module route, exercises actual admin login, CSRF
and `HelperForm` save handling, and uses an in-process classifier fixture plus
a synthetic loopback SMTP sink. Its JSONL evidence is written to
`tests/real-prestashop-9.0.x.evidence.jsonl`.
