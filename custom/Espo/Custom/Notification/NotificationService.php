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

namespace Espo\Custom\Notification;

use Espo\Core\Field\LinkParent;
use Espo\Core\ORM\Entity;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\WebSocket\Submission as WebSocketSubmission;
use Espo\Custom\WebPush\PushNotificationService;
use Espo\Entities\Notification;
use Espo\Modules\Crm\Entities\Reminder;
use Espo\Entities\User;
use Throwable;

/**
 * The single entry point for telling a user about something.
 *
 * Every feature - Call Route reminders, Task reminders, Meeting reminders,
 * assignments, mentions, approvals, workflow output, system alerts - calls
 * notifyUser() and gets both delivery paths for free:
 *
 *     Event ──► notifyUser()
 *                   ├── Path A: persistent Notification + popup + WebSocket
 *                   └── Path B: Web Push to every device of the user
 *
 * The persistent notification is the source of truth. Web Push is only a
 * delivery channel, and every failure inside it is swallowed so that Path A
 * is never lost because a browser subscription went stale.
 *
 * Usage:
 *
 *     $notificationService->notifyUser($userId, [
 *         'message'    => 'Call with ABC Company is due now.',
 *         'entityType' => 'Call',
 *         'entityId'   => $callId,
 *         'entityName' => $callName,
 *     ], [
 *         'actionId'  => md5('Call|' . $callId . '|' . $dateStart),
 *         'category'  => 'callRoute',
 *         'pushTitle' => 'Call Reminder',
 *         'pushBody'  => $callName . ' is due now.',
 *     ]);
 */
class NotificationService
{
    private const DEFAULT_PUSH_TTL = 3600;

