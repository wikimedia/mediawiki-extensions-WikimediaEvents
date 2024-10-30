<?php

namespace WikimediaEvents\PeriodicMetrics;

use GlobalPreferences\GlobalPreferencesServices;
use InvalidArgumentException;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentityLookup;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Allows the construction of IMetric classes.
 * Copy, with some modification, from GrowthExperiments
 * includes/PeriodicMetrics/MediaModerationMetricsFactory.php
 */
class WikimediaEventsMetricsFactory {

	/** @var string[] */
	private const PER_WIKI_METRICS = [
		LocallyAutoEnrolledTemporaryAccountIPViewersMetric::class,
		LocalTemporaryAccountIPViewersMetric::class,
		ActiveTemporaryAccountIPViewersMetric::class,
		LocalTemporaryAccountIPViewersWithEnabledPreferenceMetric::class,
	];

	/** @var string[] */
	private const GLOBAL_METRICS = [
		GloballyAutoEnrolledTemporaryAccountIPViewersMetric::class,
		GlobalTemporaryAccountIPViewersMetric::class,
		GlobalTemporaryAccountIPViewersWithEnabledPreferenceMetric::class,
	];

	private GroupPermissionsLookup $groupPermissionsLookup;
	private UserGroupManager $userGroupManager;
	private IConnectionProvider $dbProvider;
	private ExtensionRegistry $extensionRegistry;
	private CentralIdLookup $centralIdLookup;
	private UserIdentityLookup $userIdentityLookup;

	public function __construct(
		GroupPermissionsLookup $groupPermissionsLookup,
		UserGroupManager $userGroupManager,
		IConnectionProvider $dbProvider,
		ExtensionRegistry $extensionRegistry,
		CentralIdLookup $centralIdLookup,
		UserIdentityLookup $userIdentityLookup
	) {
		$this->groupPermissionsLookup = $groupPermissionsLookup;
		$this->userGroupManager = $userGroupManager;
		$this->dbProvider = $dbProvider;
		$this->extensionRegistry = $extensionRegistry;
		$this->centralIdLookup = $centralIdLookup;
		$this->userIdentityLookup = $userIdentityLookup;
	}

	/**
	 * @return string[] A list of all classes that implement {@link IMetric}.
	 */
	public function getAllMetrics(): array {
		return array_merge( self::PER_WIKI_METRICS, self::GLOBAL_METRICS );
	}

	/**
	 * @return string[] A list of all classes that extend {@link PerWikiMetric}.
	 */
	public function getAllPerWikiMetrics(): array {
		return self::PER_WIKI_METRICS;
	}

	/**
	 * @return string[] A list of all classes that implement {@link IMetric} and do not extend {@link PerWikiMetric}.
	 */
	public function getAllGlobalMetrics(): array {
		return self::GLOBAL_METRICS;
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
		if ( $this->extensionRegistry->isLoaded( 'CentralAuth' ) ) {
			$globalGroupLookup = CentralAuthServices::getGlobalGroupLookup();
			$centralAuthDatabaseManager = CentralAuthServices::getDatabaseManager();

			if ( $this->extensionRegistry->isLoaded( 'GlobalPreferences' ) ) {
				$globalPreferencesServices = GlobalPreferencesServices::wrap( MediaWikiServices::getInstance() );
				$globalPreferencesConnectionProvider = $globalPreferencesServices
					->getGlobalPreferencesConnectionProvider();

				switch ( $className ) {
					case GlobalTemporaryAccountIPViewersWithEnabledPreferenceMetric::class:
						return new GlobalTemporaryAccountIPViewersWithEnabledPreferenceMetric(
							$globalGroupLookup, $centralAuthDatabaseManager, $globalPreferencesConnectionProvider
						);
				}
			}

			switch ( $className ) {
				case GloballyAutoEnrolledTemporaryAccountIPViewersMetric::class:
					return new GloballyAutoEnrolledTemporaryAccountIPViewersMetric(
						$globalGroupLookup, $centralAuthDatabaseManager
					);
				case GlobalTemporaryAccountIPViewersMetric::class:
					return new GlobalTemporaryAccountIPViewersMetric(
						$globalGroupLookup, $centralAuthDatabaseManager
					);
			}
		}

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
			case LocalTemporaryAccountIPViewersWithEnabledPreferenceMetric::class:
				return new LocalTemporaryAccountIPViewersWithEnabledPreferenceMetric(
					$this->groupPermissionsLookup, $this->userGroupManager, $this->dbProvider,
					$this->extensionRegistry, $this->centralIdLookup, $this->userIdentityLookup
				);
			default:
				throw new InvalidArgumentException( 'Unsupported metric class name' );
		}
	}
}
