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
 * Encrypts a payload and delivers it to a Web Push service (RFC 8030) with a
 * VAPID signature (RFC 8292).
 *
 * Requires nothing beyond the OpenSSL and cURL extensions already used by
 * EspoCRM; no third-party Web Push library is involved.
 */
final class PushSender
{
    private const CONNECT_TIMEOUT = 8;
    private const TIMEOUT = 15;

    /** VAPID assertions should not be accepted for more than a day. */
    private const TOKEN_LIFETIME = 3600;

    private const HEADER_PREFIX = 'vapid ';

    private VapidKeys $vapidKeys;

    public function __construct(?VapidKeys $vapidKeys = null)
    {
        $this->vapidKeys = $vapidKeys ?? new VapidKeys();
    }

    public function isConfigured(): bool
    {
        return $this->vapidKeys->isConfigured();
    }

    /**
     * @param string $endpoint Push service endpoint URL from the subscription.
     * @param string $publicKey base64url encoded 65-byte p256dh key.
     * @param string $authKey base64url encoded 16-byte auth secret.
     * @param string $payload Plain text message; it is encrypted before sending.
     * @param int $ttl Seconds the push service may retain an undelivered message.
     * @param string $urgency RFC 8048 urgency: very-low | low | normal | high.
     */
    public function send(
        string $endpoint,
        string $publicKey,
        string $authKey,
        string $payload,
        int $ttl = 3600,
        string $urgency = 'normal'
    ): SendResult {
        if (!$this->vapidKeys->isConfigured()) {
            return new SendResult(0, '', 'VAPID keys are not configured.');
        }

        $origin = self::originOf($endpoint);

        if ($origin === null) {
            return new SendResult(0, '', "Push endpoint is not a valid URL: {$endpoint}");
        }

        try {
            $encrypted = Encryption::encrypt($payload, $publicKey, $authKey);
        } catch (\Throwable $e) {
            return new SendResult(0, '', 'Payload encryption failed: ' . $e->getMessage());
        }

        $authorization = self::HEADER_PREFIX
            . 't=' . $this->buildToken($origin) . ', '
            . 'k=' . $this->vapidKeys->getPublicKey();

        return $this->request($endpoint, $encrypted['body'], $authorization, $ttl, $urgency);
    }

    /**
     * Build the ES256 signed JWT used as the VAPID "t" parameter.
     */
    private function buildToken(string $origin): string
    {
        $header = Base64Url::encode(json_encode(
            ['typ' => 'JWT', 'alg' => 'ES256'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $payload = Base64Url::encode(json_encode(
            [
                'aud' => $origin,
                'exp' => time() + self::TOKEN_LIFETIME,
                'sub' => $this->vapidKeys->getSubject(),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $signingInput = $header . '.' . $payload;

        $der = '';

        if (!openssl_sign($signingInput, $der, $this->vapidKeys->getPrivateKeyPem(), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign the VAPID token.');
        }

        return $signingInput . '.' . Base64Url::encode(Der::ecdsaDerToRaw($der));
    }

    private function request(
        string $endpoint,
        string $body,
        string $authorization,
        int $ttl,
        string $urgency
    ): SendResult {
        $curl = curl_init($endpoint);

        if ($curl === false) {
            return new SendResult(0, '', 'Failed to initialise cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: ' . Encryption::CONTENT_ENCODING,
                'Authorization: ' . $authorization,
                'TTL: ' . $ttl,
                'Urgency: ' . $urgency,
            ],
        ]);

        $response = curl_exec($curl);

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);

        if ($response === false) {
            return new SendResult(0, '', "Push request failed: {$error}");
        }

        return new SendResult($status, is_string($response) ? $response : '');
    }

    /**
     * @return string|null Origin ("scheme://host[:port]") of the endpoint.
     */
    private static function originOf(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);

        if (
            !is_array($parts) ||
            empty($parts['scheme']) ||
            empty($parts['host'])
        ) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['https', 'http'], true)) {
            return null;
        }

        $origin = $scheme . '://' . strtolower($parts['host']);

        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }
}
