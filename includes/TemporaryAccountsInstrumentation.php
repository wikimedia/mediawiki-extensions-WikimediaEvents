<?php
namespace WikimediaEvents;

use IDBAccessObject;
use ManualLogEntry;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Stats\StatsFactory;

/**
 * Holds hook handlers emitting metrics related to the temporary accounts initiative (T357763).
 */
class TemporaryAccountsInstrumentation implements PageDeleteCompleteHook, PageSaveCompleteHook, BlockIpCompleteHook {
	public const ACCOUNT_TYPE_TEMPORARY = 'temp';
	public const ACCOUNT_TYPE_ANON = 'anon';
	public const ACCOUNT_TYPE_IP_RANGE = 'iprange';
	public const ACCOUNT_TYPE_BOT = 'bot';
	public const ACCOUNT_TYPE_NORMAL = 'normal';

	private StatsFactory $statsFactory;
	private RevisionLookup $revisionLookup;
	private UserIdentityUtils $userIdentityUtils;
	private UserFactory $userFactory;

	public function __construct(
		StatsFactory $statsFactory,
		RevisionLookup $revisionLookup,
		UserIdentityUtils $userIdentityUtils,
		UserFactory $userFactory
	) {
		$this->statsFactory = $statsFactory;
		$this->revisionLookup = $revisionLookup;
		$this->userIdentityUtils = $userIdentityUtils;
		$this->userFactory = $userFactory;
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

	/**
	 * Get the type of user for use as a Prometheus label.
	 * @param UserIdentity|string $user For single IP addresses, temporary accounts, named accounts,
	 *  and bot accounts, this will be a user identity. For IP ranges, this will be a string.
	 * @return string One of the TemporaryAccountsInstrumentation::ACCOUNT_TYPE_* constants
	 */
	private function getUserType( $user ): string {
		if ( !$user instanceof UserIdentity ) {
			// Must be an IP range.
			return self::ACCOUNT_TYPE_IP_RANGE;
		}
		if ( $this->userIdentityUtils->isTemp( $user ) ) {
			return self::ACCOUNT_TYPE_TEMPORARY;
		}

		if ( !$user->isRegistered() ) {
			return self::ACCOUNT_TYPE_ANON;
		}

		$user = $this->userFactory->newFromUserIdentity( $user );
		if ( $user->isBot() ) {
			return self::ACCOUNT_TYPE_BOT;
		}

		return self::ACCOUNT_TYPE_NORMAL;
	}
}
