<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM �?" Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces of modified source and object code versions
 * must display Appropriate Legal Notices, as required by Section 7(b) of the
 * GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\WebPush\Base64Url;
use Espo\Custom\WebPush\PushNotificationService;
use Espo\Custom\WebPush\PushPreferences;
use Espo\Custom\WebPush\SubscriptionStore;
use Espo\Custom\WebPush\VapidKeys;
use Espo\Entities\User;

/**
 * Manages Web Push subscriptions for the signed-in user.
 *
 * Exposed through the generic `/:controller/action/:action` routes:
 *
 *   GET  /api/v1/PushSubscription/action/vapidPublicKey
 *   GET  /api/v1/PushSubscription/action/status
 *   GET  /api/v1/PushSubscription/action/preferences
 *   POST /api/v1/PushSubscription/action/preferences
 *   POST /api/v1/PushSubscription/action/subscribe
 *   POST /api/v1/PushSubscription/action/unsubscribe
 *   POST /api/v1/PushSubscription/action/sendTest
 *
 * The PushSubscription scope is registered with "acl": false, so this
 * controller performs its own ownership checks: every operation is bound to
 * the authenticated user and never exposes another user's endpoint.
 */
class PushSubscription
{
    private SubscriptionStore $store;

    private PushPreferences $preferences;

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Config $config,
        private Log $log,
        private PushNotificationService $pushNotificationService,
        private PushPreferences $pushPreferences
    ) {
        $this->store = new SubscriptionStore($this->entityManager);
        $this->preferences = $pushPreferences;
    }

    /**
     * Returns the VAPID application server key the browser needs in order to
     * create a push subscription.
     */
    public function getActionVapidPublicKey(Request $request, Response $response): array
    {
        $vapidKeys = new VapidKeys();

        return [
            'configured' => $vapidKeys->isConfigured(),
            'publicKey' => $vapidKeys->isConfigured() ? $vapidKeys->getPublicKey() : null,
        ];
    }

    /**
     * Server side half of the enable flow. The browser merges this with
     * `Notification.permission` to decide which state to show.
     */
    public function getActionStatus(Request $request, Response $response): array
    {
        $userId = $this->requireUserId();

        return [
            'configured' => (new VapidKeys())->isConfigured(),
            'enabled' => $this->config->get('webPushEnabled') !== false,
            'subscriptionCount' => $this->store->countActiveForUser($userId),
            'mutedCategories' => $this->preferences->mutedFor($userId),
            'availableCategories' => $this->preferences->availableCategories(),
        ];
    }

    public function getActionPreferences(Request $request, Response $response): array
    {
        $userId = $this->requireUserId();

        return [
            'mutedCategories' => $this->preferences->mutedFor($userId),
            'availableCategories' => $this->preferences->availableCategories(),
        ];
    }

    /**
     * Body: {"categories": ["callRoute", "task"]}. The listed categories are
     * the muted ones - everything else keeps delivering.
     */
    public function postActionPreferences(Request $request, Response $response): array
    {
        $userId = $this->requireUserId();

        $data = $this->readBody($request);
        $categories = $data['categories'] ?? null;

        if (!is_array($categories)) {
            throw new BadRequest('Field "categories" must be an array.');
        }

        $this->preferences->setMuted($userId, $categories);

        return [
            'result' => 'success',
            'mutedCategories' => $this->preferences->mutedFor($userId),
            'availableCategories' => $this->preferences->availableCategories(),
        ];
    }

    /**
     * Stores the subscription returned by PushManager.subscribe().
     */
    public function postActionSubscribe(Request $request, Response $response): array
    {
        $userId = $this->requireUserId();

        $data = $this->readBody($request);

        $endpoint = trim((string) ($data['endpoint'] ?? ''));

        if ($endpoint === '') {
            throw new BadRequest('Subscription endpoint is required.');
        }

        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            throw new BadRequest('Subscription endpoint is not a valid URL.');
        }

        $keys = isset($data['keys']) && is_array($data['keys']) ? $data['keys'] : [];

        $publicKey = trim((string) ($keys['p256dh'] ?? ''));
        $authKey = trim((string) ($keys['auth'] ?? ''));

        if (!$this->isValidKey($publicKey, 65)) {
            throw new BadRequest('Subscription p256dh key is invalid.');
        }

        if (!$this->isValidKey($authKey, 16)) {
            throw new BadRequest('Subscription auth key is invalid.');
        }

        $id = $this->store->save(
            $userId,
            $endpoint,
            $publicKey,
            $authKey,
            SubscriptionStore::normalizeExpirationTime($data['expirationTime'] ?? null),
            $request->getHeader('User-Agent')
        );

        return [
            'result' => 'success',
            'id' => $id,
            'subscriptionCount' => $this->store->countActiveForUser($userId),
        ];
    }

    /**
     * Removes a subscription. Called when the user opts out or the browser
     * reports that the subscription is no longer usable.
     */
    public function postActionUnsubscribe(Request $request, Response $response): array
    {
        $userId = $this->requireUserId();

        $data = $this->readBody($request);
        $endpoint = trim((string) ($data['endpoint'] ?? ''));

        if ($endpoint !== '') {
            $this->store->remove($userId, $endpoint);
        }

        return [
            'result' => 'success',
            'subscriptionCount' => $this->store->countActiveForUser($userId),
        ];
    }

    /**
     * Sends a push to every device of the caller. Used to verify the whole
     * chain without waiting for a scheduled job.
     *
     * Body: {"title": "...", "body": "...", "url": "#Call/view/123"}
     */
    public function postActionSendTest(Request $request, Response $response): array
    {
        $userId = $this->requireUserId();

        $data = $this->readBody($request);

        $title = trim((string) ($data['title'] ?? ''));
        $title = $title !== '' ? $title : 'EspoCRM test notification';

        $body = trim((string) ($data['body'] ?? ''));
        $body = $body !== '' ? $body : 'Web Push is working for this device.';

        $url = trim((string) ($data['url'] ?? ''));

        $payload = PushNotificationService::buildPayload([
            'type' => 'test',
            'category' => 'systemAlert',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'tag' => 'espocrm-test-' . $userId,
        ]);

        $summary = $this->pushNotificationService->sendToUser($userId, $payload, [
            'category' => 'systemAlert',
            'ttl' => 120,
            'urgency' => 'high',
        ]);

        if (!$summary['configured']) {
            return [
                'result' => 'failed',
                'error' => 'notConfigured',
                'reason' => 'VAPID keys are not configured.',
                'sent' => 0,
                'success' => false,
                'summary' => $summary,
            ];
        }

        if ($summary['sent'] === 0 && $summary['skipped'] === 0) {
            return [
                'result' => 'failed',
                'error' => 'noSubscription',
                'reason' => 'No active push subscription for this account.',
                'sent' => 0,
                'success' => false,
                'summary' => $summary,
            ];
        }

        if ($summary['skipped'] > 0) {
            return [
                'result' => 'skipped',
                'error' => 'muted',
                'reason' => 'Desktop notifications are muted for this category.',
                'sent' => 0,
                'success' => false,
                'summary' => $summary,
            ];
        }

        $success = !($summary['failed'] > 0 && $summary['sent'] === 0);

        return [
            'result' => $success ? 'success' : 'failed',
            'sent' => $summary['sent'],
            'success' => $success,
            'summary' => $summary,
        ];
    }

    private function requireUserId(): string
    {
        $userId = (string) $this->user->getId();

        if ($userId === '' || $userId === 'anonymous') {
            throw new Forbidden('Authentication is required.');
        }

        return $userId;
    }

    private function isValidKey(string $key, int $byteLength): bool
    {
        if ($key === '' || !Base64Url::isValid($key)) {
            return false;
        }

        try {
            $decoded = Base64Url::decode($key);
        } catch (\Throwable) {
            return false;
        }

        if (strlen($decoded) !== $byteLength) {
            return false;
        }

        return $byteLength !== 65 || $decoded[0] === "\x04";
    }

    /**
     * Accepts either a JSON body or a form encoded body.
     *
     * @return array<string, mixed>
     */
    private function readBody(Request $request): array
    {
        $raw = $request->getBodyContents();

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $parsed = [];

        parse_str($raw, $parsed);

        return is_array($parsed) ? $parsed : [];
    }
}
