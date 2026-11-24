# Ledge Acquire Protocol

The acquire capability lets Ledge command a site to produce an encrypted bundle (full DB dump + env manifest) and push it to object storage for update testing. It is a higher-privilege capability than `/health`, so commands must carry an Ed25519 signature verifiable independently of the shared `X-Ledge-Key`.

## Key discovery

The plugin does **not** store a signing public key. Ledge publishes its signing keys at a well-known keyset endpoint and the plugin discovers them:

```
GET {ledgeBaseUrl}/.well-known/ledge-keys

{ "keys": [ { "key_id": "a1b2c3d4", "alg": "ed25519", "public_key": "<base64>" } ] }
```

- `ledgeBaseUrl` defaults to `https://my.ledgehq.app` and is overridable **only** via `config/ledge.php` (file config, deliberately not env, and never derived from an incoming command — an attacker-supplied URL serving an attacker key would defeat the scheme).
- A command with an unknown `key_id` triggers one refetch; if still unknown, the command is rejected (`unknown_key_id`).
- **Pins are permanent.** Learned `key_id` → `public_key` pins are stored without TTL: a `key_id` that was ever seen maps to its original key material forever. A later fetch that disagrees is ignored for that entry — even after the fetched-keyset cache (24h TTL, discovery only) expires. Rotation always arrives as a new `key_id`.

## Configuration (plugin side)

`config/ledge.php`:

```php
return [
    'secretKey' => '$LEDGE_SECRET_KEY',

    // Acquire is OFF by default. Opt in explicitly here or with
    // LEDGE_ACQUIRE_ENABLED=true; while disabled the acquire routes are not
    // registered (404) and the plugin behaves exactly as before.
    'acquireEnabled' => true,

    // Upload/callback host allowlist. Defaults to ['ledgehq.app', '*.ledgehq.app'];
    // override only for self-hosted or dev setups:
    // 'acquireAllowedHosts' => ['ledge.example.com'],

    // The manifest includes every env var by default; the plugin's own LEDGE_*
    // is always excluded. Uncomment to keep common credentials out of the
    // bundle (case-insensitive fnmatch patterns):
    // 'acquireEnvDenylist' => [
    //     'DB_*', 'CRAFT_DB_*',                 // database credentials
    //     'MAIL_*', 'CRAFT_MAIL_*', 'SMTP_*',   // mail credentials
    //     'AWS_*', 'S3_*',                      // object-storage keys
    //     '*_PASSWORD*', '*_SECRET*', '*_TOKEN*', '*_API_KEY*',
    //     '*_PRIVATE_KEY*', '*_CREDENTIAL*', '*_DSN', '*_WEBHOOK*',
    // ],

    // 'ledgeBaseUrl' => 'https://my.ledgehq.app',   // keyset trust anchor; file-config only
    // 'acquirePath' => '_ledge/acquire',
    // 'acquireMaxBundleBytes' => 524288000,
    // 'acquireJobTtr' => 3600,
];
```

The `/health` payload includes `acquireEnabled` (top-level boolean) so Ledge can tell per-project whether the operator has turned acquire on. This is a stronger signal than sniffing the plugin version from the `plugins` check, which only proves the code is installed, not enabled.

The allowlist defaults to `['ledgehq.app', '*.ledgehq.app']`, so acquire works against the Ledge service out of the box once enabled. Setting `acquireAllowedHosts` (or the `LEDGE_ACQUIRE_ALLOWED_HOSTS` env fallback, comma-separated) replaces the default entirely — list the ledgehq.app entries alongside any custom hosts if both should be reachable. `upload_url` and `callback_url` must be `https` (plain `http` is accepted only when Craft `devMode` is on) and their hosts must match the allowlist (exact, case-insensitive, or `*.example.com` wildcard); non-matching commands are rejected `host_not_allowed`.

## Keypair generation (one-liner, Ledge side)

```bash
php -r '$kp = sodium_crypto_sign_keypair(); echo "public: ".base64_encode(sodium_crypto_sign_publickey($kp))."\nsecret: ".base64_encode(sodium_crypto_sign_secretkey($kp))."\n";'
```

