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
use Espo\Modules\Crm\Entities\Meeting;
use Throwable;

/**
 * Fires when a Meeting reaches its Date Start.
 *
 * The twin of CallRouteReminders: the same window, the same marker field
 * trick and the same NotificationService call, only the scope is Meeting.
 * Category "meeting" already exists in the Web Push preferences, so users
 * can mute meeting pushes independently of call pushes.
 */
class MeetingReminders implements JobDataLess
{
    public const DEFAULT_PAST_HOURS = 24;

    public const FIELD_MARKER = 'cMeetingReminderNotifiedFor';

    public const CATEGORY = 'meeting';

    private const DEFAULT_MESSAGE =
        '**Meeting Reminder**: {entityType} {entity} is starting now. Please attend the meeting.';

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

        /** @var iterable<Meeting> $meetingList */
        $meetingList = $this->entityManager
            ->getRDBRepositoryByClass(Meeting::class)
            ->where($where)
            ->find();

        foreach ($meetingList as $meeting) {
            try {
                $this->processMeeting($meeting);
            } catch (Throwable $e) {
                $this->log->error(sprintf(
                    'Job MeetingReminders: %s [%s] %s (Meeting %s)',
                    get_class($e),
                    (string) $e->getCode(),
                    $e->getMessage(),
                    $meeting->getId()
                ));
            }
        }
    }

    private function processMeeting(Meeting $meeting): void
    {
        $userId = $meeting->get('assignedUserId');

        if (!$userId) {
            return;
        }

        $dateStart = $meeting->get('dateStart');

        if (!$dateStart) {
            return;
        }

        $meetingId = $meeting->getId();

        if ($meeting->get(self::FIELD_MARKER) === $dateStart) {
            return;
        }

        $actionId = $this->buildActionId($meetingId, $dateStart);

        $existing = $this->entityManager
            ->getRDBRepository(Notification::ENTITY_TYPE)
            ->where([
                Notification::ATTR_USER_ID => $userId,
                Notification::ATTR_ACTION_ID => $actionId,
            ])
            ->findOne();

        if ($existing !== null) {
            $this->setMarker($meetingId, $dateStart);

            return;
        }

        $previousDateStart = $meeting->get(self::FIELD_MARKER);

        if (is_string($previousDateStart) && $previousDateStart !== '') {
            /* The meeting was rescheduled: drop the reminder that pointed at
               the old time so the list never shows a stale one. */
            $this->removeNotification($meetingId, $previousDateStart);
        }

        $entityName = $this->resolveName($meeting);

        $notification = $this->notificationService->notifyUser(
            (string) $userId,
            [
                'message' => $this->renderMessage($meeting, $entityName),
                'entityType' => Meeting::ENTITY_TYPE,
                'entityId' => $meetingId,
                'entityName' => $entityName,
                'url' => '#Meeting/view/' . $meetingId,
                'note' => [
                    'userId' => $userId,
                ],
            ],
            [
                'actionId' => $actionId,
                'related' => $meeting,
                'popup' => [
                    'entityType' => Meeting::ENTITY_TYPE,
                    'entityId' => $meetingId,
                    'startAt' => (string) $dateStart,
                ],
                'category' => self::CATEGORY,
                'payloadType' => 'meeting-reminder',
                'pushTitle' => $this->getWebPushTitle(),
                'pushBody' => $this->getWebPushBody(),
                'tag' => 'meeting-' . $meetingId . '-' . md5((string) $dateStart),
                'urgency' => 'high',
            ]
        );

        if ($notification === null) {
            /* Nothing was persisted - most likely the service already had
               this event. Leave the marker alone so the next run can retry. */
            return;
        }

        $this->setMarker($meetingId, $dateStart);
    }

    /**
     * Drops the notification created for a Date Start that no longer exists
     * (the meeting was rescheduled).
     */
    private function removeNotification(string $meetingId, string $dateStart): void
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from(Notification::ENTITY_TYPE)
            ->where([
                Notification::ATTR_ACTION_ID => $this->buildActionId($meetingId, $dateStart),
            ])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }

    private function setMarker(string $meetingId, string $dateStart): void
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in(Meeting::ENTITY_TYPE)
            ->set([
                self::FIELD_MARKER => $dateStart,
            ])
            ->where([
                'id' => $meetingId,
            ])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }

    private function buildActionId(string $meetingId, string $dateStart): string
    {
        return md5(Meeting::ENTITY_TYPE . '|' . $meetingId . '|' . $dateStart);
    }

    private function resolveName(Meeting $meeting): string
    {
        $name = (string) $meeting->get(Field::NAME);

        if ($name !== '') {
            return $name;
        }

        $parentType = $meeting->get('parentType');
        $parentId = $meeting->get('parentId');

        if (!$parentType || !$parentId || !$this->entityManager->hasRepository($parentType)) {
            return $name;
        }

        $parent = $this->entityManager->getEntityById($parentType, $parentId);

        return $parent ? (string) $parent->get(Field::NAME) : $name;
    }

    private function getStatusList(): array
    {
        $statusList = $this->metadata->get('scopes.Meeting.activityStatusList');

        if (is_array($statusList) && $statusList !== []) {
            return array_values($statusList);
        }

        $options = $this->metadata->get('entityDefs.Meeting.fields.status.options');

        if (!is_array($options) || $options === []) {
            return [];
        }

        $ignoreList = array_merge(
            (array) $this->metadata->get('scopes.Meeting.completedStatusList'),
            (array) $this->metadata->get('scopes.Meeting.canceledStatusList')
        );

        return array_values(array_diff($options, $ignoreList));
    }

    /**
     * Substitutes the placeholders of the translated message so the stored
     * notification reads correctly in the panel.
     */
    private function renderMessage(Meeting $meeting, string $entityName): string
    {
        return str_replace(
            ['{entityType}', '{entity}'],
            [Meeting::ENTITY_TYPE, $entityName],
            $this->getMessage()
        );
    }

    private function getMessage(): string
    {
        $message = $this->language->translate('meetingReminder', 'messages', Meeting::ENTITY_TYPE);

        if (!$message || $message === 'meetingReminder') {
            return self::DEFAULT_MESSAGE;
        }

        return $message;
    }

    private function getWebPushTitle(): string
    {
        $title = $this->language->translate('webPushMeetingTitle', 'messages', Meeting::ENTITY_TYPE);

        if (!$title || $title === 'webPushMeetingTitle') {
            return 'Meeting Reminder';
        }

        return $title;
    }

    private function getWebPushBody(): string
    {
        $body = $this->language->translate('webPushMeetingBody', 'messages', Meeting::ENTITY_TYPE);

        if (!$body || $body === 'webPushMeetingBody') {
            $body = '{entity} is starting now. Please attend the meeting.';
        }

        return $body;
    }
}
