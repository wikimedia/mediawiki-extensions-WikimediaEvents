<?php

use MediaWiki\MediaWikiServices;
use WikimediaEvents\PageViews;

/**
 * Hooks used for Wikimedia-related logging
 *
 * @author Ori Livneh <ori@wikimedia.org>
 * @author Matthew Flaschen <mflaschen@wikimedia.org>
 * @author Benny Situ <bsitu@wikimedia.org>
 */
class WikimediaEventsHooks {

	/* @var int UNIX timestamp representing the start of the PHP7 editor productivity study. */
	const PHP7_START = 1548028800;  // Mon, 21 Jan 2019 00:00:00 UTC

	/**
	 * Check if a user is in the PHP7 study
	 *
	 * @param User $user
	 * @return bool
	 */
	public static function isUserInPHP7Study( User $user ) {
		$ts = $user->getRegistration();
		return ( $ts > 0 ) && ( wfTimestampOrNull( TS_UNIX, $ts ) >= self::PHP7_START );
	}

	/**
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( &$out, &$skin ) {
		global $wgWMEUnderstandingFirstDay;

		if ( $wgWMEUnderstandingFirstDay ) {
			PageViews::deferredLog();
		}

		$out->addModules( 'ext.wikimediaEvents' );

		if ( $out->getUser()->isLoggedIn() ) {
			$out->addModules( 'ext.wikimediaEvents.loggedin' );
		}

		if ( defined( 'WB_VERSION' ) ) {
			// If we are in Wikibase Repo, load Wikibase module
			$out->addModules( 'ext.wikimediaEvents.wikibase' );
		}

		$user = $out->getUser();
		if ( $user->isAnon() ) {
			return;
		}

		$req = $out->getRequest();
		$currentCookieValue = $req->getCookie( 'php7', '' );
		if (
			( self::isUserInPHP7Study( $user ) && $user->getId() % 2 === 0 ) ||
			( ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' ) &&
			BetaFeatures::isFeatureEnabled( $user, 'php7' ) )
		) {
			if ( $currentCookieValue !== 'true' ) {
				// Set the cookie.
				$req->response()->setCookie( 'php7', 'true', null, [ 'prefix' => '' ] );
			}
		} elseif ( $currentCookieValue !== null ) {
			// Clear the cookie.
			$req->response()->setCookie( 'php7', '', - 86400, [ 'prefix' => '' ] );
		}
	}

	/**
	 * UserLogout hook handler.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserLogout
	 *
	 * @param User &$user
	 * @return bool
	 */
	public static function onUserLogout( User &$user ) {
		global $wgWMEUnderstandingFirstDay;
		if ( $wgWMEUnderstandingFirstDay ) {
			PageViews::deferredLog( $user->getId() );
		}
		return true;
	}

	/**
	 * BeforePageRedirect hook handler.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageRedirect
	 *
	 * @param OutputPage $out
	 * @param string &$redirect URL string, modifiable
	 * @param string &$code HTTP code, modifiable
	 * @return bool
	 */
	public static function onBeforePageRedirect( $out, &$redirect, &$code ) {
		global $wgWMEUnderstandingFirstDay;
		if ( $wgWMEUnderstandingFirstDay ) {
			PageViews::deferredLog();
		}
		return true;
	}

