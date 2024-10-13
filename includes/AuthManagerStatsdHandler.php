<?php
/**
 * Custom logger for counting certain events.
 *
 * (c) Wikimedia Foundation 2015, GPL
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace WikimediaEvents;

use MediaWiki\Extension\CentralAuth\SharedDomainUtils;
use MediaWiki\MediaWikiServices;
use Monolog\Handler\AbstractHandler;
use RequestContext;

/**
 * Counts authentication-related log events (those sent to the 'authevents'
 * channel).
 *
 * Events can include the following data in their context:
 *   - 'event': (string, required) the type of the event (e.g. 'login').
 *   - 'eventType': (string) a subtype for more complex events.
 *   - 'accountType': (string, optional), a performer account type, one of `named`, `temp`, `anon`
 *   - 'successful': (bool) whether the attempt was successful.
 *   - 'status': (string) attempt status (such as an error message key).
 *     Will be ignored unless 'successful' is false.
 *
 * Will result in a ping to a statsd counter that looks like
 * <MediaWiki root>.authmanager.<event>.<type>.<entrypoint>.[success|failure].<status>
 * Some segments will be omitted when the appropriate data is not present.
 * <entrypoint> is 'web' or 'api' and filled automatically.
 *
 * Generic stats counters will also be incremented, depending on the event:
 *   - 'authmanager_success_total'
 *   - 'authmanager_error_total'
 * With the following labels:
 *   - 'entrypoint': as described above, 'web' or 'api'
 *   - 'event': the type of the event
 *   - 'subtype': (can be 'n/a' if no subtype is found)
 *   - 'reason': failure reason, set only for errors
 *   - 'accountType': the account type if passed
 *
 * Used to alert on sudden, unexplained changes in e.g. the number of login
 * errors.
 */
class AuthManagerStatsdHandler extends AbstractHandler {

	/**
	 * Temporary - used to mark metrics with SUL3 label. Should be removed after full migration
	 * Technical Debt - Introduces hard coupling between WikimediaEvents and CentralAuth
	 * @see https://phabricator.wikimedia.org/T375955
	 * @return bool
	 */
	private function isSul3Enabled() {
		$services = MediaWikiServices::getInstance();
		if ( !$services->getExtensionRegistry()->isLoaded( 'CentralAuth' ) ) {
			return false;
		}
		/** @var SharedDomainUtils $sharedDomainUtils */
		$sharedDomainUtils = $services->get( 'CentralAuth.SharedDomainUtils' );
		$context = RequestContext::getMain();

		return $sharedDomainUtils->isSul3Enabled( $context->getRequest() );
	}

	/**
	 * @inheritDoc
	 */
	public function handle( array $record ): bool {
		$event = $record['context']['event'] ?? null;
		$type = $record['context']['eventType'] ?? $record['context']['type'] ?? null;
		$entrypoint = $this->getEntryPoint();
		$status = $record['context']['status'] ?? null;
		$successful = $record['context']['successful'] ?? null;
		$accountType = $record['context']['accountType'] ?? null;

		$error = null;
		if ( $successful === false ) {
			$error = strval( $status );
		}

		// Sense-check in case this was invoked from some non-metrics-related
		// code by accident
		if (
			( $record['channel'] !== 'authevents' && $record['channel'] !== 'captcha' )
			|| !$event || !is_string( $event )
			|| ( $type && !is_string( $type ) )
		) {
			return false;
		}

		// some key parts can be null and will be removed by array_filter
		$keyParts = [ 'authmanager', $event, $type, $entrypoint ];
		// captcha stream is used to check for captcha effectiveness and there is no need to
		// differentiate between account types
		if ( $accountType !== null && $record['channel'] === 'authevents' ) {
			$keyParts[] = $accountType;
		}
		if ( $successful === true ) {
			$keyParts[] = 'success';
			$counterName = 'authmanager_success_total';
		} elseif ( $successful === false ) {
			$counterName = 'authmanager_error_total';
			$keyParts[] = 'failure';
			$keyParts[] = $error;
		} else {
			$counterName = 'authmanager_event_total';
		}
		$statsdKey = implode( '.', array_filter( $keyParts ) );

		// use of this class is set up in operations/mediawiki-config so no nice dependency injection
		$counter = MediaWikiServices::getInstance()->getStatsFactory()
			->withComponent( 'WikimediaEvents' )
			->getCounter( $counterName )
			->setLabel( 'entrypoint', $entrypoint )
			->setLabel( 'event', $event )
			->setLabel( 'subtype', $type ?? 'n/a' );
		if ( $successful === false ) {
			$counter->setLabel( 'reason', $error ?: 'n/a' );
		}
		if ( $accountType !== null ) {
			$counter->setLabel( 'accountType', $accountType );
		}
		$counter->setLabel( 'sul3', $this->isSul3Enabled() ? 'enabled' : 'disabled' );

		$counter->copyToStatsdAt( $statsdKey )
			->increment();

		// pass to next handler
		return false;
	}

	/**
	 * @return string
	 */
	protected function getEntryPoint() {
		return defined( 'MW_API' ) ? 'api' : 'web';
	}

}
