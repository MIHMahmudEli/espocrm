# Web Push Notifications — deployment, configuration and limits

Custom module for EspoCRM 10.x. Everything lives under `custom/` and
`client/custom/`, so an EspoCRM upgrade does not overwrite it.

---

## 1. What it does

Two independent delivery paths share one API:

| Path | Trigger | Survives browser closing? |
|------|---------|----------------------------|
| **A — in-app** | `NotificationService::notifyUser()` writes a `notification` row and submits a WebSocket refresh | No — visible only while a session is open |
| **B — Web Push** | The same call forwards the payload to the push service (FCM/Mozilla), which hands it to the operating system | Yes — while the service worker is registered for that browser profile |

`CallRouteReminders` and any future producer call **one** method,
`NotificationService::notifyUser(...)`. There is no Web Push logic inside the
jobs, and no `setTimeout`/`setInterval`/polling anywhere in the delivery chain.

Nothing in this module is Windows-specific.

---

## 2. File map

```
custom/Espo/Custom/
  Controllers/PushSubscription.php      REST endpoints (see §6)
  ConsoleCommands/WebPushGenerateVapidKeys.php
  Jobs/CallRouteReminders.php           producer, consumes NotificationService
  Notification/NotificationService.php  the single API both paths go through
  WebPush/
    PushNotificationService.php         dispatch: subscriptions -> PushSender
    SubscriptionStore.php               push_subscription CRUD
    PushSender.php                      HTTP POST to the push service
    PushPreferences.php                 per-user muted categories
    VapidKeys.php                       env vars, fallback file, key generation
    Encryption.php Der.php Base64Url.php
    ProjectPath.php SendResult.php
  Resources/metadata/
    entityDefs/PushSubscription.json    lastUsedAt, active, expiry
    entityDefs/Preferences.json         webPushMutedCategories
    app/client.json                     scriptList
    app/clientNavbar.json               navbar toggle
    app/scheduledJobs.json              CallRouteReminders every minute
    app/consoleCommands.json            web-push-generate-vapid-keys
    scopes/PushSubscription.json

client/custom/
  src/web-push.js                       bootstrap, window.EspoWebPush
  src/helpers/web-push-manager.js       SW + permission + API calls
  src/views/site/navbar/web-push.js     navbar control
  res/templates/site/navbar/web-push.tpl
.htaccess                               never lets a browser cache sw.js
public/sw.js                            service worker (root scope)
data/webpush-config.php                 VAPID fallback, private key — never public
```

---

## 3. Production deployment checklist

`deploy.sh` in the project root runs steps 1–5 below, verifies the result with
`--url=https://host/app`, and prints the cron line. Everything except the two
server-owned items (HTTPS and cron) is automatic.

1. **HTTPS is mandatory.** `serviceWorker` registration, `PushManager.subscribe()`
   and `Notification.requestPermission()` are all blocked on insecure origins
   (the browser exposes them as *insecure context*). `http://localhost` is the
   only exception a browser makes.
2. **Serve the app from the domain root** (or know your base path). The service
   worker is registered as `basePath + 'sw.js'` with `scope: basePath`, so
   `public/sw.js` must answer at `<base>/sw.js`.
   Answer it with `Cache-Control: no-cache` — a shipped `.htaccess` rule does
   this for `sw.js` and for nothing else. The worker is the one file a browser
   is *allowed* to cache on its own schedule; if a server gives it a long
   lifetime, devices that already have it registered keep the old copy for days
   and never see a fix you deploy.
3. **VAPID key pair.** Normally nothing to do: the first request generates a
   pair and writes `data/webpush-config.php`, so a fresh deployment starts
   working by itself. Two reasons to run it by hand instead — choosing the
   contact subject, or rotating an existing pair:

   ```
   php command.php web-push-generate-vapid-keys --subject=mailto:ops@example.com
   ```

   Keep the file private — it holds the private key. `data/` must not be
   web-reachable. If `data/` is not writable the app falls back to
   *"not configured"* rather than handing out keys only one request can see.
