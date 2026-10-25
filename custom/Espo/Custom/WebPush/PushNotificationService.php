<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM — Open Source CRM application.
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

namespace Espo\Custom\WebPush;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Throwable;

/**
 * Path B: delivers a notification to every registered device of a user.
 *
 * This class knows nothing about the feature that produced the event. It
 * receives a payload, checks the user's category preference, pushes to all
 * active subscriptions and cleans up the ones that are gone for good.
 *
 * It never throws: the persistent database notification is the source of
 * truth, so a push failure must not roll anything back.
 */
final class PushNotificationService
{
    /**
     * Push services reject bodies larger than 4 KB once the encryption
     * overhead is added, so the plain payload is kept well below that.
     */
    public const MAX_PAYLOAD_BYTES = 3000;

    public const DEFAULT_TTL = 3600;
    public const DEFAULT_URGENCY = 'high';

    private const TITLE_MAX = 200;
    private const BODY_MAX = 1000;

    private ?SubscriptionStore $store = null;
    private ?PushPreferences $preferences = null;
    private ?PushSender $sender = null;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * Test seam: lets tests inject a sender that does not touch the network.
     */
    public function setSender(PushSender $sender): void
    {
        $this->sender = $sender;
    }

    /**
     * Builds the generic payload shared by the service worker and the API.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function buildPayload(array $params): array
    {
        $entityType = self::text($params['entityType'] ?? null);
        $entityId = self::text($params['entityId'] ?? null);

        $url = self::text($params['url'] ?? null);

        if ($url === '' && $entityType !== '' && $entityId !== '') {
            $url = '#' . $entityType . '/view/' . $entityId;
        }

        $timestamp = $params['timestamp'] ?? null;
        $timestamp = is_numeric($timestamp) ? (int) $timestamp : time();

        $type = self::text($params['type'] ?? null);
        $category = self::text($params['category'] ?? null);

        $payload = [
            'notificationId' => self::text($params['notificationId'] ?? null),
            'type' => $type !== '' ? $type : 'notification',
            'title' => self::limit(self::text($params['title'] ?? null), self::TITLE_MAX),
            'body' => self::limit(self::text($params['body'] ?? null), self::BODY_MAX),
            'entityType' => $entityType,
            'entityId' => $entityId,
            'url' => $url,
            'timestamp' => $timestamp,
        ];

        if ($category !== '') {
            $payload['category'] = $category;
        }

        $tag = self::text($params['tag'] ?? null);

        if ($tag !== '') {
            $payload['tag'] = self::limit($tag, 100);
        }

        /* Kept out of the payload unless it is on: the service worker only
           has to act on it for notifications that should stay on screen. */
        if (($params['requireInteraction'] ?? false) === true) {
            $payload['requireInteraction'] = true;
        }

        return $payload;
    }

    /**
     * Pushes a payload to every active device of one user.
     *
     * @param array<string, mixed> $payload Result of buildPayload().
     * @param array{ttl?:int,urgency?:string,category?:?string} $options
     * @return array{sent:int,skipped:int,failed:int,removed:int,configured:bool}
     */
    public function sendToUser(string $userId, array $payload, array $options = []): array
    {
        $summary = [
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'removed' => 0,
            'configured' => false,
        ];

        if ($userId === '' || $userId === 'anonymous') {
            return $summary;
        }

        /* Configuration is checked first so a caller can always tell
           "Web Push is not set up" apart from "there was nothing to send". */
        $sender = $this->getSender();

        if (!$sender->isConfigured()) {
            $this->log->debug(
                'Web Push skipped: VAPID keys are not configured.'
            );

            return $summary;
        }

        $summary['configured'] = true;

        $category = $options['category'] ?? ($payload['category'] ?? null);

        if (!$this->getPreferences()->allows($userId, $category)) {
            $summary['skipped'] = 1;

            return $summary;
        }

        $store = $this->getStore();

        $subscriptionList = self::uniqueByEndpoint($store->listForUser($userId));

        if ($subscriptionList === []) {
            return $summary;
        }

        $encoded = $this->encode($payload);

        $ttl = isset($options['ttl']) && is_int($options['ttl']) && $options['ttl'] > 0
            ? $options['ttl']
            : self::DEFAULT_TTL;

        $urgency = isset($options['urgency']) && is_string($options['urgency'])
            ? $options['urgency']
            : self::DEFAULT_URGENCY;

        foreach ($subscriptionList as $subscription) {
            try {
                $result = $sender->send(
                    $subscription['endpoint'],
                    $subscription['publicKey'],
                    $subscription['authKey'],
                    $encoded,
                    $ttl,
                    $urgency
                );
            } catch (Throwable $e) {
                $summary['failed']++;

                $this->log->warning(
                    'Web Push to user ' . $userId . ' threw: ' . $e->getMessage()
                );

                continue;
            }

            if ($result->isSubscriptionExpired()) {
                /* 404 / 410: the push service tells us this endpoint will
                   never work again. Stop retrying it forever. */
                $store->removeById($subscription['id']);
                $summary['removed']++;

                $this->log->debug(
                    'Web Push removed an expired subscription for user ' . $userId . '.'
                );

                continue;
            }

            if ($result->isSuccess()) {
                $store->markUsed($subscription['id']);
                $summary['sent']++;

                continue;
            }

            if ($result->isTransportError()) {
                /* Nothing conclusive: keep the subscription and try again
                   with the next event. */
                $summary['failed']++;

                $this->log->warning(
                    'Web Push transport error for user ' . $userId . ': ' . $result->describe()
                );

                continue;
            }

            $summary['failed']++;

            $this->log->warning(
                'Web Push failed for user ' . $userId . ': ' . $result->describe()
            );
        }

        return $summary;
    }

    /**
     * Pushes the same payload to several users, one independent summary each.
     *
     * @param string[] $userIds
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, array{sent:int,skipped:int,failed:int,removed:int,configured:bool}>
     */
    public function sendToUsers(array $userIds, array $payload, array $options = []): array
    {
        $result = [];

        foreach (array_unique(array_values($userIds)) as $userId) {
            if (!is_string($userId)) {
                continue;
            }

            $result[$userId] = $this->sendToUser($userId, $payload, $options);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            $encoded = json_encode([
                'title' => 'EspoCRM',
                'body' => '',
            ]);
        }

        if (is_string($encoded) && strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            /* Keep the identifiers, shrink the prose. The record still opens
               from entityType/entityId even when the body had to be cut. */
            $payload['body'] = self::limit(
                $payload['body'] ?? '',
                max(0, self::BODY_MAX - (strlen($encoded) - self::MAX_PAYLOAD_BYTES) - 64)
            );

            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    private static function uniqueByEndpoint(array $list): array
    {
        $seen = [];
        $result = [];

        foreach ($list as $item) {
            $endpoint = (string) ($item['endpoint'] ?? '');

            if ($endpoint === '' || isset($seen[$endpoint])) {
                continue;
            }

            $seen[$endpoint] = true;
            $result[] = $item;
        }

        return $result;
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        /* Control characters break notification rendering and can smuggle
           data into the OS notification layer. */
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return is_string($value) ? trim($value) : '';
    }

    private static function limit(string $value, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
        }

        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    private function getStore(): SubscriptionStore
    {
        return $this->store ??= new SubscriptionStore($this->entityManager);
    }

    private function getPreferences(): PushPreferences
    {
        return $this->preferences ??= new PushPreferences($this->entityManager);
    }

    private function getSender(): PushSender
    {
        return $this->sender ??= new PushSender();
    }
}
