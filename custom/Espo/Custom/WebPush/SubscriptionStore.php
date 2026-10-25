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

namespace Espo\Custom\WebPush;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\ORM\Entity;

/**
 * Reads and writes PushSubscription records.
 *
 * The scope is registered with "acl": false, so access control is enforced
 * here instead: every operation is keyed by the owning user id.
 */
final class SubscriptionStore
{
    public const ENTITY_TYPE = 'PushSubscription';

    public function __construct(private EntityManager $entityManager)
    {}

    /**
     * Active subscriptions of a user, one per device/browser.
     *
     * Rows deactivated by subscription cleanup are skipped, so a dead
     * endpoint is never retried. The check is done in PHP rather than in the
     * WHERE clause because rows written before the `active` column existed
     * carry NULL, which must still count as active.
     *
     * @return array<int, array{id:string,endpoint:string,publicKey:string,authKey:string}>
     */
    public function listForUser(string $userId): array
    {
        if ($userId === '') {
            return [];
        }

        $collection = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['assignedUserId' => $userId])
            ->find();

        $list = [];

        foreach ($collection as $entity) {
            $endpoint = (string) $entity->get('endpoint');
            $publicKey = (string) $entity->get('publicKey');
            $authKey = (string) $entity->get('authKey');

            if ($endpoint === '' || $publicKey === '' || $authKey === '') {
                continue;
            }

            if ($entity->get('active') === false) {
                continue;
            }

            if ($this->isExpired($entity)) {
                /* The browser told us the subscription can no longer be
                   used. Drop it instead of pushing to a dead endpoint. */
                $this->removeById($entity->getId());

                continue;
            }

            $list[] = [
                'id' => $entity->getId(),
                'endpoint' => $endpoint,
                'publicKey' => $publicKey,
                'authKey' => $authKey,
            ];
        }

        return $list;
    }

    /**
     * Number of usable subscriptions a user has, one per device.
     */
    public function countActiveForUser(string $userId): int
    {
        return count($this->listForUser($userId));
    }

    /**
     * Records a successful delivery so the last known good contact time of
     * every device is visible to administrators.
     */
    public function markUsed(string $id): void
    {
        $entity = $this->entityManager->getRDBRepository(self::ENTITY_TYPE)->getById($id);

        if ($entity === null || $entity->get('deleted')) {
            return;
        }

        $entity->set('lastUsedAt', gmdate(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));
        $entity->set('modifiedAt', gmdate(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));

        $this->entityManager->saveEntity($entity);
    }

    /**
     * Marks a subscription as unusable without deleting the row, so it is
     * skipped from now on.
     */
    public function deactivate(string $id): void
    {
        $entity = $this->entityManager->getRDBRepository(self::ENTITY_TYPE)->getById($id);

        if ($entity === null || $entity->get('deleted')) {
            return;
        }

        $entity->set('active', false);
        $entity->set('modifiedAt', gmdate(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));

        $this->entityManager->saveEntity($entity);
    }

    public function removeById(string $id): void
    {
        $entity = $this->entityManager->getRDBRepository(self::ENTITY_TYPE)->getById($id);

        if ($entity !== null) {
            $this->entityManager->removeEntity($entity);
        }
    }

    /**
     * True when the browser reported an expiration time that has passed.
     */
    private function isExpired(Entity $entity): bool
    {
        $expirationTime = $entity->get('expirationTime');

        if (!is_string($expirationTime) || $expirationTime === '') {
            return false;
        }

        $timestamp = strtotime($expirationTime . ' UTC');

        return $timestamp !== false && $timestamp <= time();
    }

    public function find(string $userId, string $endpoint): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where([
                'assignedUserId' => $userId,
                'endpoint' => $endpoint,
            ])
            ->findOne();
    }

    /**
     * Creates or refreshes a subscription for a user.
     *
     * @param ?string $expirationTime UTC datetime in Espo's system format.
     */
    public function save(
        string $userId,
        string $endpoint,
        string $publicKey,
        string $authKey,
        ?string $expirationTime,
        ?string $userAgent
    ): string {
        $entity = $this->find($userId, $endpoint);

        if ($entity === null) {
            /* A browser endpoint belongs to exactly one account: when the
               same browser signs in as somebody else, drop the old owner. */
            $this->removeByEndpoint($endpoint);

            $entity = $this->entityManager
                ->getRDBRepository(self::ENTITY_TYPE)
                ->getNew();

            $entity->set('assignedUserId', $userId);
            $entity->set('endpoint', $endpoint);
            $entity->set('createdAt', gmdate(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));
        }

        $entity
            ->set('publicKey', $publicKey)
            ->set('authKey', $authKey)
            ->set('expirationTime', $expirationTime)
            ->set('userAgent', $userAgent ? mb_substr($userAgent, 0, 255) : null)
            ->set('active', true)
            ->set('modifiedAt', gmdate(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));

        $this->entityManager->saveEntity($entity);

        return $entity->getId();
    }

    public function remove(string $userId, string $endpoint): void
    {
        $entity = $this->find($userId, $endpoint);

        if ($entity !== null) {
            $this->entityManager->removeEntity($entity);
        }
    }

    /**
     * Removes a subscription whichever user owns it. Used when the push
     * service answers 404/410, which means the endpoint is gone for good.
     */
    public function removeByEndpoint(string $endpoint): void
    {
        $entity = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['endpoint' => $endpoint])
            ->findOne();

        if ($entity !== null) {
            $this->entityManager->removeEntity($entity);
        }
    }

    /**
     * Converts an RFC 8030 expirationTime (epoch seconds, may be a float or
     * null) into an Espo datetime string.
     */
    public static function normalizeExpirationTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = is_numeric($value) ? (float) $value : strtotime((string) $value);

        if ($timestamp === false || $timestamp <= 0) {
            return null;
        }

        return gmdate(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT, (int) $timestamp);
    }
}
