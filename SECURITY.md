# Security

Report vulnerabilities privately to the SendRepute maintainers. Do not include live API tokens, customer mail, recipients, or production logs.

## Trust and data boundaries

Administrators use PrestaShop's authenticated module configuration controller and CSRF token. API credentials are read only from `SENDREPUTE_API_TOKEN`; the module never stores, renders, or sends them to a browser. `SENDREPUTE_API_BASE` is a server-only canonical HTTPS origin.

Automatic content disclosure and paid classification require all three controls: enabled, explicit consent, and an exact opted-in template. Defaults deny all three. Dual plain+HTML messages use one typed request whose fragments are normalized independently by the service; no fragment can consume another, and no partial score is accepted. The legacy body duplicates the inert plain fragment and is included in the aggregate 524288-byte preflight bound. Original HTML is retained for visible-content and link auditing. Unsupported sources, ambiguous transfer encoding, or oversized content follows the explicit failure policy before any paid request. Recipients, attachments, embedded resources, and message/provider objects remain untouched.

Because API feature extraction decodes quoted-printable and base64-like input, the adapter rejects `=XX`/soft-break ambiguity before payment, neutralizes a displayed base64 transfer-encoding header phrase, and prefixes supported bodies with non-base64 punctuation. A contract test passes these outputs through the actual API `visibleEmailText` function.

The client validates public DNS and pins all validated answers, verifies TLS and hostname, refuses redirects, bounds time and response size, performs no retries, and accepts only strict finite classification and integer pricing fields. API errors are sanitized and bounded before logging. Message content, token, response bodies, and recipient data are not logged.

Blocking relies on the audited PrestaShop 9.0.x `Mail::send()` catch for Symfony Mailer exceptions. The final hook itself has no cancellation return value. Unsupported PrestaShop releases must not be forced past the declared compatibility range without re-auditing `classes/Mail.php`.