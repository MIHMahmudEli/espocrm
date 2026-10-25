<?php

namespace Espo\Custom\Jobs;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Name\Field;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Custom\Notification\NotificationService;
use Espo\Entities\Notification;
use Espo\Modules\Crm\Entities\Call;
use Throwable;

/**
 * Fires when a Call Route (Call) reaches its Date Start.
 *
 * Scheduling, windowing and deduplication stay here because they belong to
 * the Call Route feature. Everything about *telling the user* has moved to
 * the global NotificationService, which produces both delivery paths:
 *
 *     Date Start reached ──► NotificationService.notifyUser()
 *                                 ├── persistent Notification (+ popup, WebSocket)
 *                                 └── Web Push to every device of the user
 *
 * Adding Task or Meeting reminders means repeating this pattern with a
 * different scope - never adding push code to the job.
 */
class CallRouteReminders implements JobDataLess
{
    public const DEFAULT_PAST_HOURS = 24;

    public const FIELD_MARKER = 'cCallReminderNotifiedFor';

    public const CATEGORY = 'callRoute';

    private const DEFAULT_MESSAGE =
        '**Call Reminder**: {entityType} {entity} is due now. Please make the call.';

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Config $config,
        private Language $language,
        private Log $log,
        private NotificationService $notificationService,
    ) {}

    public function run(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $pastHours = (int) $this->config->get('reminderPastHours', self::DEFAULT_PAST_HOURS);

        if ($pastHours < 1) {
            $pastHours = self::DEFAULT_PAST_HOURS;
        }

        $nowString = $now->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $windowString = $now
            ->sub(new DateInterval('PT' . $pastHours . 'H'))
            ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $where = [
            'dateStart<=' => $nowString,
            'dateStart>' => $windowString,
        ];

        $statusList = $this->getStatusList();

        if ($statusList !== []) {
            $where['status'] = $statusList;
        }

        /** @var iterable<Call> $callList */
        $callList = $this->entityManager
            ->getRDBRepositoryByClass(Call::class)
            ->where($where)
            ->find();

        foreach ($callList as $call) {
            try {
                $this->processCall($call);
            } catch (Throwable $e) {
                $this->log->error(sprintf(
                    'Job CallRouteReminders: %s [%s] %s (Call %s)',
                    get_class($e),
                    (string) $e->getCode(),
                    $e->getMessage(),
                    $call->getId()
                ));
            }
        }
    }

    private function processCall(Call $call): void
    {
        $userId = $call->get('assignedUserId');

        if (!$userId) {
            return;
        }

        $dateStart = $call->get('dateStart');

        if (!$dateStart) {
            return;
        }

        $callId = $call->getId();

        if ($call->get(self::FIELD_MARKER) === $dateStart) {
            return;
        }

        $actionId = $this->buildActionId($callId, $dateStart);

        $existing = $this->entityManager
            ->getRDBRepository(Notification::ENTITY_TYPE)
            ->where([
                Notification::ATTR_USER_ID => $userId,
                Notification::ATTR_ACTION_ID => $actionId,
            ])
            ->findOne();

        if ($existing !== null) {
            $this->setMarker($callId, $dateStart);

            return;
        }

        $previousDateStart = $call->get(self::FIELD_MARKER);

        if (is_string($previousDateStart) && $previousDateStart !== '') {
            /* The call was rescheduled: drop the reminder that pointed at
               the old time so the list never shows a stale one. */
            $this->removeNotification($callId, $previousDateStart);
        }

        $entityName = $this->resolveName($call);

        $notification = $this->notificationService->notifyUser(
            (string) $userId,
            [
                'message' => $this->renderMessage($call, $entityName),
                'entityType' => Call::ENTITY_TYPE,
                'entityId' => $callId,
                'entityName' => $entityName,
                'url' => '#Call/view/' . $callId,
                'note' => [
                    'userId' => $userId,
                ],
            ],
            [
                'actionId' => $actionId,
                'related' => $call,
                'popup' => [
                    'entityType' => Call::ENTITY_TYPE,
                    'entityId' => $callId,
                    'startAt' => (string) $dateStart,
                ],
                'category' => self::CATEGORY,
                'payloadType' => 'call-route-reminder',
                'pushTitle' => $this->getWebPushTitle(),
                'pushBody' => $this->getWebPushBody(),
                'tag' => 'call-route-' . $callId . '-' . md5((string) $dateStart),
                'urgency' => 'high',
            ]
        );

        if ($notification === null) {
            /* Nothing was persisted - most likely the service already had
               this event. Leave the marker alone so the next run can retry. */
            return;
        }

        $this->setMarker($callId, $dateStart);
    }

    /**
     * Drops the notification created for a Date Start that no longer exists
     * (the call was rescheduled).
     */
    private function removeNotification(string $callId, string $dateStart): void
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from(Notification::ENTITY_TYPE)
            ->where([
                Notification::ATTR_ACTION_ID => $this->buildActionId($callId, $dateStart),
            ])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }

    private function setMarker(string $callId, string $dateStart): void
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in(Call::ENTITY_TYPE)
            ->set([
                self::FIELD_MARKER => $dateStart,
            ])
            ->where([
                'id' => $callId,
            ])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }

    private function buildActionId(string $callId, string $dateStart): string
    {
        return md5(Call::ENTITY_TYPE . '|' . $callId . '|' . $dateStart);
    }

    private function resolveName(Call $call): string
    {
        $name = (string) $call->get(Field::NAME);

        if ($name !== '') {
            return $name;
        }

        $parentType = $call->get('parentType');
        $parentId = $call->get('parentId');

        if (!$parentType || !$parentId || !$this->entityManager->hasRepository($parentType)) {
            return $name;
        }

        $parent = $this->entityManager->getEntityById($parentType, $parentId);

        return $parent ? (string) $parent->get(Field::NAME) : $name;
    }

    private function getStatusList(): array
    {
        $statusList = $this->metadata->get('scopes.Call.activityStatusList');

        if (is_array($statusList) && $statusList !== []) {
            return array_values($statusList);
        }

        $options = $this->metadata->get('entityDefs.Call.fields.status.options');

        if (!is_array($options) || $options === []) {
            return [];
        }

        $ignoreList = array_merge(
            (array) $this->metadata->get('scopes.Call.completedStatusList'),
            (array) $this->metadata->get('scopes.Call.canceledStatusList')
        );

        return array_values(array_diff($options, $ignoreList));
    }

    /**
     * Substitutes the placeholders of the translated message so the stored
     * notification reads correctly in the panel.
     */
    private function renderMessage(Call $call, string $entityName): string
    {
        return str_replace(
            ['{entityType}', '{entity}'],
            [Call::ENTITY_TYPE, $entityName],
            $this->getMessage()
        );
    }

    private function getMessage(): string
    {
        $message = $this->language->translate('callReminder', 'messages', Call::ENTITY_TYPE);

        if (!$message || $message === 'callReminder') {
            return self::DEFAULT_MESSAGE;
        }

        return $message;
    }

    private function getWebPushTitle(): string
    {
        $title = $this->language->translate('webPushCallTitle', 'messages', Call::ENTITY_TYPE);

        if (!$title || $title === 'webPushCallTitle') {
            return 'Call Reminder';
        }

        return $title;
    }

    private function getWebPushBody(): string
    {
        $body = $this->language->translate('webPushCallBody', 'messages', Call::ENTITY_TYPE);

        if (!$body || $body === 'webPushCallBody') {
            $body = '{entity} is due now. Please make the call.';
        }

        return $body;
    }
}
