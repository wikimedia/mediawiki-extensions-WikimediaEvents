<?php
use MediaWiki\Logger\LoggerFactory;

/**
 * Hooks used for Wikimedia-related logging
 *
 * @author Ori Livneh <ori@wikimedia.org>
 * @author Matthew Flaschen <mflaschen@wikimedia.org>
 * @author Benny Situ <bsitu@wikimedia.org>
 */
class WikimediaEventsHooks {

	/**
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( &$out, &$skin ) {
		$out->addModules( 'ext.wikimediaEvents' );

		if ( $out->getUser()->isLoggedIn() ) {
			$out->addModules( 'ext.wikimediaEvents.loggedin' );
		}
	}

	/**
	 * On XAnalyticsHeader
	 *
	 * When adding new headers here please update the docs:
	 * https://wikitech.wikimedia.org/wiki/X-Analytics
	 *
	 * Insert a 'page_id' key with the page ID as value (if the request is for a page with a pageid)
	 * Insert a 'ns' key with the namespace ID as value (if the request is for a valid title)
	 * Insert a 'special' key with the resolved name of the special page (if the request is for a
	 * special page)
	 *
	 * Add a 'loggedIn' key with the value of 1 if the user is logged in
	 */
	public static function onXAnalyticsHeader( OutputPage $out, array &$headerItems ) {
		$title = $out->getTitle();
		if ( $title !== null ) {
			$pageId = $title->getArticleID();
			$headerItems['ns'] = $title->getNamespace();
			if ( is_int( $pageId ) && $pageId > 0 ) {
				$headerItems['page_id'] = $pageId;
			}
			if ( $title->isSpecialPage() ) {
				list( $name, /* $subpage */ ) = SpecialPageFactory::resolveAlias( $title->getDBkey() );
				if ( $name !== null ) {
					$headerItems['special'] = $name;
				}
			}
		}

		if ( $out->getUser()->isLoggedIn() ) {
			$headerItems['loggedIn'] = 1;
		}
	}

	/**
	 * Log server-side event on successful page edit.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 * @see https://meta.wikimedia.org/wiki/Schema:PageContentSaveComplete
	 */
	// Imported from EventLogging extension
	public static function onPageContentSaveComplete( $article, $user, $content, $summary,
		$isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId ) {

		if ( !$revision ) {
			return;
		}

		if ( PHP_SAPI !== 'cli' ) {
			DeferredUpdates::addCallableUpdate( function () {
				$context = RequestContext::getMain();
				$timing = $context->getTiming();
				$measure = $timing->measure( 'editResponseTime', 'requestStart', 'requestShutdown' );
				if ( $measure !== false ) {
					$context->getStats()->timing( 'timing.editResponseTime', $measure['duration'] * 1000 );
				}
			} );
		}

		$isAPI = defined( 'MW_API' );
		$isMobile = class_exists( 'MobileContext' ) && MobileContext::singleton()->shouldDisplayMobileView();
		$revId = $revision->getId();
		$title = $article->getTitle();

		$event = array(
			'revisionId' => $revId,
			'isAPI'      => $isAPI,
			'isMobile'   => $isMobile,
		);

		if ( isset( $_SERVER[ 'HTTP_USER_AGENT' ] ) ) {
			$event[ 'userAgent' ] = $_SERVER[ 'HTTP_USER_AGENT' ];
		}
		EventLogging::logEvent( 'PageContentSaveComplete', 5588433, $event );

		if ( $user->isAnon() ) {
			return;
		}

		// Get the user's age, measured in seconds since registration.
		$age = time() - wfTimestampOrNull( TS_UNIX, $user->getRegistration() );

		$editCount = $user->getEditCount();


		// Check if this edit brings the user's total edit count to a value
		// that is a factor of ten. We consider these 'milestones'. The rate
		// at which editors are hitting such milestones and the time it takes
		// are important indicators of community health.
		if ( $editCount === 0 || preg_match( '/^9+$/' , "$editCount" ) ) {
			$milestone = $editCount + 1;
			$stats = RequestContext::getMain()->getStats();
			$stats->increment( "editor.milestones.{$milestone}" );
			$stats->timing( "editor.milestones.timing.{$milestone}", $age );
		}

		// If the editor signed up in the last thirty days, and if this is an
		// NS_MAIN edit, log a NewEditorEdit event.
		if ( $age <= 2592000 && $title->inNamespace( NS_MAIN ) ) {
			EventLogging::logEvent( 'NewEditorEdit', 6792669, array(
					'userId'    => $user->getId(),
					'userAge'   => $age,
					'editCount' => $editCount,
					'pageId'    => $article->getId(),
					'revId'     => $revId,
					'isAPI'     => $isAPI,
					'isMobile'  => $isMobile,
				) );
		}

		return true;
	}

