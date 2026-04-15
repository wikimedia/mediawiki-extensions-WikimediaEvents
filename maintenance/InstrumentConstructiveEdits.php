<?php

namespace WikimediaEvents\Maintenance;

use MediaWiki\ChangeTags\ChangeTags;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\RecentChanges\RecentChange;
use stdClass;
use Wikimedia\Timestamp\TimestampFormat;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Generates constructive edits (edits persist for N time).
 */
class InstrumentConstructiveEdits extends Maintenance {
	private const CONSTRUCTIVE_EDITS_SURVIVAL_HOURS = 48;
	private const CONSTRUCTIVE_EDITS_SCRIPT_RUNS_INTERVAL_HOURS = 1;
	private const CONSTRUCTIVE_EDITS_INSTRUMENT_NAME = 'constructive-edits';

	public function __construct() {
		parent::__construct();
		$this->addOption( 'dry-run', 'Does not send events' );
		$this->addOption( 'threshold', 'How long a revision has to survive (hours)' );
		$this->addOption( 'interval', 'Period between script runs (hours)' );
	}

	public function execute(): void {
		// Fetch every revision from all wikis.
		$edits = $this->findAllEdits(
			$this->getOption( 'threshold', self::CONSTRUCTIVE_EDITS_SURVIVAL_HOURS ),
			$this->getOption( 'interval', self::CONSTRUCTIVE_EDITS_SCRIPT_RUNS_INTERVAL_HOURS )
		);
		// Send events for all constructive edits.
		$this->logConstructiveEdits( $edits );
	}

	/**
	 * Find rows that have constructive edits.
	 *
	 * @param int $hours the number of previous hours to check.
	 * @param int $interval the interval between edits.
	 *
	 * @return iterable<stdClass> a list of row IDs, identifying rows of constructive edits.
	 */
	private function findAllEdits( int $hours, int $interval ): iterable {
		$startTime = time() - ( $hours * 3600 );
		$endTime = wfTimestamp( TimestampFormat::MW, $startTime - ( $interval * 3600 ) );
		$startTime = wfTimestamp( TimestampFormat::MW, $startTime );
		$query = $this->getServiceContainer()->getChangesListQueryFactory()->newQuery()
			->recentChangeFields()
			->startAt( $startTime )
			->endAt( $endTime )
			->requireSources( [
				RecentChange::SRC_NEW,
				RecentChange::SRC_EDIT
			] )
			->excludeDeletedLogAction()
			->excludeChangeTags( CHANGETAGS::REVERT_TAGS )
			->caller( __METHOD__ );

		$result = $query->fetchResult();
		$this->output( "Changes between $startTime and $endTime: {$result->count()}\n" );
		return $result->getRows();
	}

	private function logConstructiveEdits( iterable $edits ): void {
		$services = $this->getServiceContainer();
		$instrumentManager = $services->getService( 'TestKitchen.InstrumentManager' );
		$instrument = $instrumentManager->getInstrument( self::CONSTRUCTIVE_EDITS_INSTRUMENT_NAME );
		$dbname = $this->getReplicaDB()->getDBname();
		$tempConfig = $services->getTempUserConfig();
		$statsFactory = $services->getStatsFactory();

		foreach ( $edits as $edit ) {
			// This is a constructive edit (not reverted within a certain time).
			// Send event via a TK instrument and override contextual attributes
			// from the recent changes query results.
			if ( !$this->hasOption( 'dry-run' ) ) {
				$instrument->send(
					'edit_survived',
					[
						'mediawiki' => [
							'database' => $dbname
						],
						'page' => [
							'id' => $edit->rc_cur_id,
							'namespace_id' => $edit->rc_namespace,
							'revision_id' => $edit->rc_this_oldid,
						],
						'performer' => [
							'id' => $edit->rc_user,
							'is_bot' => $edit->rc_bot,
							'is_logged_in' => (bool)$edit->rc_user,
							'is_temp' => $tempConfig->isTempName( $edit->rc_user_text )
						]
					]
				);
				// Increment a Prometheus counter for the total number of constructive edits.
				$statsFactory->getCounter( 'constructive_edits_total' )->increment();
			}
			$this->output( "Edit survived: revisionId - $edit->rc_this_oldid\n" );
		}
	}
}