4. **Or set environment variables** (they take precedence over the file, all
   three must be present):

   ```
   VAPID_PUBLIC_KEY=<base64url 65-byte P-256 key>
   VAPID_PRIVATE_KEY=<base64url 32-byte scalar D>
   VAPID_SUBJECT=mailto:ops@example.com
   ```

   Set them for **both** Apache/FPM and the CLI that runs the scheduler, or the
   web path and the cron path will use different keys and every subscription
   will break.
5. **Rebuild metadata** after installing or upgrading the module:

   ```
   php rebuild.php
   php clear_cache.php
   ```
6. **Confirm the scheduler runs every minute.** `CallRouteReminders` is
   registered with `* * * * *`. The cron entry EspoCRM already uses is enough:

   ```
   * * * * * php /path/to/espocrm/cron.php > /dev/null 2>&1
   ```
7. **Master switch.** Web Push is on unless `webPushEnabled` is explicitly
   `false` in `config.php`. Leaving the key absent keeps it enabled.
8. **Rotation.** Rotating the VAPID pair invalidates *every* stored
   subscription; each browser must re-subscribe (click the navbar toggle off,
   then on). Rotate both halves together, never one.
9. **Migration.** No manual SQL. `PushSubscription` and the
   `Preferences.webPushMutedCategories` field are created by the rebuild.
   If you deploy by copy rather than install, run the rebuild before use.

---

## 4. Localhost testing

* `http://localhost/...` is accepted by browsers as a secure origin — push
  works without a certificate.
* `http://127.0.0.1/...` also works. `http://my-machine/` (plain host name over
  HTTP) does **not**.
* Clearing site data removes the service worker registration; re-enable the
  toggle after clearing.
* To watch the wire without a real push service, point the sender at a local
  endpoint during development — the integration suite does exactly this with a
  `php -S` router that returns `201`, `410` and `429` so success, expired
  subscription and transient failure can all be exercised.

---

## 5. Browser support

| Browser | Status |
|---------|--------|
| Chrome / Edge (Windows, macOS, Linux) | Supported — verified |
| Firefox (Windows, macOS, Linux) | Supported by spec; uses Mozilla's push service |
| Safari 16.4+ (macOS, iOS) | Supported by spec; requires an Apple developer certificate on iOS |
| Internet Explorer, legacy Edge | Not supported (no Service Worker / Push API) |
| Any browser over plain HTTP (non-localhost) | Not supported |

Requirements: Service Worker + Push API + Notifications API. The client checks
all three and shows *"This browser does not support desktop notifications"*
when they are missing.

---

## 6. API reference

All under `/api/v1/PushSubscription/action/…`, authenticated as usual.

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `vapidPublicKey` | Base64url public key for `applicationServerKey` |
| GET | `status` | `enabled`, `configured`, subscription count, permission |
| GET | `preferences` | `{"mutedCategories":[…]}` |
| POST | `preferences` | Save muted categories |
| POST | `subscribe` | Body: `{endpoint, keys:{p256dh, auth}, expirationTime, deviceName}` |
| POST | `unsubscribe` | Body: `{endpoint}` |
| POST | `sendTest` | Fires one push at the calling user's devices |
| POST | `diagnostics` | Body: `{client: …}` — this account's server view plus the browser's own report |
| GET | `devices` | Administrator only: every device on the installation, endpoint URL omitted |

`sendTest` response:

```jsonc
{ "result": "success", "sent": 2, "success": true, "summary": "…" }
{ "result": "skipped", "error": "muted",          "sent": 0, "success": false }
{ "result": "failed",  "error": "notConfigured",  "sent": 0, "success": false }
{ "result": "failed",  "error": "noSubscription", "sent": 0, "success": false }
```

**Diagnostics.** One call answers *"why did this device behave differently"*.
`POST diagnostics` returns two halves:

* `server` — what the installation holds for the caller: VAPID configuration,
  the master switch, subscription count, muted categories, one row per device,
  and the time it was checked;
* `client` — what that browser's own service worker recorded: worker version,
  how many pushes it received, whether `showNotification()` was ever called,
  when, and the **name** of the last error (never its message).

`GET devices` returns the same per-device rows across all accounts and is
refused unless the caller is an administrator — that is how a device that works
is compared with one that does not.

