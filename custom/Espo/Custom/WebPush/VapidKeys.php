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

/**
 * Loads and exposes the VAPID (RFC 8292) key pair.
 *
 * Resolution order:
 *   1. Process/OS environment variables VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT.
 *   2. The web-forbidden file data/webpush-config.php returning a PHP array.
 *   3. Neither present - a pair is generated and written to that file, so a
 *      fresh deployment starts working without a console command.
 */
final class VapidKeys
{
    public const ENV_PUBLIC_KEY = 'VAPID_PUBLIC_KEY';
    public const ENV_PRIVATE_KEY = 'VAPID_PRIVATE_KEY';
    public const ENV_SUBJECT = 'VAPID_SUBJECT';

    private const CONFIG_FILE = 'data/webpush-config.php';

    private const DEFAULT_SUBJECT = 'mailto:admin@localhost';

    private ?array $data = null;
    private bool $loaded = false;

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $env = [
            'publicKey' => getenv(self::ENV_PUBLIC_KEY),
            'privateKey' => getenv(self::ENV_PRIVATE_KEY),
            'subject' => getenv(self::ENV_SUBJECT),
        ];

        foreach ($env as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                unset($env[$key]);
            }
        }

        if (count($env) === 3) {
            $this->data = $env;
            return;
        }

        $file = ProjectPath::join(self::CONFIG_FILE);

        if (is_file($file)) {
            $data = include $file;

            if (is_array($data)) {
                $this->data = $data;
            }

            return;
        }

        $this->data = $this->create($env);
    }

    /**
     * Nothing is configured yet. Generate one pair and persist it so the web
     * requests and the cron job keep reading the same keys.
     *
     * A concurrent first request is serialised with an exclusive lock; if the
     * file appears while waiting, its keys win. When the file cannot be
     * written nothing is returned, which leaves the caller on the old
     * "not configured" path instead of handing out keys nobody else can see.
     *
     * @param array<string, string> $env
     */
    private function create(array $env): ?array
    {
        $file = ProjectPath::join(self::CONFIG_FILE);
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        $lock = @fopen($file . '.lock', 'c');

        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }

        try {
            if (is_file($file)) {
                $data = include $file;

                if (is_array($data)) {
                    return $data;
                }
            }

            $keys = self::generate();

            $data = [
                'publicKey' => $keys['publicKey'],
                'privateKey' => $keys['privateKey'],
                'subject' => isset($env['subject']) ? trim($env['subject']) : self::DEFAULT_SUBJECT,
            ];

            $contents = "<?php\n\n"
                . "return [\n"
                . "    'publicKey' => " . var_export($data['publicKey'], true) . ",\n"
                . "    'privateKey' => " . var_export($data['privateKey'], true) . ",\n"
                . "    'subject' => " . var_export($data['subject'], true) . ",\n"
                . "];\n";

            if (@file_put_contents($file, $contents, LOCK_EX) === false) {
                return null;
            }

            return $data;
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    public function isConfigured(): bool
    {
        $this->load();

        if ($this->data === null) {
            return false;
        }

        return self::isNonEmpty($this->data, 'publicKey')
            && self::isNonEmpty($this->data, 'privateKey')
            && self::isNonEmpty($this->data, 'subject');
    }

    private static function isNonEmpty(array $data, string $key): bool
    {
        return isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '';
    }

    /**
     * @return string base64url encoded 65-byte uncompressed P-256 public key.
     */
    public function getPublicKey(): string
    {
        $this->requireConfigured();

        return trim($this->data['publicKey']);
    }

    /**
     * @return string raw 32-byte private scalar D (not encoded).
     */
    public function getPrivateKeyRaw(): string
    {
        $this->requireConfigured();

        $decoded = Base64Url::decode(trim($this->data['privateKey']));

        if (strlen($decoded) !== 32) {
            throw new \RuntimeException(
                'VAPID private key must decode to 32 bytes (got ' . strlen($decoded) . ').'
            );
        }

        return $decoded;
    }

    /**
     * PEM PKCS#8 private key usable by openssl_sign().
     */
    public function getPrivateKeyPem(): string
    {
        return Der::privateKeyPemFromRaw($this->getPrivateKeyRaw());
    }

    /**
     * PEM SubjectPublicKeyInfo public key.
     */
    public function getPublicKeyPem(): string
    {
        return Der::publicKeyPemFromRaw(Base64Url::decode($this->getPublicKey()));
    }

    /**
     * @return string e.g. "mailto:admin@example.com" or a https contact URL.
     */
    public function getSubject(): string
    {
        $this->requireConfigured();

        return trim($this->data['subject']);
    }

    private function requireConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'VAPID keys are not configured. Run the "webPushGenerateVapidKeys" console command '
                . 'or set the ' . self::ENV_PUBLIC_KEY . ', ' . self::ENV_PRIVATE_KEY . ' and '
                . self::ENV_SUBJECT . ' environment variables.'
            );
        }
    }

    /**
     * Generate a fresh P-256 key pair.
     *
     * @return array{publicKey:string,privateKey:string} base64url encoded values.
     * @throws \RuntimeException When OpenSSL cannot generate the key pair.
     */
    public static function generate(): array
    {
        $configPath = self::locateOpensslConfig();

        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];

        if ($configPath !== null) {
            $options['config'] = $configPath;
        }

        $resource = openssl_pkey_new($options);

        if ($resource === false) {
            throw new \RuntimeException('Failed to generate an EC key pair: ' . self::opensslError());
        }

        $details = openssl_pkey_get_details($resource);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new \RuntimeException('Failed to read EC key details: ' . self::opensslError());
        }

        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        $d = $details['ec']['d'] ?? null;

        if (!is_string($x) || !is_string($y) || !is_string($d)) {
            throw new \RuntimeException('EC key details did not contain x, y and d values.');
        }

        // openssl returns the raw (possibly left-padded/truncated) component bytes.
        $publicRaw = "\x04" . str_pad(substr($x, -32), 32, "\x00", STR_PAD_LEFT)
            . str_pad(substr($y, -32), 32, "\x00", STR_PAD_LEFT);

        return [
            'publicKey' => Base64Url::encode($publicRaw),
            'privateKey' => Base64Url::encode(str_pad(substr($d, -32), 32, "\x00", STR_PAD_LEFT)),
        ];
    }

    /**
     * openssl_pkey_new() fails when OpenSSL cannot locate a configuration file,
     * so one is supplied explicitly when it can be found on disk.
     */
    private static function locateOpensslConfig(): ?string
    {
        $candidates = [];

        $env = getenv('OPENSSL_CONF');

        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }

        $phpBinary = PHP_BINARY;

        if ($phpBinary !== '') {
            $candidates[] = dirname($phpBinary) . '/extras/ssl/openssl.cnf';
        }

        $locations = openssl_get_cert_locations();

        if (is_array($locations) && !empty($locations['ini_cafile'])) {
            $candidates[] = dirname($locations['ini_cafile']) . '/openssl.cnf';
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function opensslError(): string
    {
        $messages = [];

        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }

        return implode('; ', $messages) ?: 'unknown error';
    }
}
