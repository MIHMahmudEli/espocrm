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
 * Outcome of a single Web Push request.
 */
final class SendResult
{
    private const EXPIRED_STATUSES = [404, 410];

    public function __construct(
        public readonly int $statusCode,
        public readonly string $responseBody,
        public readonly ?string $error = null,
        public readonly ?string $requestId = null
    ) {}

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * The push service no longer recognises the subscription; the stored
     * subscription record must be deleted.
     */
    public function isSubscriptionExpired(): bool
    {
        return in_array($this->statusCode, self::EXPIRED_STATUSES, true);
    }

    /**
     * A transport level failure (DNS, timeout, TLS) rather than a HTTP response.
     */
    public function isTransportError(): bool
    {
        return $this->statusCode === 0;
    }

    public function describe(): string
    {
        if ($this->error !== null) {
            return $this->error;
        }

        $description = 'HTTP ' . $this->statusCode;

        if ($this->responseBody !== '') {
            $description .= ': ' . mb_substr($this->responseBody, 0, 300);
        }

        return $description;
    }
}
