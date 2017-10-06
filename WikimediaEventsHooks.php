<?php

use MediaWiki\MediaWikiServices;

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
	 * @param OutputPage $out
	 * @param array &$headerItems
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
	 *
	 * Imported from EventLogging extension
	 *
	 * @param WikiPage $article
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param bool $isMinor
	 * @param bool $isWatch
	 * @param string $section
	 * @param int $flags
	 * @param Revision $revision
	 * @param Status $status
	 * @param int $baseRevId
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 * @see https://meta.wikimedia.org/wiki/Schema:PageContentSaveComplete
	 */
	public static function onPageContentSaveComplete(
		$article, $user, $content, $summary,
		$isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId
	) {
		if ( !$revision ) {
			return;
		}

		$title = $article->getTitle();
		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();

		if ( PHP_SAPI !== 'cli' ) {
			if ( $user->isBot() ) {
				$accType = 'bot'; // registered bot
			} elseif ( RequestContext::getMain()->getRequest()->getCheck( 'maxlag' ) ) {
				$accType = 'throttled'; // probably an unregistered bot
			} else {
				$accType = 'normal';
			}

			if ( MWNamespace::isContent( $title->getNamespace() ) ) {
				$nsType = 'content';
			} elseif ( MWNamespace::isTalk( $title->getNamespace() ) ) {
				$nsType = 'talk';
			} else {
				$nsType = 'meta';
			}

			$size = $content->getSize();
			if ( defined( 'MW_API' ) ) {
				$entry = 'api';
			} elseif ( defined( 'MEDIAWIKI_JOB_RUNNER' ) ) {
				$entry = 'job';
			} elseif ( PHP_SAPI === 'cli' ) {
				$entry = 'cli';
			} else {
				$entry = 'index';
			}

			DeferredUpdates::addCallableUpdate(
				function () use ( $stats, $size, $nsType, $accType, $entry ) {
					$timing = RequestContext::getMain()->getTiming();
					$measure = $timing->measure(
						'editResponseTime', 'requestStart', 'requestShutdown' );
					if ( $measure !== false ) {
						$timeMs = $measure['duration'] * 1000;
						$stats->timing( 'timing.editResponseTime', $timeMs );
						$stats->timing( "timing.editResponseTime.page.$nsType", $timeMs );
						$stats->timing( "timing.editResponseTime.user.$accType", $timeMs );
						$stats->timing( "timing.editResponseTime.entry.$entry", $timeMs );
						$stats->gauge( 'edit.newContentSize', $size );
					}
				}
			);
		}

		$isAPI = defined( 'MW_API' );
		$isMobile = class_exists( 'MobileContext' )
			&& MobileContext::singleton()->shouldDisplayMobileView();
		$revId = $revision->getId();

		$event = [
			'revisionId' => $revId,
			'isAPI'      => $isAPI,
			'isMobile'   => $isMobile,
		];

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
		if ( $editCount === 0 || preg_match( '/^9+$/', "$editCount" ) ) {
			$milestone = $editCount + 1;
			$stats->increment( "editor.milestones.{$milestone}" );
			$stats->timing( "editor.milestones.timing.{$milestone}", $age );
		}

		// If the editor signed up in the last thirty days, and if this is an
		// NS_MAIN edit, log a NewEditorEdit event.
		if ( $age <= 2592000 && $title->inNamespace( NS_MAIN ) ) {
			EventLogging::logEvent( 'NewEditorEdit', 6792669, [
					'userId'    => $user->getId(),
					'userAge'   => $age,
					'editCount' => $editCount,
					'pageId'    => $article->getId(),
					'revId'     => $revId,
					'isAPI'     => $isAPI,
					'isMobile'  => $isMobile,
				] );
		}
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
		DeferredUpdates::addCallableUpdate( function () use ( $user ) {
			$db = wfGetDB( DB_MASTER );

			$since = date( 'Ym' ) . '00000000';
			$numMainspaceEditsThisMonth = $db->selectRowCount(
				[ 'revision', 'page' ],
				'1',
				[
					'rev_user'         => $user->getId(),
					'rev_timestamp >= ' . $db->addQuotes( $db->timestamp( $since ) ),
					'page_namespace'   => NS_MAIN,
				],
				__FILE__ . ':' . __LINE__,
				[ 'LIMIT' => 6 ],
				[ 'page' => [ 'INNER JOIN', 'rev_page = page_id' ] ]
			);

			if ( $numMainspaceEditsThisMonth === 5 ) {
				$month = date( 'm-Y' );
				MediaWikiServices::getInstance()
					->getStatsdDataFactory()->increment( 'editor.activation.' . $month );
				EventLogging::logEvent( 'EditorActivation', 14208837, [
					'userId' => $user->getId(),
					'month'  => $month,
				] );
			}
		} );
	}

	/**
	 * Handler for UserSaveOptions hook.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/UserSaveOptions
	 * @param User $user user whose options are being saved
	 * @param array &$options Options being saved
	 * @return bool true in all cases
	 */
	public static function onUserSaveOptions( $user, &$options ) {
		// Modified version of original method from the Echo extension
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

			$commonData = [
				'version' => '1',
				'userId' => $user->getId(),
				'saveTimestamp' => wfTimestampNow(),
			];

			foreach ( $options as $optName => $optValue ) {
				// loose comparision is required since some of the values
				// are not consistent in the two variables, eg, '' vs false
				if ( $clone->getOption( $optName ) != $optValue ) {
					$event = [
						'property' => $optName,
						// Encode value as JSON.
						// This is parseable and allows a consistent type for validation.
						'value' => FormatJson::encode( $optValue ),
						'isDefault' => User::getDefaultOption( $optName ) == $optValue,
					] + $commonData;
					EventLogging::logEvent( 'PrefUpdate', 5563398, $event );
				}
			}
		}

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

		EventLogging::logEvent( 'EditConflict', 8860941, [
			'userId' => $user->getId(),
			'userText' => $user->getName(),
			'pageId' => $title->getArticleID(),
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBkey(),
			'revId' => (int)$title->getLatestRevID(),
		] );

		return true;
	}

	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $wgWMEStatsdBaseUri, $wgWMEReadingDepthSamplingRate,
			$wgWMEReadingDepthEnabled, $wgWMEPrintSamplingRate,
			$wgWMEPrintEnabled;

		$vars['wgWMEStatsdBaseUri'] = $wgWMEStatsdBaseUri;
		$vars['wgWMEReadingDepthSamplingRate'] = $wgWMEReadingDepthSamplingRate;
		$vars['wgWMEReadingDepthEnabled'] = $wgWMEReadingDepthEnabled;
		$vars['wgWMEPrintSamplingRate'] = $wgWMEPrintSamplingRate;
		$vars['wgWMEPrintEnabled'] = $wgWMEPrintEnabled;
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
	 * @param string $term
	 * @param SearchResultSet $titleMatches
	 * @param SearchResultSet $textMatches
	 * @return true
	 */
	public static function onSpecialSearchResults( $term, $titleMatches, $textMatches ) {
		global $wgOut;

		$wgOut->addJsConfigVars( [
			'wgIsSearchResultPage' => true,
		] );

		return true;
	}

	/**
	 * Add a change tag 'cross-wiki-upload' to cross-wiki uploads to Commons, to track usage of the
	 * new feature. (Both to track adoption, and to let Commons editors review the uploads.) (T115328)
	 *
	 * @param RecentChange $recentChange
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
		if ( !in_array( $bucket, [ '1', '2', '3', '4' ] ) ) {
			$bucket = null;
		}

		$tags = [ 'cross-wiki-upload' ];
		if ( $bucket ) {
			$tags[] = "cross-wiki-upload-$bucket";
		}
		$recentChange->addTags( $tags );

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
				MediaWikiServices::getInstance()->getStatsdDataFactory()->timing(
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
	 * @param array &$builder
	 * @return bool
	 */
	public static function onAbuseFilterBuilder( &$builder ) {
		$builder['vars']['user_wpzero'] = 'user-wpzero';
	}

	/**
	 * Called after building form options on pages inheriting from
	 * ChangesListSpecialPage (in core: RecentChanges, RecentChangesLinked
	 * and Watchlist).
	 *
	 * @param ChangesListSpecialPage $special Special page
	 */
	public static function onChangesListSpecialPageStructuredFilters( $special ) {
		// For volume/capacity reasons, only log this for logged-in users
		if ( $special->getUser()->isAnon() ) {
			return;
		}

		$logData = [
			'pagename' => $special->getName(),
			'enhancedFiltersEnabled' => (bool)$special->getUser()->getOption( 'rcenhancedfilters' ),
			'userId' => $special->getUser()->getId(),
		];

		$knownFilters = [
			'hideminor' => 'bool',
			'hidemajor' => 'bool',
			'hidebots' => 'bool',
			'hidehumans' => 'bool',
			'hideanons' => 'bool',
			'hidepatrolled' => 'bool',
			'hideunpatrolled' => 'bool',
			'hidemyself' => 'bool',
			'hidebyothers' => 'bool',
			'hideliu' => 'bool',
			'hidecategorization' => 'bool',
			'hidepageedits' => 'bool',
			'hidenewpages' => 'bool',
			'hidelog' => 'bool',
			'invert' => 'bool',
			'associated' => 'bool',
			'namespace' => 'integer',
			'tagfilter' => 'string',
			'userExpLevel' => 'string',

			// Extension:Wikidata
			'hideWikibase' => 'bool',

			// Extension:FlaggedRevs
			'hideReviewed' => 'bool',

			// Extension:ORES
			'hidenondamaging' => 'bool',
			'damaging' => 'string',
			'goodfaith' => 'string',
		];

		$webParams = $special->getRequest()->getQueryValues();
		foreach ( $webParams as $param => $value ) {
			if ( array_key_exists( $param, $knownFilters ) && $value !== '' && $value !== null ) {
				if ( $knownFilters[ $param ] === 'bool' ) {
					$logData[ $param ] = (bool)$value;
				} elseif ( $knownFilters[ $param ] === 'integer' ) {
					$logData[ $param ] = (int)$value;
				} else {
					$logData[ $param ] = (string)$value;
				}
			}
		}

		// Log the existing filters
		EventLogging::logEvent(
			'ChangesListFilters',
			16837986,
			$logData
		);
	}

	public static function onMakeGlobalVariablesScript( array &$vars, OutputPage $out ) {
		global $wgWMESearchRelevancePages;
		if ( $vars['wgAction'] === 'view' ) {
			$articleId = $out->getTitle()->getArticleID();
			if ( isset( $wgWMESearchRelevancePages[$articleId] ) ) {
				$vars['wgWMESearchRelevancePages'] = $wgWMESearchRelevancePages[$articleId];
			}
		}
		return true;
	}

	/**
	 * WMDE runs banner campaigns with a GuidedTour to encourage users to create an account and edit.
	 * This could one day be factored out into its own extension.
	 *
	 * The series of banner campaigns can be seen on Phabricator:
	 * https://phabricator.wikimedia.org/project/subprojects/2821/
	 *
	 * @author addshore on behalf of WMDE
	 *
	 * @param Title $title
	 * @param mixed $unused
	 * @param OutputPage $output
	 * @param User $user
	 * @param WebRequest $request
	 * @param MediaWiki $mediaWiki
	 */
	public static function onBeforeInitializeWMDECampaign(
		$title,
		$unused,
		$output,
		$user,
		$request,
		$mediaWiki
	) {
		// Only run for dewiki
		if ( wfWikiID() !== 'dewiki' ) {
			return;
		}

		/**
		 * Setup the prefix and cookie name for this campaign.
		 * Everything below this block is agnostic to which tour is being run.
		 */
		$campaignPrefix = 'wmde_abc2017';
		$campaignStartTimestamp = '20171004000000';
		$guidedTourName = 'einfuhrung';
		$guidedTourInitialStep = 'willkommen';
		$cookieName = 'wmdecampaign-' . $campaignPrefix;

		$hasCampaignGetValue = strstr( $request->getVal( 'campaign' ), $campaignPrefix ) !== false;
		$hasCampaignCookie = $request->getCookie( $cookieName ) !== null;

		// Get the campaign name from either the URL params or cookie
		$campaign = 'NULL';
		if ( $hasCampaignGetValue ) {
			$campaign = $request->getVal( 'campaign' );
		}
		if ( $hasCampaignCookie ) {
			$campaign = $request->getCookie( $cookieName );
		}

		// Bail if this request has nothing to do with our campaign
		if ( $campaign === 'NULL' ) {
			return;
		}

		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();

		/**
		 * If an anon user clicks on the banner and doesn't yet have a session cookie then
		 * add a session cookie and count the click.
		 */
		if ( $user->isAnon() && $hasCampaignGetValue && !$hasCampaignCookie ) {
			$request->response()->setCookie( $cookieName, $campaign, null );
			$stats->increment( "wmde.campaign.$campaign.banner.click" );
			wfDebugLog( 'WMDE', "$campaign - 1 - Banner click by anon user without cookie" );
		}

		/**
		 * If an anon user with the cookie, views the create account page without a campaign
		 * value set then inject it into the WebRequest object.
		 */
		if (
			$user->isAnon() &&
			$hasCampaignCookie &&
			$title->isSpecial( 'CreateAccount' ) &&
			!$hasCampaignGetValue
		) {
			$request->setVal( 'campaign', $campaign );
			wfDebugLog( 'WMDE', "$campaign - 2 - Inject campaign value on CreateAccount" );
		}

		/**
		 * If a registered user appears with the campaign cookie (thus had clicked the banner)
		 * and a main namespace page is being viewed, decide to show the tour or not based on
		 * dumb maths and if the user has registered since the start of the campaign.
		 */
		if (
			!$user->isAnon() &&
			$hasCampaignCookie &&
			$title->getNamespace() === NS_MAIN &&
			$mediaWiki->getAction() === 'view'
		) {
			if ( $user->getRegistration() > $campaignStartTimestamp ) {
				GuidedTourLauncher::launchTourByCookie( urlencode( $guidedTourName ), $guidedTourInitialStep );
				$stats->increment( "wmde.campaign.$campaign.tour.trigger" );
				wfDebugLog( 'WMDE', "$campaign - 3 - GuidedTour for user: " . $user->getId() );
			} else {
				$stats->increment( "wmde.campaign.$campaign.process.tooOld" );
				wfDebugLog( 'WMDE', "$campaign - 3.e - User older than the campaign." );
			}
			$request->response()->clearCookie( $cookieName );
		}
	}

}
