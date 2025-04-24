<?php

namespace WikimediaEvents\TemporaryAccounts;

use MediaWiki\Auth\Hook\AuthenticationAttemptThrottledHook;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Page\Hook\ArticleProtectCompleteHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityUtils;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Stats\StatsFactory;
use WikimediaEvents\Services\WikimediaEventsRequestDetailsLookup;

/**
 * Holds hook handlers emitting metrics related to the temporary accounts initiative (T357763).
 */
class TemporaryAccountsInstrumentation extends AbstractTemporaryAccountsInstrumentation implements
	PageDeleteCompleteHook,
	PageSaveCompleteHook,
	BlockIpCompleteHook,
	AuthenticationAttemptThrottledHook,
	ArticleProtectCompleteHook
{

	private StatsFactory $statsFactory;
	private RevisionLookup $revisionLookup;
	private WikimediaEventsRequestDetailsLookup $wikimediaEventsRequestDetailsLookup;

	public function __construct(
		StatsFactory $statsFactory,
		RevisionLookup $revisionLookup,
		UserIdentityUtils $userIdentityUtils,
		UserFactory $userFactory,
		WikimediaEventsRequestDetailsLookup $wikimediaEventsRequestDetailsLookup
	) {
		parent::__construct( $userIdentityUtils, $userFactory );
		$this->statsFactory = $statsFactory;
		$this->revisionLookup = $revisionLookup;
		$this->wikimediaEventsRequestDetailsLookup = $wikimediaEventsRequestDetailsLookup;
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

	/**
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		if ( !$editResult->isRevert() ) {
			return;
		}

		$newestRevertedRevId = $editResult->getNewestRevertedRevisionId();
		if ( $newestRevertedRevId === null ) {
			return;
		}

		$newestRevertedRev = $this->revisionLookup->getRevisionById(
			$newestRevertedRevId,
			IDBAccessObject::READ_NORMAL,
			$wikiPage
		);
		if ( $newestRevertedRev === null ) {
			return;
		}

		$latestRevertedAuthor = $newestRevertedRev->getUser( $newestRevertedRev::RAW );

		// Track reverts by user type (T375501)
		$this->statsFactory
			->withComponent( 'WikimediaEvents' )
			->getCounter( 'user_revert_total' )
			->setLabel( 'wiki', WikiMap::getCurrentWikiId() )
			->setLabel( 'user', $this->getUserType( $latestRevertedAuthor ) )
			->increment();
	}

	/** @inheritDoc */
	public function onBlockIpComplete( $block, $user, $priorBlock ) {
		$target = $block->getTargetUserIdentity() ?? $block->getTargetName();
		$this->statsFactory->withComponent( 'WikimediaEvents' )
			->getCounter( 'block_target_total' )
			->setLabel( 'wiki', WikiMap::getCurrentWikiId() )
			->setLabel( 'user', $this->getUserType( $target ) )
			->increment();
	}

	/** @inheritDoc */
	public function onAuthenticationAttemptThrottled( string $type, ?string $username, ?string $ip ) {
		// Only interested in temporary account related throttling.
		if ( !in_array( $type, [ 'tempacctcreate', 'tempacctnameacquisition' ] ) ) {
			return;
		}

		$platformDetails = $this->wikimediaEventsRequestDetailsLookup->getPlatformDetails();
		$this->statsFactory->withComponent( 'WikimediaEvents' )
			->getCounter( 'temp_account_creation_throttled_total' )
			->setLabel( 'wiki', WikiMap::getCurrentWikiId() )
			->setLabel( 'type', $type )
			->setLabel( 'is_mobile', $platformDetails['isMobile'] )
			->setLabel( 'platform', $platformDetails['platform'] )
			->increment();
	}

	/** @inheritDoc */
	public function onArticleProtectComplete( $wikiPage, $user, $protect, $reason ) {
		// Don't count page un-protections as a protection.
		if ( !count( array_filter( $protect ) ) ) {
			return;
		}

		$this->statsFactory->withComponent( 'WikimediaEvents' )
			->getCounter( 'users_page_protect_total' )
			->setLabel( 'wiki', WikiMap::getCurrentWikiId() )
			->setLabel( 'source', 'core' )
			->increment();
	}
}
