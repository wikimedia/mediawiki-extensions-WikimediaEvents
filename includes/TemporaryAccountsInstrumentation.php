<?php
namespace WikimediaEvents;

use ManualLogEntry;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Stats\StatsFactory;

/**
 * Holds hook handlers emitting metrics related to the temporary accounts initiative (T357763).
 */
class TemporaryAccountsInstrumentation implements PageDeleteCompleteHook {
	private StatsFactory $statsFactory;

	public function __construct( StatsFactory $statsFactory ) {
		$this->statsFactory = $statsFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		// Track the rate of page deletions to be able to correlate with the rollout of temporary accounts
		// if necessary (T375503).
		$this->statsFactory
			->withComponent( 'WikimediaEvents' )
			->getCounter( 'users_page_delete_total' )
			->setLabel( 'wiki', WikiMap::getCurrentWikiId() )
			->increment();
	}
}