	/**
	 * Log and update statistics whenever an editor reaches the active editor
	 * threshold for this month.
	 *
	 * @see https://meta.wikimedia.org/wiki/Schema:EditorActivation
	 * @see https://www.mediawiki.org/wiki/Analytics/Metric_definitions#Active_editor
	 *
	 * @param Revision &$revision
	 * @param string $data
	 * @param array $flags
	 */
	public static function onRevisionInsertComplete( &$revision, $data, $flags ) {
		$context = RequestContext::getMain();
		$user = User::newFromId( $revision->getUser( Revision::RAW ) );

		// Anonymous users and bots don't count (sorry!)
		if ( $user->isAnon() || $user->isAllowed( 'bot' ) ) {
			return;
		}

		// Only mainspace edits qualify
		if ( !$revision->getTitle()->inNamespace( NS_MAIN ) ) {
			return;
		}

		// Check if this is the user's fifth mainspace edit this month.
		// If it is, then this editor has just made the cut as an active
		// editor for this wiki for this month.
		DeferredUpdates::addCallableUpdate( function () use ( $context, $user ) {
			$db = wfGetDB( DB_MASTER );

			$since = date( 'Ym' ) . '00000000';
			$numMainspaceEditsThisMonth = $db->selectRowCount(
				array( 'revision', 'page' ),
				'1',
				array(
					'rev_user'         => $user->getId(),
					'rev_timestamp >= ' . $db->addQuotes( $db->timestamp( $since ) ),
					'page_namespace'   => NS_MAIN,
				),
				__FILE__ . ':' . __LINE__,
				array( 'LIMIT' => 6 ),
				array( 'page' => array( 'INNER JOIN', 'rev_page = page_id' ) )
			);

			if ( $numMainspaceEditsThisMonth === 5 ) {
				$month = date( 'm-Y' );
				$context->getStats()->increment( 'editor.activation.' . $month );
				EventLogging::logEvent( 'EditorActivation', 14208837, array(
					'userId' => $user->getId(),
					'month'  => $month,
				) );
			}
		} );
	}

	/**
	 * Handler for UserSaveOptions hook.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/UserSaveOptions
	 * @param User $user user whose options are being saved
	 * @param array $options Options being saved
	 * @return bool true in all cases
	 */
	// Modified version of original method from the Echo extension
	public static function onUserSaveOptions( $user, &$options ) {
		global $wgOut;

		// Capture user options saved via Special:Preferences or ApiOptions

		// TODO (mattflaschen, 2013-06-13): Ideally this would be done more cleanly without
		// looking explicitly at page names and URL parameters.
		// Maybe a userInitiated flag passed to saveSettings would work.
		if ( ( $wgOut && $wgOut->getTitle() && $wgOut->getTitle()->isSpecial( 'Preferences' ) )
			|| ( defined( 'MW_API' ) && $wgOut->getRequest()->getVal( 'action' ) === 'options' )
		) {
			// $clone is the current user object before the new option values are set
			$clone = User::newFromId( $user->getId() );

			$commonData = array(
				'version' => '1',
				'userId' => $user->getId(),
				'saveTimestamp' => wfTimestampNow(),
			);

			foreach ( $options as $optName => $optValue ) {
				// loose comparision is required since some of the values
				// are not consistent in the two variables, eg, '' vs false
				if ( $clone->getOption( $optName ) != $optValue ) {
					$event = array(
						'property' => $optName,
						// Encode value as JSON.
						// This is parseable and allows a consistent type for validation.
						'value' => FormatJson::encode( $optValue ),
						'isDefault' => User::getDefaultOption( $optName ) == $optValue,
					) + $commonData;
					EventLogging::logEvent( 'PrefUpdate', 5563398, $event );
				}
			}
		}

		return true;
	}

