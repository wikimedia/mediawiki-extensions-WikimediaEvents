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

use MediaWiki\MediaWikiServices;
use Monolog\Handler\AbstractHandler;

/**
 * Counts authentication-related log calls (those sent to the 'authmanager'
 * channel) via StatsD.
 *
 * Used to alert on sudden, unexplained changes in e.g. the number of login
 * errors.
 */
class AuthManagerStatsdHandler extends AbstractHandler {

	/**
	 * {@inheritdoc}
	 */
	public function handle( array $record ) {
		$event = isset( $record['context']['event'] ) ? $record['context']['event'] : null;
		$type = isset( $record['context']['type'] ) ? $record['context']['type'] : null;
		$entrypoint = $this->getEntryPoint();
		$status = isset( $record['context']['status'] ) ? $record['context']['status'] : null;
		$successful = isset( $record['context']['successful'] ) ? $record['context']['successful'] : null;
		$error = null;
		if ( $status instanceof Status || $status instanceof StatusValue ) {
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
		} elseif( is_bool( $status ) ) {
			$successful = $status;
		}

		// sanity check in case this was invoked from some non-metrics-related
		// code by accident
		if (
			$record['channel'] !== 'authmanager' && $record['channel'] !== 'authevents'
			|| !$event || !is_string( $event )
			|| ( $type && !is_string( $type ) )
			|| ( $error && !is_string( $error ) )
		) {
			return false;
		}

		// some key parts can be null and will be removed by array_filter
		$keyParts = array( 'authmanager', $event, $type, $entrypoint );
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

	protected function getEntryPoint() {
		$entrypoint = defined( 'MW_API' ) ? 'api' : 'web';
		if ( $entrypoint === 'web' && wfWikiID() === 'loginwiki' ) {
			$entrypoint = 'centrallogin';
		}
		return $entrypoint;
	}
}
