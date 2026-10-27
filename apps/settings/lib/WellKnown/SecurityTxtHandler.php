<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Settings\WellKnown;

use OCP\AppFramework\Http\TextPlainResponse;
use OCP\Http\WellKnown\GenericResponse;
use OCP\Http\WellKnown\IHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;

class SecurityTxtHandler implements IHandler {
	#[\Override]
	public function handle(string $service, IRequestContext $context, ?IResponse $previousResponse): ?IResponse {
		if ($service !== 'security.txt') {
			return $previousResponse;
		}

		// RobinCloud: reports go to robin, not to Nextcloud's HackerOne (D-034).
		// Expires has to stay under a year away (RFC 9116): renew it before then.
		$response = 'Contact: mailto:robin@yoonrobin.xyz
Expires: 2027-10-01T00:00:00.000Z
Preferred-Languages: ko, en
Canonical: https://cloud.yoonrobin.xyz/.well-known/security.txt
';

		return new GenericResponse(new TextPlainResponse($response, 200));
	}
}
