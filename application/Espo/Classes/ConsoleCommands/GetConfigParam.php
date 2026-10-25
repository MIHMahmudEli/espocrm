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
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Json;

/**
 * @noinspection PhpUnused
 */
class GetConfigParam implements Command
{
    public function __construct(
        private Config $config,
    ) {}

    public function run(Params $params, IO $io): void
    {
        $param = $params->getArgument(0) ?? null;

        if ($param === null) {
            $io->writeLine("Parameter name is not specified.");
            $io->setExitStatus(1);

            return;
        }

        if (!$this->config->has($param)) {
            $io->writeLine("Parameter '$param' is not set in config.");
            $io->setExitStatus(1);

            return;
        }

        $isJson = $params->hasFlag('json');

        $value = $this->config->get($param);

        $value = $this->formatValue($value, $isJson);

        $io->writeLine($value);
    }

    private function formatValue(mixed $value, bool $isJson): string
    {
        if ($isJson) {
            return self::toJson($value);
        }

        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => self::toJson($value),
        };
    }

    private static function toJson(mixed $value): string
    {
        return Json::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