	/**
	 * Logs article deletions using the PageDeletion schema.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	 * @see https://meta.wikimedia.org/wiki/Schema:PageDeletion
	 * @param WikiPage $article The article that was deleted
	 * @param User $user The user that deleted the article
	 * @param string $reason The reason that the article was deleted
	 * @param integer $id The ID of the article that was deleted
	 */
	public static function onArticleDeleteComplete( WikiPage $article, User $user, $reason, $id ) {
		$title = $article->getTitle();
		EventLogging::logEvent( 'PageDeletion', 7481655, array(
				'userId' => $user->getId(),
				'userText' => $user->getName(),
				'pageId' => $id,
				'namespace' => $title->getNamespace(),
				'title' => $title->getDBkey(),
				'comment' => $reason,
			) );
		return true;
	}

	/**
	 * Logs article undelete using pageRestored schema
	 *
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleUndelete
	 * @see https://meta.wikimedia.org/wiki/Schema:PageRestoration
	 * @param Title $title Title of article restored
	 * @param boolean $created whether the revision created the page (default false)
	 * @param string $comment Reason for undeleting the page
	 * @param integer $oldPageId The ID of the article that was deleted
	 */
	public static function onArticleUndelete( $title, $created, $comment, $oldPageId ) {
		global $wgUser;
		EventLogging::logEvent( 'PageRestoration', 7758372, array(
				'userId' => $wgUser->getId(),
				'userText' => $wgUser->getName(),
				'oldPageId' => $oldPageId,
				'newPageId' => $title->getArticleID(),
				'namespace' => $title->getNamespace(),
				'title' => $title->getDBkey(),
				'comment' => $comment,
		) );
	}

	/**
	 * Logs a page creation event, based on the given parameters.
	 *
	 * Currently, this is either a normal page creation, or an automatic creation
	 * of a redirect when a page is moved.
	 *
	 * @see https://meta.wikimedia.org/wiki/Schema:PageCreation
	 *
	 * @param User $user user creating the page
	 * @param int $pageId page ID of new page
	 * @param Title $title title of created page
	 * @param int $revId revision ID of first revision of created page
	 */
	protected static function logPageCreation( User $user, $pageId, Title $title,
		$revId ) {

		EventLogging::logEvent( 'PageCreation', 7481635, array(
				'userId' => $user->getId(),
				'userText' => $user->getName(),
				'pageId' => $pageId,
				'namespace' => $title->getNamespace(),
				'title' => $title->getDBkey(),
				'revId' => $revId,
		) );
	}

	/**
	 * Logs title moves with the PageMove schema.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/TitleMoveComplete
	 * @see https://meta.wikimedia.org/wiki/Schema:PageMove
	 * @param Title $oldTitle The title of the old article (moved from)
	 * @param Title $newTitle The title of the new article (moved to)
	 * @param User $user The user who moved the article
	 * @param integer $pageId The page ID of the old article
	 * @param integer $redirectId The page ID of the redirect, 0 if a redirect wasn't
	 *   created
	 * @param string $reason The reason that the title was moved
	 * @return bool true in all cases
	 */
	public static function onTitleMoveComplete( Title $oldTitle, Title $newTitle, User $user, $pageId, $redirectId, $reason ) {
		EventLogging::logEvent( 'PageMove', 7495717, array(
				'userId' => $user->getId(),
				'userText' => $user->getName(),
				'pageId' => $pageId,
				'oldNamespace' => $oldTitle->getNamespace(),
				'oldTitle' => $oldTitle->getDBkey(),
				'newNamespace' => $newTitle->getNamespace(),
				'newTitle' => $newTitle->getDBkey(),
				'redirectId' => $redirectId,
				'comment' => $reason,
			) );

		if ( $redirectId !== 0 ) {
			// The redirect was not suppressed, so log its creation.
			self::logPageCreation( $user, $redirectId, $oldTitle, $oldTitle->getLatestRevID() );
		}

		return true;
	}

