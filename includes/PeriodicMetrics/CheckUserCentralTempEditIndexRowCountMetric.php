<?php

namespace WikimediaEvents\PeriodicMetrics;

use MediaWiki\CheckUser\CheckUserQueryInterface;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * A metric for the row count of the cuci_temp_edit table used monitor if it's growing too fast (T389055)
 */
class CheckUserCentralTempEditIndexRowCountMetric implements IMetric {

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	/** @inheritDoc */
	public function calculate(): int {
		$dbr = $this->dbProvider->getReplicaDatabase( CheckUserQueryInterface::VIRTUAL_GLOBAL_DB_DOMAIN );
		return $dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'cuci_temp_edit' )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** @inheritDoc */
	public function getLabels(): array {
		return [ 'table' => 'cuci_temp_edit' ];
	}

	/** @inheritDoc */
	public function getName(): string {
		return 'checkuser_central_index_row_count_total';
	}
}
