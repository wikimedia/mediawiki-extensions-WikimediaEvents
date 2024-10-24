<?php

namespace WikimediaEvents\PeriodicMetrics;

use InvalidArgumentException;
use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\User\UserGroupManager;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Allows the construction of IMetric classes.
 * Copy, with some modification, from GrowthExperiments
 * includes/PeriodicMetrics/MediaModerationMetricsFactory.php
 */
class WikimediaEventsMetricsFactory {

	/** @var string[] */
	private const METRICS = [
		LocallyAutoEnrolledTemporaryAccountIPViewersMetric::class,
		LocalTemporaryAccountIPViewersMetric::class,
		ActiveTemporaryAccountIPViewersMetric::class,
	];

	private GroupPermissionsLookup $groupPermissionsLookup;
	private UserGroupManager $userGroupManager;
	private IConnectionProvider $dbProvider;

	public function __construct(
		GroupPermissionsLookup $groupPermissionsLookup,
		UserGroupManager $userGroupManager,
		IConnectionProvider $dbProvider
	) {
		$this->groupPermissionsLookup = $groupPermissionsLookup;
		$this->userGroupManager = $userGroupManager;
		$this->dbProvider = $dbProvider;
	}

	/**
	 * @return string[] A list of all classes that extend {@link IMetric}.
	 */
	public function getAllMetrics(): array {
		return self::METRICS;
	}

	/**
	 * Returns an instance of the class that extends IMetric given
	 * in $className.
	 *
	 * @param string $className
	 * @return IMetric
	 * @throws InvalidArgumentException if metric class name is not supported
	 */
	public function newMetric( string $className ): IMetric {
		switch ( $className ) {
			case LocallyAutoEnrolledTemporaryAccountIPViewersMetric::class:
				return new LocallyAutoEnrolledTemporaryAccountIPViewersMetric(
					$this->groupPermissionsLookup, $this->userGroupManager, $this->dbProvider
				);
			case LocalTemporaryAccountIPViewersMetric::class:
				return new LocalTemporaryAccountIPViewersMetric(
					$this->groupPermissionsLookup, $this->userGroupManager, $this->dbProvider
				);
			case ActiveTemporaryAccountIPViewersMetric::class:
				return new ActiveTemporaryAccountIPViewersMetric( $this->dbProvider );
			default:
				throw new InvalidArgumentException( 'Unsupported metric class name' );
		}
	}
}
