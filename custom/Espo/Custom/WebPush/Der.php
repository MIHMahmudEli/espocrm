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
 * Minimal DER (ASN.1) encoder/decoder helpers.
 *
 * Used to build a PKCS#8 private key PEM and an X.509 SubjectPublicKeyInfo
 * public key PEM out of raw P-256 key material, and to parse the ECDSA
 * signature returned by openssl_sign() (which is DER, while JOSE/ES256
 * requires the raw r||s concatenation).
 */
final class Der
{
    /**
     * Encode a DER length prefix.
     */
    public static function len(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function seq(string $content): string
    {
        return "\x30" . self::len(strlen($content)) . $content;
    }

    public static function int(string $bytes): string
    {
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::len(strlen($bytes)) . $bytes;
    }

    public static function octet(string $bytes): string
    {
        return "\x04" . self::len(strlen($bytes)) . $bytes;
    }

    /**
     * BIT STRING with zero unused bits.
     */
    public static function bit(string $bytes): string
    {
        return "\x03" . self::len(strlen($bytes) + 1) . "\x00" . $bytes;
    }

    /**
     * Encode an object identifier from a dotted-decimal string.
     *
     * @param string $dotted e.g. "1.2.840.10045.3.1.7".
     */
    public static function oid(string $dotted): string
    {
        $parts = array_map('intval', explode('.', $dotted));

        if (count($parts) < 2) {
            throw new \InvalidArgumentException('OID must have at least two arcs.');
        }

        $body = chr(40 * $parts[0] + $parts[1]);

        for ($i = 2, $count = count($parts); $i < $count; $i++) {
            $body .= self::base128($parts[$i]);
        }

        return "\x06" . self::len(strlen($body)) . $body;
    }

    private static function base128(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('OID arc must be non-negative.');
        }

        $out = chr($value & 0x7F);
        $value >>= 7;

        while ($value > 0) {
            $out = chr(($value & 0x7F) | 0x80) . $out;
            $value >>= 7;
        }

        return $out;
    }

    /**
     * AlgorithmIdentifier for id-ecPublicKey (1.2.840.10045.2.1) with prime256v1.
     */
    public static function ecAlgorithmIdentifier(): string
    {
        return self::seq(self::oid('1.2.840.10045.2.1') . self::oid('1.2.840.10045.3.1.7'));
    }

    /**
     * Build a SubjectPublicKeyInfo DER for an EC public key.
     *
     * @param string $raw65 Uncompressed SEC1 point (0x04 || X || Y).
     */
    public static function ecPublicKeyDer(string $raw65): string
    {
        return self::seq(self::ecAlgorithmIdentifier() . self::bit($raw65));
    }

    /**
     * Build a PKCS#8 PrivateKeyInfo DER for an EC private key.
     *
     * @param string $d32 32-byte big-endian private scalar D.
     */
    public static function ecPrivateKeyPkcs8Der(string $d32): string
    {
        // RFC 5915 ECPrivateKey, wrapped in an OCTET STRING as PKCS#8 requires.
        $ecPrivateKey = self::seq(
            self::int("\x01") .           // version ecPrivkeyVer1
            self::octet($d32)             // privateKey
        );

        return self::seq(
            self::int("\x00") .                   // PKCS#8 version v0
            self::ecAlgorithmIdentifier() .       // privateKeyAlgorithm
            self::octet($ecPrivateKey)            // privateKey
        );
    }

    /**
     * Wrap a DER value in a PEM envelope.
     */
    public static function pem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END {$label}-----\n";
    }

    /**
     * Convert a 65-byte uncompressed SEC1 point into a PEM SPKI public key.
     */
    public static function publicKeyPemFromRaw(string $raw65): string
    {
        return self::pem(self::ecPublicKeyDer($raw65), 'PUBLIC KEY');
    }

    /**
     * Convert a raw 32-byte P-256 private scalar into a PEM PKCS#8 private key.
     */
    public static function privateKeyPemFromRaw(string $d32): string
    {
        return self::pem(self::ecPrivateKeyPkcs8Der($d32), 'PRIVATE KEY');
    }

    /**
     * Read a single TLV element starting at $offset.
     *
     * @return array{0:int,1:int,2:string,3:int} [tag, length, value bytes, next offset]
     */
    public static function readTlv(string $data, int $offset): array
    {
        if ($offset >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of DER data.');
        }

        $tag = ord($data[$offset]);
        $offset++;

        if ($offset >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of DER data.');
        }

        $lengthByte = ord($data[$offset]);
        $offset++;

        if (($lengthByte & 0x80) === 0) {
            $length = $lengthByte;
        } else {
            $numBytes = $lengthByte & 0x7F;
            $length = 0;

            for ($i = 0; $i < $numBytes; $i++) {
                if ($offset >= strlen($data)) {
                    throw new \RuntimeException('Unexpected end of DER data.');
                }

                $length = ($length << 8) | ord($data[$offset]);
                $offset++;
            }
        }

        if ($offset + $length > strlen($data)) {
            throw new \RuntimeException('DER element exceeds available data.');
        }

        $value = substr($data, $offset, $length);
        $offset += $length;

        return [$tag, $length, $value, $offset];
    }

    /**
     * Split a DER-encoded ECDSA signature (SEQUENCE of two INTEGERs) into raw r||s.
     *
     * @param string $der openssl_sign() output with OPENSSL_ALGO_SHA256.
     * @return string 64 bytes: r (32) || s (32).
     */
    public static function ecdsaDerToRaw(string $der): string
    {
        [$tag, , $sequence] = self::readTlv($der, 0);

        if ($tag !== 0x30) {
            throw new \RuntimeException('ECDSA signature is not a DER SEQUENCE.');
        }

        $offset = 0;
        $components = [];

        for ($i = 0; $i < 2; $i++) {
            [$tag, , $value, $offset] = self::readTlv($sequence, $offset);

            if ($tag !== 0x02) {
                throw new \RuntimeException('ECDSA signature component is not an INTEGER.');
            }

            $components[] = $value;
        }

        foreach ($components as $index => $value) {
            if (strlen($value) > 32) {
                if (strlen($value) !== 33 || $value[0] !== "\x00") {
                    throw new \RuntimeException('ECDSA signature component exceeds 32 bytes.');
                }

                $value = substr($value, 1);
            }

            $components[$index] = str_pad($value, 32, "\x00", STR_PAD_LEFT);
        }

        return $components[0] . $components[1];
    }
}