    /** notification.action_id column width. */
    private const ACTION_ID_MAX_LENGTH = 36;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private Config $config,
        private WebSocketSubmission $webSocketSubmission,
        private PushNotificationService $pushNotificationService
    ) {}

    /**
     * Notifies a single user.
     *
     * @param array<string, mixed> $data
     *      message      string  Body shown in the notification panel.
     *      type         string  Notification type, defaults to Message.
     *      entityType   string  Record type the notification points at.
     *      entityId     string
     *      entityName   string  Human readable name for the push body.
     *      url          string  Client route, e.g. "#Call/view/123".
     *      note         array   Extra data stored on the notification row.
     * @param array<string, mixed> $options
     *      actionId     string  Deduplication key, unique per user. When a
     *                            notification with this actionId already
     *                            exists nothing is created and nothing is
     *                            pushed, so a scheduler that runs ten times
     *                            produces exactly one notification.
     *      related      Entity  Record to link (linkParent).
     *      popup        bool|array  Also create a popup Reminder. Array form:
     *                            ["entityType"=>..,"entityId"=>..,"startAt"=>..]
     *      persistent   bool    Create the database notification (default true).
     *      webPush      bool    Dispatch Web Push (default true).
     *      webSocket    bool    Submit the WebSocket refresh (default true).
     *      pushTitle    string
     *      pushBody     string
     *      category     string  Web Push category for user preferences.
     *      payloadType  string  "type" field of the push payload.
     *      tag          string  OS notification tag (replaces, not stacks).
     *      ttl          int     Seconds the push service may queue it.
     *      urgency      string  very-low | low | normal | high.
     * @return Notification|null Null when nothing was created (deduplicated
     *                           or the target user does not exist).
     */
    public function notifyUser(string $userId, array $data = [], array $options = []): ?Notification
    {
        if ($userId === '' || $userId === 'anonymous') {
            return null;
        }

        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if (!$user || $user->get('deleted')) {
            return null;
        }

        $actionId = isset($options['actionId']) && is_string($options['actionId'])
            ? $options['actionId']
            : null;

        if ($actionId !== null && $actionId !== '') {
            $actionId = self::normalizeActionId($actionId);
        }

        if ($actionId !== null && $actionId !== '') {
            $existing = $this->entityManager
                ->getRDBRepository(Notification::ENTITY_TYPE)
                ->where([
                    Notification::ATTR_USER_ID => $userId,
                    Notification::ATTR_ACTION_ID => $actionId,
                ])
                ->findOne();

            if ($existing !== null) {
                /* Already delivered for this logical event: no second row,
                   no second Windows notification. */
                return null;
            }
        }

        $notification = null;
        $persistent = $options['persistent'] ?? true;

        if ($persistent) {
            $notification = $this->createNotification($userId, $user, $data, $actionId, $options);

            if ($notification !== null && ($options['popup'] ?? false)) {
                $this->ensurePopupReminder($userId, $notification, $data, $options);
            }

            if ($notification !== null && $notification->getId()) {
                $data['notificationId'] = $notification->getId();
            }
        }

        if (($options['webSocket'] ?? true) !== false) {
            $this->submitWebSocket($userId);
        }

        if (($options['webPush'] ?? true) !== false && $this->isPushEnabled()) {
            $this->sendWebPush($userId, $data, $options);
        }

        return $notification;
    }

    /**
     * Master switch for Path B. Missing means enabled, so an installation
     * that never touches the setting keeps working.
     */
    private function isPushEnabled(): bool
    {
        return $this->config->get('webPushEnabled') !== false;
    }

    /**
     * Per-recipient dedup key for notifyUsers().
     *
     * notification.action_id is a varchar(36), so a key built by appending a
     * 17 character user id to a 32 character digest would be silently
     * rejected by MySQL. The composite is digested instead, which keeps the
     * key deterministic and inside the column.
     */
    public static function userActionId(string $actionId, string $userId): string
    {
        return self::normalizeActionId($actionId . '|' . $userId);
    }

    private static function normalizeActionId(string $actionId): string
    {
        return strlen($actionId) <= self::ACTION_ID_MAX_LENGTH ? $actionId : md5($actionId);
    }

    /**
     * Notifies several users with the same content.
     *
     * @param string[] $userIds
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return array<string, Notification|null>
     */
    public function notifyUsers(array $userIds, array $data = [], array $options = []): array
    {
        $result = [];

        foreach ($userIds as $userId) {
            if (!is_string($userId) || $userId === '') {
                continue;
            }

            /* Each recipient needs their own dedup key or the first row would
               silently swallow everybody else. */
            $userOptions = $options;

            if (isset($options['actionId']) && is_string($options['actionId'])) {
                $userOptions['actionId'] = self::userActionId($options['actionId'], $userId);
            }

            $result[$userId] = $this->notifyUser($userId, $data, $userOptions);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    private function createNotification(
        string $userId,
        User $user,
        array $data,
        ?string $actionId,
        array $options
    ): ?Notification {
        $message = isset($data['message']) && is_string($data['message']) ? $data['message'] : '';

        if ($message === '') {
            $this->log->warning('NotificationService: skipped a notification without a message.');

            return null;
        }

        $type = isset($data['type']) && is_string($data['type']) && $data['type'] !== ''
            ? $data['type']
            : Notification::TYPE_MESSAGE;

        $entityType = isset($data['entityType']) && is_string($data['entityType'])
            ? $data['entityType']
            : null;
        $entityId = isset($data['entityId']) && is_string($data['entityId'])
            ? $data['entityId']
            : null;

        $note = [];

        if (isset($data['note']) && is_array($data['note'])) {
            $note = $data['note'];
        }

        $noteData = array_merge($note, [
            'entityType' => $entityType,
            'entityId' => $entityId,
            'entityName' => $data['entityName'] ?? null,
            'userId' => $userId,
            'userName' => $user->getName() ?? null,
        ]);

        $notification = $this->entityManager
            ->getRDBRepositoryByClass(Notification::class)
            ->getNew();

        $notification
            ->setType($type)
            ->setUserId($userId)
            ->setMessage($message)
            ->setData($noteData);

        if ($actionId !== null && $actionId !== '') {
            $notification->set('actionId', $actionId);
        }

        $related = $options['related'] ?? null;

        if ($related instanceof Entity) {
            $notification->setRelated(LinkParent::fromEntity($related));
        } elseif ($related instanceof LinkParent) {
            $notification->setRelated($related);
        } elseif ($entityType !== null && $entityId !== null && $entityType !== '') {
            try {
                $notification->set('relatedType', $entityType);
                $notification->set('relatedId', $entityId);
            } catch (Throwable) {
                /* Linking is a convenience; never lose the notification. */
            }
        }

        try {
            $this->entityManager->saveEntity($notification);
        } catch (Throwable $e) {
            $this->log->error('NotificationService: could not save notification: ' . $e->getMessage());

            return null;
        }

        return $notification;
    }

    /**
     * The popup toast shown while the app is open.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    private function ensurePopupReminder(
        string $userId,
        Notification $notification,
        array $data,
        array $options
    ): void {
        $popup = $options['popup'];

        if (!is_array($popup)) {
            return;
        }

        $entityType = isset($popup['entityType']) && is_string($popup['entityType'])
            ? $popup['entityType']
            : ($data['entityType'] ?? null);
        $entityId = isset($popup['entityId']) && is_string($popup['entityId'])
            ? $popup['entityId']
            : ($data['entityId'] ?? null);

        if (!$entityType || !$entityId) {
            return;
        }

        $startAt = isset($popup['startAt']) && is_string($popup['startAt'])
            ? $popup['startAt']
            : ($data['dateStart'] ?? null);

        if (!is_string($startAt) || $startAt === '') {
            $startAt = gmdate('Y-m-d H:i:s');
        }

        $exists = $this->entityManager
            ->getRDBRepository(Reminder::ENTITY_TYPE)
            ->where([
                'type' => Reminder::TYPE_POPUP,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'userId' => $userId,
                'seconds' => 0,
            ])
            ->findOne();

        if ($exists !== null) {
            if ($exists->get('startAt') !== $startAt || $exists->get('remindAt') !== $startAt) {
                $exists
                    ->set('startAt', $startAt)
                    ->set('remindAt', $startAt)
                    ->set('isSubmitted', false);

                $this->entityManager->saveEntity($exists);
            }

            return;
        }

        $reminder = $this->entityManager
            ->getRDBRepositoryByClass(Reminder::class)
            ->getNew();

        $reminder
            ->set('type', Reminder::TYPE_POPUP)
            ->set('entityType', $entityType)
            ->set('entityId', $entityId)
            ->set('userId', $userId)
            ->set('seconds', 0)
            ->set('startAt', $startAt)
            ->set('remindAt', $startAt)
            ->set('isSubmitted', false);

        try {
            $this->entityManager->saveEntity($reminder);
        } catch (Throwable $e) {
            $this->log->warning('NotificationService: popup reminder failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    private function sendWebPush(string $userId, array $data, array $options): void
    {
        try {
            $entityType = isset($data['entityType']) && is_string($data['entityType'])
                ? $data['entityType']
                : '';
            $entityId = isset($data['entityId']) && is_string($data['entityId'])
                ? $data['entityId']
                : '';
            $entityName = isset($data['entityName']) && is_string($data['entityName'])
                ? $data['entityName']
                : '';

            $title = isset($options['pushTitle']) && is_string($options['pushTitle'])
                ? $options['pushTitle']
                : null;

            if ($title === null || $title === '') {
                $title = 'EspoCRM';
            }

            $body = isset($options['pushBody']) && is_string($options['pushBody'])
                ? $options['pushBody']
                : (isset($data['message']) && is_string($data['message']) ? $data['message'] : '');

            if ($entityName !== '' && str_contains($body, '{entity}')) {
                $body = str_replace('{entity}', $entityName, $body);
            }

            $payload = PushNotificationService::buildPayload([
                'notificationId' => $data['notificationId'] ?? '',
                'type' => $options['payloadType'] ?? 'notification',
                'title' => $title,
                'body' => $body,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'url' => $data['url'] ?? '',
                'category' => $options['category'] ?? null,
                'tag' => $options['tag'] ?? $this->defaultTag($options, $entityType, $entityId),
                'requireInteraction' => ($options['requireInteraction'] ?? false) === true,
            ]);

            $ttl = isset($options['ttl']) && is_int($options['ttl'])
                ? $options['ttl']
                : self::DEFAULT_PUSH_TTL;

            $urgency = isset($options['urgency']) && is_string($options['urgency'])
                ? $options['urgency']
                : PushNotificationService::DEFAULT_URGENCY;

            $this->pushNotificationService->sendToUser($userId, $payload, [
                'ttl' => $ttl,
                'urgency' => $urgency,
                'category' => $options['category'] ?? null,
            ]);
        } catch (Throwable $e) {
            /* Web Push is best effort: the database notification already
               exists and stays the source of truth. */
            $this->log->error('NotificationService: web push failed: ' . $e->getMessage());
        }
    }

    /**
     * A stable tag means the OS replaces the previous notification for the
     * same logical event instead of stacking duplicates.
     *
     * Only genuinely related notifications may share a tag: the same action,
     * or the same record. Anything else gets *no* tag, because two unrelated
     * notifications with one tag replace each other and the second one is
     * then never announced.
     *
     * @param array<string, mixed> $options
     */
    private function defaultTag(array $options, string $entityType, string $entityId): string
    {
        if (isset($options['actionId']) && is_string($options['actionId']) && $options['actionId'] !== '') {
            return $options['actionId'];
        }

        if ($entityType !== '' && $entityId !== '') {
            return $entityType . '-' . $entityId;
        }

        return '';
    }

    private function submitWebSocket(string $userId): void
    {
        try {
            $this->webSocketSubmission->submit('newNotification', $userId);
        } catch (Throwable $e) {
            $this->log->debug(
                'NotificationService: WebSocket submit failed: ' . $e->getMessage()
            );
        }
    }
}
