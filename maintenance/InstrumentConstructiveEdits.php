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
		$this->addOption( 'threshold', 'How long a revision has to survive (hours)', false, true );
		$this->addOption( 'interval', 'Period between script runs (hours)', false, true );
	}

	public function execute(): void {
		$threshold = (int)$this->getOption( 'threshold', self::CONSTRUCTIVE_EDITS_SURVIVAL_HOURS );
		$interval = (int)$this->getOption( 'interval', self::CONSTRUCTIVE_EDITS_SCRIPT_RUNS_INTERVAL_HOURS );
		// Fetch every revision from all wikis.
		$edits = $this->findAllEdits( $threshold, $interval );
		// Send events for all constructive edits.
		$this->logConstructiveEdits( $edits, $threshold );
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
			// Two distinct sets of tags, both unwanted. REVERT_TAGS (mw-rollback,
			// mw-undo, mw-manual-revert) mark the edit that does the reverting.
			// TAG_REVERTED (mw-reverted) marks the edit that was reverted. See T431493.
			->excludeChangeTags( [ ...ChangeTags::REVERT_TAGS, ChangeTags::TAG_REVERTED ] )
			->caller( __METHOD__ );

		$result = $query->fetchResult();
		$this->output( "Changes between $startTime and $endTime: {$result->count()}\n" );
		return $result->getRows();
	}

	private function logConstructiveEdits( iterable $edits, int $threshold ): void {
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
						// `action_context` records the survival threshold (in hours) that
						// this edit met, e.g. "48H", for data lineage. See T431493.
						'action_context' => $threshold . 'H',
						// The contextual attributes below are supplied explicitly as
						// interaction data rather than collected by TestKitchen from the
						// request context: this maintenance script runs on the CLI over
						// revisions from many wikis, so there is no single
						// page/performer/wiki context to derive them from. EventFactory
						// spreads interaction data at the top level of the event, so these
						// nested fragments populate the schema's page/mediawiki/performer
						// objects directly. For them to survive, the `constructive-edits`
						// instrument config must NOT declare these as contextual
						// attributes, otherwise EventFactory::addContextualAttributes()
						// overwrites them with the (empty) CLI context.
						'mediawiki' => [
							'database' => $dbname
						],
						'page' => [
							'id' => (int)$edit->rc_cur_id,
							'namespace_id' => (int)$edit->rc_namespace,
							'revision_id' => (int)$edit->rc_this_oldid,
						],
						'performer' => [
							'id' => (int)$edit->rc_user,
							'is_bot' => (bool)$edit->rc_bot,
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

$maintClass = InstrumentConstructiveEdits::class;
require_once RUN_MAINTENANCE_IF_MAIN;
