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

use MediaWiki\MediaWikiServices;
use Monolog\Handler\AbstractHandler;
use Status;
use StatusValue;
use WikiMap;

/**
 * Counts authentication-related log events (those sent to the 'authevents'
 * channel) via StatsD.
 *
 * Events can include the following data in their context:
 *   - 'event': (string, required) the type of the event (e.g. 'login').
 *   - 'eventType': (string) a subtype for more complex events.
 *   - 'successful': (bool) whether the attempt was successful. Can be omitted if 'status' is
 *     a Status or a StatusValue.
 *   - 'status': (Status|StatusValue|string|int) attempt status (such as an error message key).
 *     string/int values will be ignored unless 'successful' is false.
 *
 * Will result in a ping to a graphite key that looks like
 * <MediaWiki root>.authmanager.<event>.<type>.<entrypoint>.[success|failure].<status>
 * Some segments will be omitted when the appropriate data is not present.
 * <entrypoint> is 'web' or 'centrallogin' or 'api' and filled automatically.
 *
 * Used to alert on sudden, unexplained changes in e.g. the number of login
 * errors.
 */
class AuthManagerStatsdHandler extends AbstractHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( array $record ): bool {
		$event = $this->getField( 'event', $record['context'] );
		$type = $this->getField( [ 'eventType', 'type' ], $record['context'] );
		$entrypoint = $this->getEntryPoint();
		$status = $this->getField( 'status', $record['context'] );
		$successful = $this->getField( 'successful', $record['context'] );
		$error = null;
		if ( $status instanceof StatusValue ) {
			$status = Status::wrap( $status );
			$successful = $status->isGood();
			if ( !$successful ) {
				$errorArray = $status->getErrorsArray() ?: $status->getWarningsArray();
				$error = $errorArray[0][0];
			}
		} elseif ( is_string( $status ) && $successful === false ) {
			$error = $status;
		} elseif ( is_numeric( $status ) && $successful === false ) {
			$error = strval( $status );
		} elseif ( is_bool( $status ) ) {
			$successful = $status;
		}

		// Sense-check in case this was invoked from some non-metrics-related
		// code by accident
		if (
			( $record['channel'] !== 'authevents' && $record['channel'] !== 'captcha' )
			|| !$event || !is_string( $event )
			|| ( $type && !is_string( $type ) )
			|| ( $error && !is_string( $error ) )
		) {
			return false;
		}

		// some key parts can be null and will be removed by array_filter
		$keyParts = [ 'authmanager', $event, $type, $entrypoint ];
		if ( $successful === true ) {
			$keyParts[] = 'success';
		} elseif ( $successful === false ) {
			$keyParts[] = 'failure';
			$keyParts[] = $error;
		}
		$key = implode( '.', array_filter( $keyParts ) );

		// use of this class is set up in operations/mediawiki-config so no nice dependency injection
		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$stats->increment( $key );

		// pass to next handler
		return false;
	}

	/**
	 * @return string
	 */
	protected function getEntryPoint() {
		$entrypoint = defined( 'MW_API' ) ? 'api' : 'web';
		if ( $entrypoint === 'web' && WikiMap::getCurrentWikiId() === 'loginwiki' ) {
			$entrypoint = 'centrallogin';
		}
		return $entrypoint;
	}

	/**
	 * Get a field from an array without triggering errors if it does not exist
	 * @param string|array $field Field name or list of field name + fallbacks
	 * @param array $data
	 * @return mixed Field value, or null if field was missing
	 */
	protected function getField( $field, array $data ) {
		foreach ( (array)$field as $key ) {
			if ( isset( $data[$key] ) ) {
				return $data[$key];
			}
		}
		return null;
	}
}