	/**
	 * LocalUserCreated hook handler.
	 *
	 * @param User $user
	 * @param bool $autocreated
	 */
	public static function onLocalUserCreated( User $user, $autocreated ) {
		global $wgWMEUnderstandingFirstDay;
		if ( $wgWMEUnderstandingFirstDay && !$autocreated ) {
			$context = new DerivativeContext( RequestContext::getMain() );
			$context->setUser( $user );
			$pageViews = new PageViews( $context );
			// We don't need to check the cohort, since we know the user is not autocreated
			// and the account was just created.
			$pageViews->setUserHashingSalt();
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
				list( $name, /* $subpage */ ) = MediaWikiServices::getInstance()->getSpecialPageFactory()
					->resolveAlias( $title->getDBkey() );

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
	 * @param WikiPage $wikiPage
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
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 */
	public static function onPageContentSaveComplete(
		WikiPage $wikiPage, $user, $content, $summary,
		$isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId
	) {
		if ( !$revision ) {
			return;
		}

		$title = $wikiPage->getTitle();
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

			// Null edits are both slow (due to user name mismatch reparses) and are
			// not the focus of this benchmark, which is about actual edits to pages
			$edit = $status->hasMessage( 'edit-no-change' ) ? 'nullEdit' : 'edit';

			DeferredUpdates::addCallableUpdate(
				function () use ( $stats, $size, $nsType, $accType, $entry, $edit ) {
					$timing = RequestContext::getMain()->getTiming();
					$measure = $timing->measure(
						'editResponseTime', 'requestStart', 'requestShutdown' );
					if ( $measure !== false ) {
						$timeMs = $measure['duration'] * 1000;
						$stats->timing( "timing.{$edit}ResponseTime", $timeMs );
						$stats->timing( "timing.{$edit}ResponseTime.page.$nsType", $timeMs );
						$stats->timing( "timing.{$edit}ResponseTime.user.$accType", $timeMs );
						$stats->timing( "timing.{$edit}ResponseTime.entry.$entry", $timeMs );
						if ( $edit === 'edit' ) {
							$stats->gauge( "edit.newContentSize", $size );
						}
					}
				}
			);
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
			$revWhere = ActorMigration::newMigration()->getWhere( $db, 'rev_user', $user );
			$since = $db->addQuotes( $db->timestamp( date( 'Ym' ) . '00000000' ) );
			$numMainspaceEditsThisMonth = 0;

			foreach ( $revWhere['orconds'] as $key => $cond ) {
				$tsField = $key === 'actor' ? 'revactor_timestamp' : 'rev_timestamp';
				$numMainspaceEditsThisMonth += $db->selectRowCount(
					[ 'revision', 'page' ] + $revWhere['tables'],
					'1',
					[
						$cond,
						$tsField . ' >= ' . $since,
						'page_namespace'   => NS_MAIN,
					],
					__FILE__ . ':' . __LINE__,
					[ 'LIMIT' => 6 - $numMainspaceEditsThisMonth ],
					[ 'page' => [ 'INNER JOIN', 'rev_page = page_id' ] ] + $revWhere['joins']
				);
				if ( $numMainspaceEditsThisMonth >= 6 ) {
					break;
				}
			}

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
	 * Helper method to verify that hook is triggered on special page
	 * @param OutputPage $out Output page
	 * @return bool Returns true, if request is sent to one of $allowedPages special page
	 */
	private static function isKnownSettingsPage( OutputPage $out ) {
		$allowedPages = [ 'Preferences', 'MobileOptions' ];
		$title = $out->getTitle();
		if ( $title === null ) {
			return false;
		}
		foreach ( $allowedPages as $page ) {
			if ( $title->isSpecial( $page ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Handler for UserSaveOptions hook.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserSaveOptions
	 * @param User $user user whose options are being saved
	 * @param array &$options Options being saved
	 * @return bool true in all cases
	 */
	public static function onUserSaveOptions( $user, &$options ) {
		// Modified version of original method from the Echo extension
		$out = RequestContext::getMain()->getOutput();
		// Capture user options saved via Special:Preferences, Special:MobileOptions or ApiOptions

		// TODO (mattflaschen, 2013-06-13): Ideally this would be done more cleanly without
		// looking explicitly at page names and URL parameters.
		// Maybe a userInitiated flag passed to saveSettings would work.
		if ( self::isKnownSettingsPage( $out )
			|| ( defined( 'MW_API' ) && $out->getRequest()->getVal( 'action' ) === 'options' )
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
	 * @param EditPage &$editPage
	 * @param OutputPage &$out
	 * @return bool true in all cases
	 */
	public static function onEditPageBeforeConflictDiff( &$editPage, &$out ) {
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
			$wgWMEPrintEnabled, $wgWMECitationUsagePopulationSize,
			$wgWMECitationUsagePageLoadPopulationSize,
			$wgWMESchemaEditAttemptStepSamplingRate,
			$wgWMEWikidataCompletionSearchClicks;

		$vars['wgWMEStatsdBaseUri'] = $wgWMEStatsdBaseUri;
		$vars['wgWMEReadingDepthSamplingRate'] = $wgWMEReadingDepthSamplingRate;
		$vars['wgWMEReadingDepthEnabled'] = $wgWMEReadingDepthEnabled;
		$vars['wgWMEPrintSamplingRate'] = $wgWMEPrintSamplingRate;
		$vars['wgWMEPrintEnabled'] = $wgWMEPrintEnabled;
		$vars['wgWMECitationUsagePopulationSize'] = $wgWMECitationUsagePopulationSize;
		$vars['wgWMECitationUsagePageLoadPopulationSize'] = $wgWMECitationUsagePageLoadPopulationSize;
		$vars['wgWMESchemaEditAttemptStepSamplingRate'] = $wgWMESchemaEditAttemptStepSamplingRate;
		$vars['wgWMEWikidataCompletionSearchClicks'] = $wgWMEWikidataCompletionSearchClicks;
	}

	/**
	 * Register change tags.
	 *
	 * @param array &$tags
	 * @return bool
	 */
	public static function onListDefinedTags( &$tags ) {
		$tags[] = 'HHVM';
		$tags[] = 'php7';
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
	public static function onRecentChangeSaveCrossWikiUpload( RecentChange $recentChange ) {
		if ( !defined( 'MW_API' ) || wfWikiID() !== 'commonswiki' ) {
			return true;
		}

		if (
			$recentChange->getAttribute( 'rc_log_type' ) !== 'upload' ||
			$recentChange->getAttribute( 'rc_log_action' ) !== 'upload'
		) {
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

	/**
	 * Add a change tag 'campaign-...' to edits made via edit campaigns (identified by an URL
	 * parameter passed to the API via VisualEditor). (T209132)
	 *
	 * @param RecentChange $recentChange
	 * @return bool
	 */
	public static function onRecentChangeSaveEditCampaign( RecentChange $recentChange ) {
		global $wgWMEEditCampaigns;

		if ( !defined( 'MW_API' ) ) {
			return true;
		}

		$request = RequestContext::getMain()->getRequest();
		$campaign = $request->getVal( 'campaign' );
		if ( !in_array( $campaign, $wgWMEEditCampaigns ) ) {
			return true;
		}

		$tags = [ "campaign-$campaign" ];
		$recentChange->addTags( $tags );

		return true;
	}

	public static function onResourceLoaderRegisterModules( ResourceLoader $resourceLoader ) {
		if ( !class_exists( 'VisualEditorHooks' ) ) {
			return;
		}

		$dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;

		$resourceLoader->register( "ext.wikimediaEvents.visualEditor", [
			'localBasePath' => $dir . 'modules',
			'remoteExtPath' => 'WikimediaEvents/modules',
			"scripts" => "ve-wme/campaigns.js",
			"dependencies" => "ext.visualEditor.targetLoader",
			"targets" => [ "desktop", "mobile" ],
		] );
	}

	public static function onArticleViewHeader() {
		DeferredUpdates::addCallableUpdate( function () {
			$context = RequestContext::getMain();
			$timing = $context->getTiming();
			if ( ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' )
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
	 */
	public static function onAbuseFilterBuilder( &$builder ) {
		$builder['vars']['user_wpzero'] = 'user-wpzero';
	}

	public static function onMakeGlobalVariablesScript( array &$vars, OutputPage $out ) {
		global $wgWMESearchRelevancePages;
		if ( $vars['wgAction'] === 'view' ) {
			$articleId = $out->getTitle()->getArticleID();
			if ( isset( $wgWMESearchRelevancePages[$articleId] ) ) {
				$vars['wgWMESearchRelevancePages'] = $wgWMESearchRelevancePages[$articleId];
			}
		}

		$vars['wgWMESchemaEditAttemptStepOversample'] =
			static::shouldSchemaEditAttemptStepOversample( $out->getContext() );
		return true;
	}

	public static function shouldSchemaEditAttemptStepOversample( IContextSource $context ) {
		global $wgWMEUnderstandingFirstDay;
		// Conditions under which Schema:EditAttemptStep should oversample (always log)

		// Oversample when UnderstandingFirstDay is enabled and the user is in the UFD cohort
		$pageViews = new PageViews( $context );
		return $wgWMEUnderstandingFirstDay && $pageViews->userIsInCohort();
	}

	/**
	 * WMDE runs banner campaigns to encourage users to create an account and edit.
	 * The tracking already implemented in the Campaigns extension doesn't quite cover the WMDE
	 * usecase as they have a wikipage landing page before the user progresses to registration.
	 * This could one day be factored out into its own extension or part of Campaigns.
	 *
	 * The task for adding this functionality to the Campaigns extension can be found below:
	 * https://phabricator.wikimedia.org/T174939
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
		 * Setup the campaign prefix.
		 * Everything below this block is agnostic to which tour is being run.
		 */
		$campaignPrefix = 'WMDE_';

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
		if ( $hasCampaignGetValue && !$hasCampaignCookie && $user->isAnon() ) {
			$request->response()->setCookie( $cookieName, $campaign, null );
			$stats->increment( "wmde.campaign.$campaign.banner.click" );
			wfDebugLog( 'WMDE', "$campaign - 1 - Banner click by anon user without cookie" );
		}

		/**
		 * If an anon user with the cookie, views the create account page without a campaign
		 * value set then inject it into the WebRequest object.
		 */
		if (
			$hasCampaignCookie &&
			!$hasCampaignGetValue &&
			$user->isAnon() &&
			$title->isSpecial( 'CreateAccount' )
		) {
			$request->setVal( 'campaign', $campaign );
			wfDebugLog( 'WMDE', "$campaign - 2 - Inject campaign value on CreateAccount" );
		}
	}

	/**
	 * Tag changes made via PHP7
	 *
	 * @param RecentChange $rc
	 */
	public static function onRecentChangeSavePHP7( RecentChange $rc ) {
		if ( PHP_VERSION_ID > 70000 && !wfIsHHVM() ) {
			$rc->addTags( 'php7' );
		}
	}

	/**
	 * Register PHP7 as a toggleable beta feature.
	 *
	 * @param User $user
	 * @param array &$prefs
	 */
	public static function onGetBetaFeaturePreferences( User $user, array &$prefs ) {
		if ( !self::isUserInPHP7Study( $user ) ) {
			$iconpath = MediaWikiServices::getInstance()->getMainConfig()->get( 'ExtensionAssetsPath' )
				. '/WikimediaEvents/resources';

			$prefs['php7'] = [
				'label-message'   => 'php7-label',
				'desc-message'    => 'php7-desc',
				'screenshot'      => [
					'ltr' => "$iconpath/betafeatures-php7-ltr.svg",
					'rtl' => "$iconpath/betafeatures-php7-rtl.svg",
				],
				'info-link'       => '//www.mediawiki.org/wiki/Special:MyLanguage/Beta_Features/PHP7',
				'discussion-link' => '//www.mediawiki.org/wiki/Talk:Beta_Features/PHP7',
			];
		}
	}
}