Nothing that could be used to forge a push travels in either direction: the
endpoint URL, the `p256dh`/`auth` keys and anything token-shaped are dropped
before the answer is built, and the browser's report is depth- and
size-limited. A device is identified by its push service host
(`fcm.googleapis.com`, …) and user agent.

### User-facing control

The navbar bell has one control: **Enable desktop notifications**. It is the
only place `Notification.requestPermission()` is ever called — permission is
never requested automatically on page load. Clicking it again disables
notifications for that browser (subscription removed server-side).

---

## 7. Backend integration guide

```php
use Espo\Custom\Notification\NotificationService;

/** @var NotificationService $notify */
$notify = (new \Espo\Core\InjectableFactory($container))
    ->create(NotificationService::class);

$notify->notifyUser($userId, [
    'message'     => 'Invoice INV-1042 was approved.',
    'entityType'  => 'Invoice',
    'entityId'    => $invoiceId,
    'entityName'  => 'INV-1042',
    'url'         => '#Invoice/view/' . $invoiceId,
], [
    'actionId'   => 'invoice.approved.' . $invoiceId, // dedup key
    'category'   => 'invoice',
    'pushTitle'  => 'Invoice approved',
    'pushBody'   => 'INV-1042 was approved.',
    'ttl'        => 3600,
    'urgency'    => 'normal',
    'tag'        => 'invoice-' . $invoiceId,
]);

$notify->notifyUsers($userIds, $data, $options); // per-recipient dedup keys
```

Options accepted by `notifyUser()`: `actionId`, `related`, `popup`,
`persistent`, `webPush`, `webSocket`, `pushTitle`, `pushBody`, `category`,
`payloadType`, `tag`, `ttl`, `urgency`.

**Deduplication.** `actionId` is unique per user. When a row with the same
`(user_id, action_id)` exists, nothing is created and nothing is pushed — so a
scheduler running every minute produces exactly one notification. Keys longer
than 36 characters are hashed (the column is `varchar(36)`), so long composite
keys are safe. `notifyUsers()` derives a per-recipient key automatically.

**Muting.** `category` is checked against the user's
`Preferences.webPushMutedCategories`. A muted category still creates the
in-app notification; only Path B is suppressed.

---

## 8. Data model

```
push_subscription
  id, assigned_user_id, endpoint (unique), key_p256dh, key_auth,
  expiration_time, device_name, user_agent, active (bool),
  last_used_at, created_at, modified_at, deleted

notification
  …, action_id varchar(36)   // dedup key, added by this module

preferences
  webPushMutedCategories (json array)   // added by this module
```

Expired subscriptions (`expiration_time` in the past or `active = 0`) are
filtered out on read. A `410 Gone` from the push service deactivates the row
immediately; a `404` does the same. Rows are never hard-deleted by the sender,
so a transient outage cannot destroy a working subscription.

---

## 9. Limitations — read before promising anything

* **Delivery with the browser fully closed is not guaranteed.** The push service
  only delivers while the browser's push connection is alive. Chrome and Edge
  on desktop keep that connection open when the browser is closed and use a
  platform service to show the notification; Firefox behaves differently, and
  some configurations, enterprise policies, or "continue running background
  apps" being disabled will stop it. **Test on the target machine before
  relying on it.**
* A push is **best effort**, not a queue. `ttl` (default 24 h unless set) bounds
  how long the push service may hold a message; after that it is dropped.
* One subscription per browser profile per origin. Multiple profiles or
  machines get multiple subscriptions; `sendTest` reports how many were
  targeted.
* The OS controls presentation: Windows focus assist, macOS Do Not Disturb, and
  per-site notification permissions can all suppress a visible banner while the
  push was still delivered.
* **A delivered push can stay silent.** Windows groups notifications by tag,
  and replacing the previous one under the same tag can write a new entry to
  the notification centre without raising the banner again. The worker now sets
  `renotify` whenever a tag is present, which asks the OS to alert once more —
  and the OS is still free to refuse if repeated notifications are switched off
  for that site.
* **Whether a banner appears at all is the operating system's decision.** Focus
  Assist / Do Not Disturb, the per-site notification setting in Windows, battery
  saver and enterprise policy can all accept the push and show only an entry in
  the notification centre. No script can override this. It is the case the
  diagnostics report exists to separate: if it shows the push arrived and
  `showNotification()` ran without error, the switch lives in the OS, not in
  the module.
