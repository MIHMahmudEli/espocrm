<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
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
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these applicable laws must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Custom\Hooks\Call;

use Espo\Core\Di\EntityManagerAware;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Keeps the lines that were added or changed on the last `description` edit so
 * that the History panel can show the updated text instead of the full value.
 *
 * A record created through Duplicate has no previous description of its own,
 * but the client sends `X-Duplicate-Source-Id` with the create request. The
 * text is then compared against that source record so the History panel shows
 * what was changed in the duplicate rather than an unrelated line.
 *
 * @implements BeforeSave<Entity>
 */
class DescriptionDiff implements BeforeSave, EntityManagerAware
{
    /**
     * Guard against pathological inputs. The diff is O(n*m) on line counts.
     */
    private const MAX_LINE_COUNT = 500;

    /**
     * `X-Duplicate-Source-Id`, as populated by PHP in the `$_SERVER` array.
     */
    private const DUPLICATE_SOURCE_SERVER_KEY = 'HTTP_X_DUPLICATE_SOURCE_ID';

    private ?EntityManager $entityManager = null;

    public function setEntityManager(EntityManager $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->hasAttribute('cDescriptionDiff') || !$entity->hasAttribute('description')) {
            return;
        }

        if ($entity->isNew()) {
            $entity->set('cDescriptionDiff', $this->computeDuplicateDiff($entity));

            return;
        }

        if (!$entity->isAttributeChanged('description')) {
            return;
        }

        $was = (string) $entity->getFetched('description');
        $became = (string) $entity->get('description');

        $changed = $this->computeChangedLines($was, $became);

        $entity->set('cDescriptionDiff', $changed ? implode("\n", $changed) : null);
    }

    /**
     * A brand new record has no previous description of its own. When it comes
     * from Duplicate, compare against the record it was duplicated from;
     * otherwise there is nothing to show yet.
     */
    private function computeDuplicateDiff(Entity $entity): ?string
    {
        $sourceId = $this->getDuplicateSourceId();

        if ($sourceId === null || $this->entityManager === null) {
            return null;
        }

        $source = $this->entityManager
            ->getRDBRepository($entity->getEntityType())
            ->getById($sourceId);

        if ($source === null || !$source->hasAttribute('description')) {
            return null;
        }

        $changed = $this->computeChangedLines(
            (string) $source->get('description'),
            (string) $entity->get('description')
        );

        return $changed ? implode("\n", $changed) : null;
    }

    private function getDuplicateSourceId(): ?string
    {
        $value = $_SERVER[self::DUPLICATE_SOURCE_SERVER_KEY] ?? null;

        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Line by line comparison. Returns the lines of `$became` that were added
     * or modified, in order. Unchanged and removed lines are not returned.
     *
     * @return string[]|null
     */
    private function computeChangedLines(string $was, string $became): ?array
    {
        if ($was === $became) {
            return null;
        }

        $oldLines = preg_split('/\r\n|\r|\n/', $was) ?: [];
        $newLines = preg_split('/\r\n|\r|\n/', $became) ?: [];

        if (count($oldLines) > self::MAX_LINE_COUNT || count($newLines) > self::MAX_LINE_COUNT) {
            return $newLines;
        }

        $oldCount = count($oldLines);
        $newCount = count($newLines);

        // $lcs[$i][$j] - length of the longest common subsequence of
        // $oldLines[$i..] and $newLines[$j..].
        $lcs = [];

        for ($i = $oldCount; $i >= 0; $i--) {
            $lcs[$i][$newCount] = 0;
        }

        for ($j = $newCount; $j >= 0; $j--) {
            $lcs[$oldCount][$j] = 0;
        }

        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                if ($oldLines[$i] === $newLines[$j]) {
                    $lcs[$i][$j] = $lcs[$i + 1][$j + 1] + 1;

                    continue;
                }

                $lcs[$i][$j] = max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $changed = [];
        $i = 0;
        $j = 0;

        while ($i < $oldCount && $j < $newCount) {
            if ($oldLines[$i] === $newLines[$j]) {
                $i++;
                $j++;

                continue;
            }

            if ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                // The old line is gone.
                $i++;

                continue;
            }

            $changed[] = $newLines[$j];
            $j++;
        }

        while ($j < $newCount) {
            $changed[] = $newLines[$j];
            $j++;
        }

        $changed = array_values(array_filter(
            $changed,
            static fn (string $line): bool => trim($line) !== ''
        ));

        return $changed === [] ? null : $changed;
    }
}
