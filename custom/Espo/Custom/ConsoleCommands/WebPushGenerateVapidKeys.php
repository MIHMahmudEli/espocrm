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

namespace Espo\Custom\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Custom\WebPush\ProjectPath;
use Espo\Custom\WebPush\VapidKeys;

/**
 * Generates the VAPID key pair used to authorise Web Push requests.
 *
 * Usage:
 *   php command.php web-push-generate-vapid-keys [--subject=mailto:admin@example.com] [--force]
 *
 * Keys are stored in `data/webpush-config.php` unless the three VAPID_*
 * environment variables are already set, in which case they take precedence
 * and no file is written.
 *
 * @noinspection PhpUnused
 */
class WebPushGenerateVapidKeys implements Command
{
    private const DEFAULT_SUBJECT = 'mailto:admin@localhost';

    public function run(Params $params, IO $io): void
    {
        $envAlreadySet = self::hasAllEnvKeys();

        if ($envAlreadySet) {
            $io->writeLine('VAPID keys are already supplied through environment variables:');
            $io->writeLine('  ' . VapidKeys::ENV_PUBLIC_KEY);
            $io->writeLine('  ' . VapidKeys::ENV_PRIVATE_KEY);
            $io->writeLine('  ' . VapidKeys::ENV_SUBJECT);
            $io->writeLine('');
            $io->writeLine('Environment variables take precedence, so nothing was written.');
            $io->writeLine('Remove them first if you want keys stored in data/webpush-config.php.');

            return;
        }

        $file = ProjectPath::join('data/webpush-config.php');
        $exists = is_file($file);

        if ($exists && !$params->hasFlag('force')) {
            $io->writeErrorLine("data/webpush-config.php already exists. Re-run with --force to overwrite it.");
            $io->setExitStatus(1);

            return;
        }

        $subject = $params->getOption('subject') ?? self::DEFAULT_SUBJECT;
        $subject = trim($subject);

        if ($subject === '' || (stripos($subject, 'mailto:') !== 0 && stripos($subject, 'https://') !== 0)) {
            $io->writeErrorLine('Subject must start with "mailto:" or "https://".');
            $io->setExitStatus(1);

            return;
        }

        try {
            $keys = VapidKeys::generate();
        } catch (\Throwable $e) {
            $io->writeErrorLine('Failed to generate VAPID keys: ' . $e->getMessage());
            $io->setExitStatus(1);

            return;
        }

        $config = [
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
            'subject' => $subject,
        ];

        $contents = "<?php\n\n"
            . "return [\n"
            . "    'publicKey' => " . var_export($config['publicKey'], true) . ",\n"
            . "    'privateKey' => " . var_export($config['privateKey'], true) . ",\n"
            . "    'subject' => " . var_export($config['subject'], true) . ",\n"
            . "];\n";

        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $io->writeErrorLine("Could not create directory: {$directory}");
            $io->setExitStatus(1);

            return;
        }

        if (@file_put_contents($file, $contents) === false) {
            $io->writeErrorLine("Could not write: {$file}");
            $io->setExitStatus(1);

            return;
        }

        $io->writeLine('VAPID key pair generated and stored in data/webpush-config.php');
        $io->writeLine('');
        $io->writeLine('  ' . VapidKeys::ENV_PUBLIC_KEY . '  = ' . $config['publicKey']);
        $io->writeLine('  ' . VapidKeys::ENV_PRIVATE_KEY . ' = ' . $config['privateKey']);
        $io->writeLine('  ' . VapidKeys::ENV_SUBJECT . '   = ' . $config['subject']);
        $io->writeLine('');
        $io->writeLine('Keep data/webpush-config.php private. It contains the private key.');
        $io->writeLine('To manage keys through environment variables instead, export:');
        $io->writeLine('  VAPID_PUBLIC_KEY ="' . $config['publicKey'] . '"');
        $io->writeLine('  VAPID_PRIVATE_KEY="' . $config['privateKey'] . '"');
        $io->writeLine('  VAPID_SUBJECT     ="' . $config['subject'] . '"');
        $io->writeLine('');
        $io->writeLine('Note: rotate the pair only together with re-subscribing every browser.');
    }

    private static function hasAllEnvKeys(): bool
    {
        foreach ([VapidKeys::ENV_PUBLIC_KEY, VapidKeys::ENV_PRIVATE_KEY, VapidKeys::ENV_SUBJECT] as $name) {
            $value = getenv($name);

            if (!is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }
}