* **A stale worker hides deployed fixes.** Because a browser may cache `sw.js`
  on its own schedule, a device can keep running yesterday's worker after
  today's release. `sw.js` must be served `no-cache` (§3) and the manager
  re-checks it at every sign-in; confirm the header before blaming the code.
* No at-least-once guarantee across a lost subscription: if the browser drops
  the subscription while offline, notifications stop until the user re-enables.
* `sw.js` must stay at the served root; moving it changes the scope and
  existing registrations become inert (fix by re-enabling the toggle).

---

## 10. Troubleshooting

| Symptom | Check |
|---------|-------|
| Toggle says "not configured" | `data/` is not writable, or the three `VAPID_*` env vars are incomplete — `php command.php web-push-generate-vapid-keys` then `php rebuild.php` |
| Subscribe succeeds, nothing arrives | `webPushEnabled` in `config.php`; browser push service reachability; `data/logs/espo-YYYY-MM-DD.log` |
| Nothing arrives with the browser closed | The limitation in §9 — verify on that machine with the browser closed |
| Reaches the notification centre, never pops up | Windows re-alerting on the same tag, or an OS banner setting — collect `POST diagnostics` for that account and compare it with a device that works (§6) |
| One device ignored a fix deployed to the server | The browser still holds the previous `sw.js` — confirm `<base>/sw.js` answers `Cache-Control: no-cache`, then reload the CRM on that device |
| Duplicates return after a scheduler change | `actionId` changed; old rows keep their key, new rows get a new one |
| Permission prompt never appears | Only the navbar toggle requests it; check the browser's site permissions |
| Push works, in-app panel does not | WebSocket submission path, unrelated to this module |

---

## 11. Verification

Automated suites (run from the development machine):

| Suite | Covers |
|-------|--------|
| `webpush-infra-test.php` | VAPID, payload builder, preferences, subscription lifecycle, dedup, dispatch e2e with real decryption, scheduler, API routes, probe round-trip, cleanup |
| `webpush-frontend-check.mjs` | Service worker contract, no auto-prompt, manager API, navbar wiring, endpoints, delivery reliability (cache headers, tag/`renotify`, diagnostics) |
| `sw-runtime-check.mjs` | The worker executed in isolation: payload parsing, `showNotification()`, `renotify`, failure handling, IndexedDB diagnostics, `notificationclick`, install/activate |
| `check-all.mjs` | Runs all four Web Push suites in one command: `node tests/check-all.mjs` |
| `wp-meta-check.php` | Metadata / entityDefs / scriptList |
| `test-diff-hook.php`, `test-e2e.php`, `test-dup-iso.php`, `test-dup-source.php`, `dup-api-check.mjs`, `ui-api-check.mjs` | Regressions for the earlier History-diff and duplicate work |
| `task-prefill-meta.php`, `task-prefill-check.mjs`, `handler-unit.mjs` | Task prefill |
| `meeting-check.mjs` | Meeting History parity |

The final acceptance test — a **real OS notification while the tab and the
browser are both closed** — must be performed manually:

1. Open the CRM, click the navbar bell → **Enable desktop notifications**,
   accept the permission prompt.
2. Click **Send test** (or trigger a call reminder).
3. Confirm the banner appears with the tab focused.
4. Close the tab, then close the browser entirely.
5. Trigger another notification.
6. Confirm the OS banner still appears. If it does not on that machine, that is
   the limitation in §9 — not a defect in the module.
7. If it did not, run `POST diagnostics` for that account (§6) and compare the
   report with a device that works: worker version, push service host, pushes
   received, `showNotification()` called, last error. Those five fields tell
   apart "never reached the browser", "reached the worker but not shown", and
   "shown but suppressed by the OS".

Run the sequence on more than one account — the administrator's own device is
the one case that can hide a permission or scope problem — and cover:

* the administrator's device and a freshly created account;
* Chrome and Edge on Windows;
* the tab focused, another tab focused, the window closed but the browser
  running, and the browser fully closed;
* several notifications in quick succession (the tag/`renotify` case in §9).
