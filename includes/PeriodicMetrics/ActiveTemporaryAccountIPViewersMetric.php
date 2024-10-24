<?php

namespace WikimediaEvents\PeriodicMetrics;

use MediaWiki\CheckUser\Logging\TemporaryAccountLogger;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * A metric for the total number of users who have used the temporary account IP reveal feature in the last 7 days at
 * least once. This metric is calculated per-wiki, but combining the numbers for each wiki to get a global count
 * won't work because a user may be active on multiple wikis.
 */
class ActiveTemporaryAccountIPViewersMetric extends PerWikiMetric {

	/**
	 * @var int A user is considered actively using the tool if they have used it at least once within 30 days.
	 *   This matches the definition often used to define active admins.
	 */
	private const RECENT_CHECKS_CUTOFF = 3600 * 24 * 30;

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	/** @inheritDoc */
	public function calculate(): int {
		// Index to use on the logging table is log_type_time
		$dbr = $this->dbProvider->getReplicaDatabase();

		$recentChecksCutoff = $dbr->timestamp( ConvertibleTimestamp::time() - self::RECENT_CHECKS_CUTOFF );

		return $dbr->newSelectQueryBuilder()
			->select( 'COUNT(DISTINCT log_actor)' )
			->from( 'logging' )
			->where( [
				'log_type' => TemporaryAccountLogger::LOG_TYPE,
				$dbr->expr( 'log_action', '!=', TemporaryAccountLogger::ACTION_CHANGE_ACCESS ),
				$dbr->expr( 'log_timestamp', '>', $recentChecksCutoff ),
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'active_temporary_account_ip_viewers_total';
	}
}
