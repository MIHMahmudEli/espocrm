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
 * Payload encryption for Web Push.
 *
 * Implements the "aes128gcm" content coding from RFC 8188 with the key
 * derivation defined in RFC 8291 ("Message Encryption for Web Push").
 *
 * Validated against the RFC 8291 Appendix A known-answer test vectors.
 */
final class Encryption
{
    public const CONTENT_ENCODING = 'aes128gcm';

    private const KEY_INFO = "WebPush: info\0";
    private const CEK_INFO = "Content-Encoding: aes128gcm\0";
    private const NONCE_INFO = "Content-Encoding: nonce\0";

    /** Record overhead: 16-byte GCM tag + 1-byte delimiter. */
    private const RECORD_OVERHEAD = 17;

    /** Header size added on top of the record when rs is chosen as payload + 18. */
    private const HEADER_PADDING = 1;

    /**
     * Encrypt a plaintext payload for a push subscription.
     *
     * @param string $plaintext UTF-8 message to be delivered.
     * @param string $subscriberPublicKey base64url encoded 65-byte p256dh key.
     * @param string $authSecret base64url encoded 16-byte auth key.
     * @return array{body:string,header:string,salt:string,asPublic:string,recordSize:int}
     * @throws \RuntimeException On invalid input or OpenSSL failure.
     */
    public static function encrypt(string $plaintext, string $subscriberPublicKey, string $authSecret): array
    {
        $uaPublic = Base64Url::decode($subscriberPublicKey);
        $auth = Base64Url::decode($authSecret);

        self::assertSubscriberKey($uaPublic, $auth);

        [$privateKeyPem, $asPublic] = self::generateEphemeralKeyPair();

        $salt = random_bytes(16);

        // rs is the size of a single encrypted record (ciphertext + tag), which
        // must be large enough for the plaintext, the delimiter byte and padding.
        $recordSize = strlen($plaintext) + self::RECORD_OVERHEAD + self::HEADER_PADDING;

        if ($recordSize < 18) {
            $recordSize = 18;
        }

        $material = self::derive($privateKeyPem, $uaPublic, $auth, $asPublic, $salt);

        $cek = $material['cek'];
        $nonce = $material['nonce'];

        $paddingLength = ($recordSize - 16) - strlen($plaintext) - 1;

        if ($paddingLength < 0) {
            throw new \RuntimeException('Computed record padding is negative.');
        }

        $record = $plaintext . "\x02" . str_repeat("\x00", $paddingLength);

        $tag = '';

        $ciphertext = openssl_encrypt(
            $record,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Payload encryption failed: ' . self::opensslError());
        }

        $header = $salt . pack('N', $recordSize) . chr(strlen($asPublic)) . $asPublic;

        return [
            'body' => $header . $ciphertext . $tag,
            'header' => $header,
            'salt' => $salt,
            'asPublic' => $asPublic,
            'recordSize' => $recordSize,
        ];
    }

    /**
     * @param string $uaPublic raw 65-byte subscriber public key.
     * @param string $auth raw 16-byte auth secret.
     */
    private static function assertSubscriberKey(string $uaPublic, string $auth): void
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new \RuntimeException(
                'Subscription p256dh key must be a 65-byte uncompressed P-256 point.'
            );
        }

        if (strlen($auth) !== 16) {
            throw new \RuntimeException('Subscription auth key must be 16 bytes.');
        }
    }

    /**
     * Perform the RFC 8291 key derivation.
     *
     * @param string $privateKeyPem PEM PKCS#8 local private key.
     * @param string $uaPublic raw 65-byte subscriber public key.
     * @param string $auth raw 16-byte auth secret.
     * @param string $asPublic raw 65-byte local public key.
     * @param string $salt raw 16-byte salt.
     * @return array{ecdh:string,prkKey:string,ikm:string,prk:string,cek:string,nonce:string}
     */
    private static function derive(
        string $privateKeyPem,
        string $uaPublic,
        string $auth,
        string $asPublic,
        string $salt
    ): array {
        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new \RuntimeException('Failed to load the local private key: ' . self::opensslError());
        }

        $subscriberKey = openssl_pkey_get_public(Der::publicKeyPemFromRaw($uaPublic));

        if ($subscriberKey === false) {
            throw new \RuntimeException('Failed to load the subscriber public key: ' . self::opensslError());
        }

        // openssl_pkey_derive() expects (public key, private key).
        $ecdh = openssl_pkey_derive($subscriberKey, $privateKey);

        if ($ecdh === false) {
            throw new \RuntimeException('ECDH key agreement failed: ' . self::opensslError());
        }

        $prkKey = hash_hmac('sha256', $ecdh, $auth, true);

        $keyInfo = self::KEY_INFO . $uaPublic . $asPublic . "\x01";

        $ikm = hash_hmac('sha256', $keyInfo, $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);

        $cek = substr(hash_hmac('sha256', self::CEK_INFO . "\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', self::NONCE_INFO . "\x01", $prk, true), 0, 12);

        return [
            'ecdh' => $ecdh,
            'prkKey' => $prkKey,
            'ikm' => $ikm,
            'prk' => $prk,
            'cek' => $cek,
            'nonce' => $nonce,
        ];
    }

    /**
     * Exposed for known-answer test validation only.
     *
     * @return array{ecdh:string,prkKey:string,ikm:string,prk:string,cek:string,nonce:string}
     */
    public static function deriveForTest(
        string $privateKeyPem,
        string $uaPublic,
        string $auth,
        string $asPublic,
        string $salt
    ): array {
        return self::derive($privateKeyPem, $uaPublic, $auth, $asPublic, $salt);
    }

    /**
     * @return array{0:string,1:string} [private key PEM, raw 65-byte public key]
     */
    private static function generateEphemeralKeyPair(): array
    {
        $keys = VapidKeys::generate();

        $privateRaw = Base64Url::decode($keys['privateKey']);
        $publicRaw = Base64Url::decode($keys['publicKey']);

        return [Der::privateKeyPemFromRaw($privateRaw), $publicRaw];
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