	/**
	 * Logs page creations with the PageCreation schema.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentInsertComplete
	 * @see https://meta.wikimedia.org/wiki/Schema:PageCreation
	 * @param WikiPage $wikipage page just created
	 * @param User $user user who created the page
	 * @param Content $content content of new page
	 * @param string $summary summary given by creating user
	 * @param bool $isMinor whether the edit is marked as minor (not actually possible for new
	 *   pages)
	 * @param $isWatch not used
	 * @param $section not used
	 * @param int $flags bit flags about page creation
	 * @param Revision $revision first revision of new page
	 */
	public static function onPageContentInsertComplete( WikiPage $wikiPage, User $user,
		Content $content, $summary, $isMinor, $isWatch, $section, $flags, Revision $revision ) {

		$title = $wikiPage->getTitle();
		self::logPageCreation( $user, $wikiPage->getId(), $title, $revision->getId() );

		return true;
	}

	/**
	 * Logs edit conflicts with the EditConflict schema.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/EditPageBeforeConflictDiff
	 * @see https://meta.wikimedia.org/wiki/Schema:EditConflict
	 * @param EditPage &$editor
	 * @param OutputPage &$out
	 * @return bool true in all cases
	 */
	public static function onEditPageBeforeConflictDiff( &$editor, &$out ) {
		$user = $out->getUser();
		$title = $out->getTitle();

		EventLogging::logEvent( 'EditConflict', 8860941, array(
			'userId' => $user->getId(),
			'userText' => $user->getName(),
			'pageId' => $title->getArticleID(),
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBkey(),
			'revId' => (int)$title->getLatestRevID(),
		) );

		return true;
	}

	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $wgWMEStatsdBaseUri;
		$vars['wgWMEStatsdBaseUri'] = $wgWMEStatsdBaseUri;
	}

	/**
	 * Register change tags.
	 *
	 * @param array &$tags
	 * @return bool
	 */
	public static function onListDefinedTags( &$tags ) {
		$tags[] = 'HHVM';
		if ( wfWikiID() === 'commonswiki' ) {
			$tags[] = 'cross-wiki-upload';
			// For A/B test
			$tags[] = 'cross-wiki-upload-1';
			$tags[] = 'cross-wiki-upload-2';
			$tags[] = 'cross-wiki-upload-3';
			$tags[] = 'cross-wiki-upload-4';
		}
		return true;
	}

	/**
	 * Mark active change tags.
	 *
	 * @param array &$tags
	 * @return bool
	 */
	public static function onChangeTagsListActive( &$tags ) {
		if ( wfWikiID() === 'commonswiki' ) {
			$tags[] = 'cross-wiki-upload';
			// For A/B test
			$tags[] = 'cross-wiki-upload-1';
			$tags[] = 'cross-wiki-upload-2';
			$tags[] = 'cross-wiki-upload-3';
			$tags[] = 'cross-wiki-upload-4';
		}
		return true;
	}

	public static function onSpecialSearchGoResult( $term, Title $title, &$url ) {
		$request = RequestContext::getMain()->getRequest();

		$wprov = $request->getVal( 'wprov' );
		if ( $wprov ) {
			$url = $title->getFullURL( [ 'wprov' => $wprov ] );
		}

		return true;
	}

	/**
	 * The javascript that records search metrics needs to know if it is on a
	 * SERP or not. This ends up being non-trivial due to localization, so
	 * make it trivial by injecting a boolean value to check.
	 */
	public static function onSpecialSearchResults( $term, &$titleMatches, &$textMatches ) {
		global $wgOut;

		$wgOut->addJsConfigVars( array(
			'wgIsSearchResultPage' => true,
		) );
		$wgOut->addModules( 'ext.wikimediaEvents.didyoumean' );

		return true;
	}

	/**
	 * Add a change tag 'cross-wiki-upload' to cross-wiki uploads to Commons, to track usage of the
	 * new feature. (Both to track adoption, and to let Commons editors review the uploads.) (T115328)
	 *
	 * @param $recentChange RecentChange
	 * @return bool
	 */
	public static function onRecentChangeSave( RecentChange $recentChange ) {
		if ( !defined( 'MW_API' ) ) {
			return true;
		}
		if ( wfWikiID() !== 'commonswiki' ) {
			return true;
		}
		if ( !(
			$recentChange->getAttribute( 'rc_log_type' ) === 'upload' &&
			$recentChange->getAttribute( 'rc_log_action' ) === 'upload'
		) ) {
			return true;
		}
		$request = RequestContext::getMain()->getRequest();
		if ( !$request->response()->getHeader( 'Access-Control-Allow-Origin' ) ) {
			return true;
		}

		// A/B test
		$bucket = $request->getVal( 'bucket' );
		if ( !in_array( $bucket, array( '1', '2', '3', '4' ) ) ) {
			$bucket = null;
		}

		$tags = array( 'cross-wiki-upload' );
		if ( $bucket ) {
			$tags[] = "cross-wiki-upload-$bucket";
		}
		ChangeTags::addTags(
			$tags,
			$recentChange->getAttribute( 'rc_id' ),
			$recentChange->getAttribute( 'rc_this_oldid' ),
			$recentChange->getAttribute( 'rc_logid' )
		);

		return true;
	}

	public static function onArticleViewHeader() {
		DeferredUpdates::addCallableUpdate( function () {
			$context = RequestContext::getMain();
			$timing = $context->getTiming();
			if ( class_exists( 'MobileContext' )
				&& MobileContext::singleton()->shouldDisplayMobileView()
			) {
				$platform = 'mobile';
			} else {
				$platform = 'desktop';
			}

			$measure = $timing->measure( 'viewResponseTime', 'requestStart', 'requestShutdown' );
			if ( $measure !== false ) {
				$context->getStats()->timing(
					"timing.viewResponseTime.{$platform}", $measure['duration'] * 1000 );
			}
		} );
	}

	/**
	 * AbuseFilter-GenerateUserVars hook handler
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param User $user object
	 */
	public static function onAbuseFilterGenerateUserVars( $vars, $user ) {
		global $wgRequest;

		$vars->setVar(
			'user_wpzero',
			$wgRequest->getHeader( 'X-Carrier' ) !== false
		);
	}

	/**
	 * AbuseFilter-builder hook handler
	 *
	 * @param array $builder
	 * @return bool
	 */
	public static function onAbuseFilterBuilder( &$builder ) {
		$builder['vars']['user_wpzero'] = 'user-wpzero';
	}

	public static function onUnitTestsList( array &$files ) {
		// @codeCoverageIgnoreStart
		$directoryIterator = new RecursiveDirectoryIterator( __DIR__ . '/tests/phpunit/' );

		/**
		 * @var SplFileInfo $fileInfo
		 */
		$ourFiles = array();
		foreach ( new RecursiveIteratorIterator( $directoryIterator ) as $fileInfo ) {
			if ( substr( $fileInfo->getFilename(), -8 ) === 'Test.php' ) {
				$ourFiles[] = $fileInfo->getPathname();
			}
		}

		$files = array_merge( $files, $ourFiles );
		return true;
		// @codeCoverageIgnoreEnd
	}

}