The public key is published in the keyset under a `key_id`; the secret key stays in Ledge.

## Command contract

`POST /_ledge/acquire` with header `X-Ledge-Key: <shared key>` (header name configurable via the plugin's `keyHeader` setting; `X-Ledge-Key` is the default and is used throughout this document) and body:

```json
{
  "command": {
    "run_id": "9f2b3c-...",        // 1-64 chars, [A-Za-z0-9-]
    "expires_at": "2026-07-02T12:15:00+00:00",   // ISO8601
    "upload_url": "https://storage.ledgehq.app/bundles/<run_id>",
    "bundle_pubkey": "<base64 X25519 public key (crypto_box), 32 bytes>",
    "profile": "full",             // "full" (update run) or "backup"
    "callback_url": "https://app.ledgehq.app/api/acquire/callback",
    "callback_token": "<bearer token echoed on every callback>",
    "key_id": "a1b2c3d4"
  },
  "signature": "<base64 Ed25519 detached signature>"
}
```

The signature covers the **canonical JSON of the command without `callback_token`**: recursively sort all keys (`ksort`), then `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Excluding `callback_token` lets Ledge rotate callback tokens without re-signing; the token is still protected in transit by TLS, and `callback_url` itself *is* signed and allowlisted.

## Profiles

`profile` selects what the bundle is for. Verification, signing, replay guard, host allowlist, encryption, and the signed-upload flow are **identical** across profiles — only the bundle contents and the `acquire.completed` detail differ. Unknown profile values are rejected `invalid_profile`.

| Profile | Purpose | Bundle contents | `acquire.completed` detail |
|---|---|---|---|
| `full` | Update-test run | `dump.sql` + `manifest.json` (env vars, facts, crawlable `uris`) | `{size, sha256}` + upload telemetry |
| `backup` | Scheduled backup | `dump.sql` **only** | `{size, sha256, compressed_size, uncompressed_size, dump_duration_ms, table_count}` + upload telemetry |

Both run the same queue job (same TTR / memory handling); distinct `run_id`s queue independently and the replay guard only fires on a repeated `run_id`.

The `backup` profile is deliberately **the database and nothing else** — no env manifest, no metadata sidecar, no project config. The env manifest (the only artefact that packages potentially secret-bearing environment variables) is `full`-only; the backup code path never builds it. The bare dump still restores to a working site because Craft's project config and schema version already live inside the dump (the `projectconfig` / `info` tables). The richer `acquire.completed` detail is operational telemetry only (sizes, dump duration, table count, sealed sha256) — no site data.

## Signing (Laravel side)

```php
$command = [
    'run_id' => $runId,
    'expires_at' => now()->addMinutes(15)->toIso8601String(),
    'upload_url' => $uploadUrl,
    'bundle_pubkey' => base64_encode($bundlePublicKey),
    'profile' => 'full',
    'callback_url' => route('acquire.callback'),
    'callback_token' => $callbackToken,
    'key_id' => $keyId,
];

$unsigned = $command;
unset($unsigned['callback_token']);
ksort($unsigned); // recursively, if nested values are ever added

$signature = sodium_crypto_sign_detached(
    json_encode($unsigned, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    base64_decode($signingSecretKey),
);

Http::withHeaders(['X-Ledge-Key' => $site->secret_key])
    ->post("{$site->url}/_ledge/acquire", [
        'command' => $command,
        'signature' => base64_encode($signature),
    ]);
```

## Verification order and error reasons

Checks run in this order; the first failure wins. Errors are `{"accepted": false, "reason": "<reason>"}` with the listed HTTP status, and every rejection is logged.

| Order | Check | Reason | Status |
|---|---|---|---|
| 0 | acquire enabled (`acquireEnabled` / `LEDGE_ACQUIRE_ENABLED`) | `not_enabled` | 404 |
| 1 | `X-Ledge-Key` valid | `invalid_key` | 401 |
| 2 | body well-formed (`command` object + base64 64-byte `signature`) | `invalid_envelope` | 400 |
| 3 | command fields present/typed, ISO8601 `expires_at` | `invalid_payload` | 400 |
| 4 | `key_id` resolves via keyset | `unknown_key_id` / `keyset_unavailable` | 401 / 503 |
| 5 | Ed25519 signature valid over canonical JSON | `invalid_signature` | 401 |
| 6 | not expired | `expired` | 403 |
| 7 | profile supported | `invalid_profile` | 400 |
| 8 | upload + callback hosts allowlisted, https | `host_not_allowed` | 403 |
| 9 | `run_id` never seen before | `replayed` | 409 |

Success: `202 {"accepted": true, "run_id": "..."}` — the bundle job is queued on Craft's queue; nothing heavy runs in the request cycle.

Job-side failure reasons (reported via callback and the status endpoint): `no_dump_command` (mysqldump/pg_dump not resolvable), `tmp_not_writable`, `insufficient_disk` (free < 2× estimated dump size), `sodium_unavailable`, `dump_failed`, `archive_failed`, `bundle_too_large`, `encrypt_failed`, `upload_failed`, `run_already_finished`, `unexpected_error`.

**Upload semantics:** the bundle is a streamed `PUT` to `upload_url`, retried on transient failures, the same way for every profile. Any 2xx response is success and the body is ignored (the Ledge signed route answers `201 {"ok":true,...}`; an S3 pre-signed PUT answers `200` with an empty body). A PUT to the same URL overwrites the same object, so repeating it is safe; every attempt reopens the already-encrypted bundle and sends the whole body with the same `Content-Length` (nothing is re-dumped or re-encrypted).

- **Retried:** transport failures with no response (connect errors, resets, timeouts) and HTTP `408`, `429`, `500`, `502`, `503` and `504`.
- **Terminal, never retried:** `413` → `bundle_too_large`, `409` (run already finished) → `run_already_finished`, any other status → `upload_failed` (other 4xx such as `403` for an expired or invalid signature, other 5xx such as `501`, and 3xx since redirects are not followed).
- **Attempts and waits:** up to 5 attempts. The waits before attempts 2-5 are 2s, 6s, 18s and 54s, each multiplied by a random jitter in [0.75, 1.25] so sites backing up in the same minute spread out: about 80s of waiting in all. A `Retry-After` header (delta-seconds or HTTP-date) longer than the computed wait replaces it, capped at 60s.
- **Deadline:** Ledge's upload URL is valid for 60 minutes from when it sent the command, and Ledge reaps a run still unfinished 60 minutes after it started. The job records when the command was accepted, and no retry starts at or after accepted + 55 minutes; each attempt's timeout is also clipped to `max(30s, min(600s, time left before that deadline))`, so an attempt cannot run on past the window. Jobs queued by a plugin before 5.14.0 carry no accepted time and upload without the deadline.
- **Time budget:** independently, no new attempt starts once the time since the first attempt began would exceed the per-attempt timeout (600s), so a run of slow timeouts cannot use up the job's TTR.
- When retries stop, the `detail` string says why, e.g. `upload target responded with HTTP 503 after 5 attempts`, `… (retry time budget of 600s exhausted)` or `… (upload URL deadline reached)`.

Plugins before 5.14.0 make a single attempt and give up on the first failure.

Every 4xx/5xx rejection from the plugin itself carries `{"reason": "<code>"}` so Ledge can record it as the run's `failure_reason`, including unexpected errors (`unexpected_error`, 500).

## Bundle file format

The uploaded object is:

```
[ 80 bytes  crypto_box_seal(symmetric key, bundle_pubkey) ]
[ 24 bytes  secretstream header ]
[ N chunks  crypto_secretstream_xchacha20poly1305 ciphertext ]
```

- Symmetric key: `crypto_secretstream_xchacha20poly1305_keygen()` (32 bytes); sealed size = 32 + 48 (`SODIUM_CRYPTO_BOX_SEALBYTES`).
- Plaintext chunk size: **1 MiB** (1048576 bytes); each ciphertext chunk is 1048576 + 17 (`SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES`) bytes, except the final chunk, which is shorter and tagged `TAG_FINAL`.
- The plaintext is `bundle.tar.gz`: a gzipped tar containing `dump.sql` (mysqldump via Craft's backup pipeline) and `manifest.json`.

Runner-side decrypt:

```php
$in = fopen($bundlePath, 'rb');
$key = sodium_crypto_box_seal_open(fread($in, 80), $runnerKeypair); // crypto_box keypair matching bundle_pubkey
$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(fread($in, 24), $key);
$out = fopen('bundle.tar.gz', 'wb');

do {
    $chunk = fread($in, 1048576 + 17);
    [$plain, $tag] = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
    fwrite($out, $plain);
} while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
```

## Manifest

`manifest.json` contains:

- `env` — **every** process environment variable by default (the bundle already carries a full sealed DB dump, so the manifest's env vars are a minor incremental exposure). The site operator narrows this with `acquireEnvDenylist`, a list of `fnmatch` patterns matched case-insensitively (e.g. `['DB_*', '*_SECRET*', '*_API_KEY', 'AWS_*']`); `LEDGE_ACQUIRE_ENV_DENYLIST` (comma-separated) is an env fallback. The plugin's own auth material (`LEDGE_*`) is **always** excluded regardless of config — shipping it would let anyone with the bundle authenticate to this site's Ledge endpoints. Because the default denylist is empty, review it before enabling acquire on sites that keep unusual secrets in the environment.
- `php` — version + loaded extensions
- `database` — driver + server version
- `craft` — version + edition
- `configVersion` — project-config version from the `info` table
- `plugins` — installed plugin handles → versions
- `sites` — every Craft site, so a consumer of `uris` can map each entry's `site` handle to a real host instead of assuming everything lives under the primary site's URL (on a multi-site install, non-primary-only pages otherwise get requested against the primary host and 404). A JSON array of `{ "handle": "besserFrench", "primary": false, "enabled": true, "language": "fr-CA", "baseUrl": "https://example.com/fr", "host": "example.com", "path": "/fr" }` objects, primary first then by handle (disabled sites included, flagged `enabled: false`): `baseUrl` is the site's resolved base URL (aliases/env vars already expanded, no trailing slash; `null` if Craft can't resolve one), and `host` (lowercase, `null` when unresolvable) and `path` (`/` for root, `/fr` for a path-mounted site) are that URL pre-split — together they let a runner re-homing every site onto sandbox hosts tell a path-mounted site (same host as the primary → keep the path) from a host-distinguished one (`fr.example.com` → needs its own sandbox host) without re-parsing. **Fallback rule:** `sites` is purely additive — absent on plugins before 5.5.0 and omitted (never emitted empty) on enumeration failure, the same way `uris` is. Consumers must treat absence as single-site and resolve every URI against the primary site's URL.
- `uris` — every crawlable URL on the site, so the runner can crawl before/after an update instead of deriving URLs from raw SQL. A JSON array of `{ "uri": "/blog/sample-post", "site": "default", "section": "blog" }` objects: `uri` is site-root-relative with a leading slash (the homepage sentinel `__home__` is emitted as `/`), `site` is the Craft site handle, and `section` is the section handle for entries or `null` for element types not organized into sections (categories, Commerce products, etc.). Enumerated authoritatively via Craft's element API across **all** URL-enabled element types (entries, categories, Commerce products, any custom element type with a URI) for every site, filtered to enabled + not soft-deleted + non-null URI — no sampling, no cap; only exact `(uri, site)` duplicates are removed (URIs are unique per site, so `section` is deterministic for a given pair). The field is additive: a bundle with no `uris` (older plugin) just means the runner falls back to its own URL discovery. On a pathologically large site the list is correspondingly large by design — bounding it is the runner's concern.

## Callbacks

Best-effort `POST` to `callback_url` with `Authorization: Bearer <callback_token>` (3s timeout, one retry; a dropped callback never fails the job). The token identifies the run on Ledge's side, so the body is just:

```json
{ "event": "acquire.progress", "step": "dump", "detail": null }
```

| Event | When | Detail |
|---|---|---|
| `acquire.accepted` | command verified + job queued | — |
| `acquire.started` | job picked up by queue | — |
| `acquire.progress` | after each step (preflight, dump, manifest, encrypt) | — |
| `acquire.upload_retried` | an upload attempt failed and will be retried; sent before the wait (step `upload`) | see below |
| `acquire.completed` | bundle uploaded | the profile's keys (see Profiles) + upload telemetry, see below |
| `acquire.failed` | any step failed; at the upload step only once retries are exhausted or the status is terminal | see below |

Ledge responds `401` (bad token), `409` (run already finished — not retried), or `200 {ok: true}`.

How Ledge treats these today: **any event whose name ends in `.failed` is terminal** (the run is marked failed and the customer alerted), so the plugin sends `acquire.failed` once and never for a retried attempt. Other events are appended to the run's event log. Per event, Ledge keeps:

- `detail.reason`, cut to 255 characters;
- on `acquire.completed`, the scalar telemetry keys `sha256`, `size`, `compressed_size`, `uncompressed_size`, `dump_duration_ms`, `table_count`, `encrypt_duration_ms`, `upload_duration_ms`, `upload_attempts`, `peak_memory_bytes`;
- everything else in `detail` as the event's metrics, after bounding: the first 20 keys at each level, strings cut to 255 characters, nulls dropped, at most two levels of nesting below the top. If the JSON of that is over 1500 bytes, the whole of it is replaced by `{"truncated": true}`.

That last limit is why each event carries a single upload attempt rather than the history, and why the plugin keeps the rest of `detail` under 1400 bytes as Ledge would store it: when a worst-case attempt would not fit, it drops the attempt's `body`, then its `request_ids`, then cuts the attempt's `error` and the top-level `detail` to 120 characters, and as a last resort leaves the attempt object out. `reason`, `reason_code`, `upload_attempts` and the timings are never dropped. A typical S3 `503 SlowDown` attempt fits whole (about 700 bytes stored for `acquire.upload_retried`, 850 for the final `acquire.failed`). The keys below are additive and absent on plugins before 5.14.0.

### Upload retries and diagnostics

**Reason line.** Each failed upload attempt is summarised in one line, which is what Ledge stores:

```
attempt 2/5: HTTP 503 SlowDown, request-id abc123, 412 ms, sent 0/26214400 bytes, retry-after 1
attempt 1/5: ConnectException curl 28, 600012 ms, sent 1048576/26214400 bytes
```

The parts are the attempt number out of the maximum; `HTTP <status>` with the S3 error code when the body has one, and/or the exception type with curl's error number; `request-id`; the attempt's duration; `sent <bytes>/<bundle size> bytes`; and `retry-after`. Parts whose value is unknown are left out. Values taken from the response are reduced to printable ASCII and capped, and the line (with any `<code>: ` prefix) is always under 255 characters.

**`acquire.upload_retried`** (step `upload`):

| Key | Type | |
|---|---|---|
| `reason` | string | the reason line of the attempt that just failed |
| `max_attempts` | int | attempts allowed (5) |
| `retry_in_ms` | int | wait before the next attempt |
| `upload_attempts` | int | attempts made so far |
| `upload_attempt` | object | the attempt that just failed (below) |

A dropped or failing callback for this event neither fails the job nor uses up an attempt.

**`acquire.failed`** from any step:

| Key | Type | |
|---|---|---|
| `reason` | string | the bare reason code (`no_dump_command`, `dump_failed`, `bundle_too_large`, `run_already_finished`, ...), exactly as before, except `upload_failed`, which becomes `upload_failed: <reason line of the last attempt>`, e.g. `upload_failed: attempt 5/5: HTTP 503 SlowDown, request-id abc123, 412 ms, sent 0/26214400 bytes` (Ledge matches `upload_failed` by prefix and other codes exactly) |
| `reason_code` | string | the bare code, on every failed event |
| `detail` | string\|null | the human-readable detail, as before |
| `upload_attempts` | int | upload step only: attempts made |
| `upload_attempt` | object | upload step only: the last attempt (below); absent when none was made |
| `dump_duration_ms`, `encrypt_duration_ms`, `upload_duration_ms` | int | whichever phases had been measured (for an upload failure, `upload_duration_ms` is the time spent uploading, waits included) |
| `peak_memory_bytes` | int | `memory_get_peak_usage(true)` |

The status endpoint keeps recording the bare code.

**`acquire.completed`**: the profile's existing keys, unchanged, plus:

| Key | Type | |
|---|---|---|
| `encrypt_duration_ms` | int | archive + encryption time |
| `upload_duration_ms` | int | upload time, waits included |
| `upload_attempts` | int | attempts made, `1` when the first succeeded |
| `peak_memory_bytes` | int | `memory_get_peak_usage(true)` |

Earlier failed attempts of a run that then succeeded were reported by their own `acquire.upload_retried` events.

**Attempt object** (`upload_attempt`):

| Key | Type | |
|---|---|---|
| `attempt` | int | 1-based |
| `started_at` | string | UTC, `2026-11-24T03:00:07Z` |
| `status` | int\|null | `null` when no response arrived. When the transfer broke after the response headers came in, both `status` and `error` are set and the attempt is judged by its status |
| `error` | string\|null | exception type and message, at most 200 bytes; `null` when a response arrived cleanly |
| `curl_errno` | int\|null | `28` timeout, `7` couldn't connect, `56` receive failure, ... |
| `error_code` | string\|null | the S3 `<Code>` from the response body (`SlowDown`, `ServiceUnavailable`, ...), at most 64 characters of `[A-Za-z0-9._-]` |
| `request_id` | string\|null | `x-amz-request-id` if present, else the first other request-id header |
| `request_ids` | object\|null | every response header whose name contains `request-id` or `amz-id`, or is `cf-ray`: name → value, at most 5, values at most 128 bytes; `null` if shed to fit the size budget. No other headers are reported |
| `retry_after` | string\|null | the raw `Retry-After` header, at most 64 bytes |
| `duration_ms` | int | |
| `bytes_sent` | int\|null | bytes of the body curl had sent. Guzzle sends `Expect: 100-continue` for bodies over 1 MB, so a store that throttles can answer before any of the body is sent; `0` here shows that. `null` when the HTTP handler gives no stats |
| `remote_ip` | string\|null | the address curl connected to |
| `body` | string\|null | the first 300 bytes of a non-2xx response body, valid UTF-8; `null` if shed to fit the size budget |
| `retry_in_ms` | int\|null | wait before the next attempt; `null` when none followed |

No reason, detail or attempt object ever contains the upload URL's query string, the callback token or the bundle key: the URL is reduced to scheme + host + path, and the query string, its long values, the callback token and the bundle public key are replaced with `[redacted]` wherever a response or exception message echoes them.

## Status endpoint (pull fallback)

`GET /_ledge/acquire/<run_id>` with `X-Ledge-Key` header returns the run's current state (held in Craft's cache, ~24h TTL — the plugin keeps no persistent run history; Ledge is the system of record):

```json
{
  "run_id": "...",
  "status": "pending|running|completed|failed",
  "step": "queued|preflight|dump|manifest|encrypt|upload",
  "detail": null,
  "profile": "full",
  "size": 1234567,
  "sha256": "…",
  "dateCreated": "…",
  "dateUpdated": "…"
}
```

Unknown run: `404 {"reason": "unknown_run_id"}`. This is how the watchdog distinguishes "queue never ran the job" (`pending`/`queued` forever) from "job died mid-dump" (`running` + stale `dateUpdated`) from "callbacks being dropped" (`completed` here, nothing received). Because run state is cache-backed, a cache flush mid-run can drop the entry (404 → the watchdog should treat unknown as retryable); the job transparently rebuilds it on its next transition.

## Public URIs endpoint

`GET /_ledge/uris` with the `X-Ledge-Key` header returns the site's crawlable public URL map on demand — the same `uris` list embedded in the `full`-profile bundle, but fetchable without triggering an acquisition:

```json
{
  "count": 342,
  "sites": [
    { "handle": "default", "primary": true, "enabled": true, "language": "en-US", "baseUrl": "https://example.com", "host": "example.com", "path": "/" },
    { "handle": "fr", "primary": false, "enabled": true, "language": "fr-CA", "baseUrl": "https://fr.example.com", "host": "fr.example.com", "path": "/" }
  ],
  "uris": [
    { "uri": "/", "site": "default", "section": "pages" },
    { "uri": "/blog/hello-world", "site": "default", "section": "blog" },
    { "uri": "/blog/bonjour", "site": "fr", "section": "blog" }
  ]
}
```

`sites` is the same list as the bundle manifest's `sites` fact (see above): resolve each URI's `site` handle against it to get the host — `baseUrl + uri` is the real public URL. `count` covers `uris` only, and `sites` is omitted (not empty) when site enumeration fails or on plugins before 5.5.0.

Shared-key auth (same tier as `/health`), **not** the signed-command tier — the URLs are already public, so the endpoint only keeps the full map from being fully anonymous. The plugin always returns **all** URIs (enabled + front-end-resolvable, every URL-enabled element type across all sites); the Ledge service decides which pages it actually checks. The list can be large, which is why it is a separate on-demand endpoint rather than part of the frequently-polled `/health` payload.

**Off by default.** Because it exposes the complete public URL map — including live-but-unlinked pages — the endpoint is opt-in: enable it via config (`'urisEnabled' => true`) or the `LEDGE_URIS_ENABLED` env var. While disabled the `/_ledge/uris` route is not registered (404). Enumerated via `AcquireService::getPublicUris()`; path configurable via `urisPath` (default `_ledge/uris`).

## Local dev

In the test Craft site's `config/ledge.php`:

```php
return [
    'secretKey' => '$LEDGE_SECRET_KEY',
    'ledgeBaseUrl' => 'https://ledge.ddev.site',
    'acquireAllowedHosts' => ['ledge.ddev.site'],
];
```

The dev keyset is served at `https://ledge.ddev.site/.well-known/ledge-keys` automatically — no key copying between machines — and the single host covers the keyset fetch, callbacks, and the bundle upload. If every command fails `keyset_unavailable`, the Craft container can't reach or trust the Ledge container: check DDEV CA trust inside the web container and cross-project networking (`ddev-router` DNS).

## Manual end-to-end verification

1. Configure the test site as in **Local dev** above. (No migration/table — replay and run state live in Craft's cache.)
2. Mint a signed command from the local Ledge (or with the signing snippet above), pointing `upload_url` at a local PUT sink (with devMode on, http URLs are accepted).
4. `curl -X POST -H "X-Ledge-Key: …" -d @body.json https://site.ddev.site/_ledge/acquire` → expect `202`.
5. Re-POST the same body → `409 replayed`. Change a signed field → `401 invalid_signature`. Set `expires_at` in the past → `403 expired`. Use an off-list host → `403 host_not_allowed`. Use a bogus `key_id` → `401 unknown_key_id`.
6. `ddev craft queue/run` → callbacks arrive in order at Ledge; `GET /_ledge/acquire/<run_id>` shows `completed` with `size`/`sha256`; decrypt the uploaded object with the runner snippet and inspect `dump.sql` + `manifest.json`.
7. Confirm `storage/runtime/ledge/` is empty afterwards — including after a forced failure (e.g. unreachable `upload_url`).
8. `GET /_ledge/health` root includes `platform: {name: "craftcms", version, edition}`, `configVersion`, `acquireEnabled: true`, and `backupProfileSupported: true` (advertises backup support so Ledge can gate scheduling on it).
9. Set `acquireEnabled` back to `false` (or unset `LEDGE_ACQUIRE_ENABLED`) → `POST /_ledge/acquire` is a `404`, `/health` reports `acquireEnabled: false`, and the plugin behaves exactly as before the feature existed.
