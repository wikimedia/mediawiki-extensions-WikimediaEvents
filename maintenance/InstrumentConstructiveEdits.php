<?php

namespace WikimediaEvents\Maintenance;

use MediaWiki\ChangeTags\ChangeTags;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\RecentChanges\RecentChange;
use stdClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;
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
		$this->addOption(
			'as-of',
			'Behave as if the script ran at this time, to replay a missed run. Any time inside the '
				. 'target interval works. Use the same --threshold and --interval as the missed run. '
				. 'Any timestamp format MediaWiki accepts.',
			false,
			true
		);
	}

	public function execute(): void {
		$threshold = (int)$this->getOption( 'threshold', self::CONSTRUCTIVE_EDITS_SURVIVAL_HOURS );
		$interval = (int)$this->getOption( 'interval', self::CONSTRUCTIVE_EDITS_SCRIPT_RUNS_INTERVAL_HOURS );
		if ( $interval < 1 ) {
			$this->fatalError( '--interval must be at least 1 hour.' );
		}
		if ( $threshold < 0 ) {
			$this->fatalError( '--threshold cannot be negative.' );
		}
		[ $oldest, $newest ] = $this->getTimestampRange( $threshold, $interval );
		// This queries one wiki. Puppet runs the script under foreachwiki.
		$edits = $this->findAllEdits( $oldest, $newest );
		$this->logConstructiveEdits( $edits, $threshold );
	}

	/**
	 * Get the range of edits to report on, as [ oldest, newest ] MW timestamps.
	 *
	 * The range aligns to a multiple of the interval. A range measured back from
	 * the current time moves between runs, because foreachwiki reaches each wiki
	 * at a different moment. Edits near the bounds are then reported twice, or
	 * not at all.
	 *
	 * Both query bounds are inclusive, so the newest bound is one second below
	 * the next range. Without this, an edit on a boundary is reported twice.
	 *
	 * The interval must match how often the script runs. More frequent runs
	 * align to the same interval and report the same edits again. A missed run
	 * leaves a gap, which --as-of replays.
	 *
	 * @param int $threshold how long a revision has to survive (hours).
	 * @param int $interval the period between script runs (hours).
	 *
	 * @return string[]
	 */
	private function getTimestampRange( int $threshold, int $interval ): array {
		$asOf = $this->getOption( 'as-of' );
		if ( $asOf === null ) {
			$now = ConvertibleTimestamp::time();
		} else {
			$now = ConvertibleTimestamp::convert( TimestampFormat::UNIX, $asOf );
			if ( $now === false ) {
				$this->fatalError( "Could not parse --as-of value '$asOf'." );
			}
			$now = (int)$now;
		}
		$intervalSeconds = $interval * 3600;
		$alignedNow = intdiv( $now, $intervalSeconds ) * $intervalSeconds;
		$newest = $alignedNow - ( $threshold * 3600 );
		return [
			ConvertibleTimestamp::convert( TimestampFormat::MW, $newest - $intervalSeconds ),
			ConvertibleTimestamp::convert( TimestampFormat::MW, $newest - 1 ),
		];
	}

	/**
	 * Find rows that have constructive edits.
	 *
	 * @param string $oldest MW timestamp of the oldest edit to report on.
	 * @param string $newest MW timestamp of the newest edit to report on.
	 *
	 * @return iterable<stdClass> a list of row IDs, identifying rows of constructive edits.
	 */
	private function findAllEdits( string $oldest, string $newest ): iterable {
		$query = $this->getServiceContainer()->getChangesListQueryFactory()->newQuery()
			->recentChangeFields()
			->startAt( $newest )
			->endAt( $oldest )
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
		$this->output( "Changes between $oldest and $newest: {$result->count()}\n" );
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
