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
use Espo\Entities\Preferences;

/**
 * Per-user Web Push categories.
 *
 * Categories are opt-out: a user who has never touched the preference gets
 * every category, so adding a new notification type never silently stops
 * delivering to existing users.
 *
 * Storage lives on the singleton Preferences record of the user (its id is
 * the user id), which keeps the preference server side and tied to the
 * authenticated account rather than to one browser.
 */
final class PushPreferences
{
    public const FIELD_MUTED = 'webPushMutedCategories';

    /** Category id => default label. */
    public const CATEGORIES = [
        'callRoute' => 'Call reminders',
        'task' => 'Task reminders',
        'meeting' => 'Meeting reminders',
        'calendar' => 'Calendar events',
        'assignment' => 'Assignment notifications',
        'mention' => 'Mention notifications',
        'recordUpdate' => 'Record updates',
        'message' => 'New messages',
        'approval' => 'Approval requests',
        'workflow' => 'Workflow notifications',
        'systemAlert' => 'System alerts',
        'custom' => 'Custom notifications',
    ];

    /** An unknown/omitted category is always allowed. */
    public const CATEGORY_DEFAULT = 'custom';

    public function __construct(private EntityManager $entityManager)
    {}

    /**
     * @return string[]
     */
    public function availableCategories(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /**
     * @return string[]
     */
    public function mutedFor(string $userId): array
    {
        if ($userId === '') {
            return [];
        }

        $preferences = $this->entityManager
            ->getRepositoryByClass(Preferences::class)
            ->getById($userId);

        if ($preferences === null) {
            return [];
        }

        $value = $preferences->get(self::FIELD_MUTED);

        if (!is_array($value)) {
            return [];
        }

        $available = $this->availableCategories();
        $muted = [];

        foreach ($value as $category) {
            if (is_string($category) && in_array($category, $available, true)) {
                $muted[] = $category;
            }
        }

        return array_values(array_unique($muted));
    }

    /**
     * Whether a push is allowed for the user in this category.
     */
    public function allows(string $userId, ?string $category): bool
    {
        $category = $category !== null && $category !== '' ? $category : self::CATEGORY_DEFAULT;

        if (!in_array($category, $this->availableCategories(), true)) {
            /* An unrecognised category must not be dropped silently: the
               caller knows more than this list does. */
            return true;
        }

        return !in_array($category, $this->mutedFor($userId), true);
    }

    /**
     * @param string[] $categories
     */
    public function setMuted(string $userId, array $categories): void
    {
        if ($userId === '') {
            return;
        }

        $available = $this->availableCategories();
        $muted = [];

        foreach ($categories as $category) {
            if (is_string($category) && in_array($category, $available, true)) {
                $muted[] = $category;
            }
        }

        $muted = array_values(array_unique($muted));

        $repository = $this->entityManager->getRepositoryByClass(Preferences::class);

        $preferences = $repository->getById($userId);

        if ($preferences === null) {
            $preferences = $this->entityManager->getNewEntity('Preferences');
            $preferences->setId($userId);
        }

        $preferences->set(self::FIELD_MUTED, $muted);

        $this->entityManager->saveEntity($preferences);
    }
}
