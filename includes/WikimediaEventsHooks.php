<?php

namespace WikimediaEvents;

use ActorMigration;
use DeferredUpdates;
use DerivativeContext;
use EditPage;
use EventLogging;
use ExtensionRegistry;
use Hooks;
use IContextSource;
use MediaWiki;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use MobileContext;
use OutputPage;
use RecentChange;
use RequestContext;
use ResourceLoader;
use SearchResultSet;
use Skin;
use Title;
use User;
use WebRequest;
use WikiPage;

/**
 * Hooks used for Wikimedia-related logging
 *
 * @author Ori Livneh <ori@wikimedia.org>
 * @author Matthew Flaschen <mflaschen@wikimedia.org>
 * @author Benny Situ <bsitu@wikimedia.org>
 */
class WikimediaEventsHooks {

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
		global $wgWMEUnderstandingFirstDay;

		if ( $wgWMEUnderstandingFirstDay ) {
			PageViews::deferredLog();
		}

		$out->addModules( 'ext.wikimediaEvents' );

		if ( ExtensionRegistry::getInstance()->isLoaded( 'WikibaseRepository' ) ) {
			// If we are in Wikibase Repo, load Wikibase module
			$out->addModules( 'ext.wikimediaEvents.wikibase' );
		}
	}

	/**
	 * UserLogout hook handler.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserLogout
	 *
	 * @param User $user
	 * @return bool
	 */
	public static function onUserLogout( User $user ) {
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

		if ( $out->getUser()->isRegistered() ) {
			$headerItems['loggedIn'] = 1;
		}
	}

	/**
	 * Log server-side event on successful page edit.
	 *
	 * Imported from EventLogging extension
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $userIdentity
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
	 */
	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $userIdentity,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	) {
		if ( PHP_SAPI === 'cli' ) {
			return; // ignore maintenance scripts
		}

		$title = $wikiPage->getTitle();

		$request = RequestContext::getMain()->getRequest();
		$services = MediaWikiServices::getInstance();
		$nsInfo = $services->getNamespaceInfo();
		$stats = $services->getStatsdDataFactory();
		$permMgr = $services->getPermissionManager();

		$user = User::newFromIdentity( $userIdentity );
		$content = $wikiPage->getContent();

		if (
			$user->isBot() ||
			( $request->getCheck( 'bot' ) && $permMgr->userHasRight( $user, 'bot' ) )
		) {
			$accType = 'bot'; // registered bot or script acting on behalf of a user
		} elseif ( $request->getCheck( 'maxlag' ) ) {
			$accType = 'throttled'; // probably an unregistered bot
		} else {
			$accType = 'normal';
		}

		if ( in_array( $content->getModel(), [ 'wikibase-item', 'wikibase-property' ] ) ) {
			$nsType = 'entity';
		} elseif ( $nsInfo->isContent( $title->getNamespace() ) ) {
			$nsType = 'content';
		} elseif ( $nsInfo->isTalk( $title->getNamespace() ) ) {
			$nsType = 'talk';
		} else {
			$nsType = 'meta';
		}

		if ( defined( 'MW_API' ) ) {
			$entry = 'api';
		} elseif ( defined( 'MEDIAWIKI_JOB_RUNNER' ) ) {
			$entry = 'job';
		} else {
			$entry = 'index';
		}

		// Null edits are both slow (due to user name mismatch reparses) and are
		// not the focus of this benchmark, which is about actual edits to pages
		$edit = $editResult->isNullEdit() ? 'nullEdit' : 'edit';

		$size = $content->getSize();

		DeferredUpdates::addCallableUpdate(
			function () use ( $stats, $size, $nsType, $accType, $entry, $edit ) {
				$timing = RequestContext::getMain()->getTiming();
				$measure = $timing->measure(
					'editResponseTime', 'requestStart', 'requestShutdown' );
				if ( $measure === false ) {
					return;
				}

				$timeMs = $measure['duration'] * 1000;
				$stats->timing( "timing.{$edit}ResponseTime", $timeMs );
				$stats->timing( "timing.{$edit}ResponseTime.page.$nsType", $timeMs );
				$stats->timing( "timing.{$edit}ResponseTime.user.$accType", $timeMs );
				$stats->timing( "timing.{$edit}ResponseTime.entry.$entry", $timeMs );
				if ( $edit === 'edit' ) {
					$msPerKb = $timeMs / ( max( $size, 1 ) / 1e3 ); // T224686
					$stats->timing( "timing.editResponseTimePerKB.page.$nsType", $msPerKb );
					$stats->timing( "timing.editResponseTimePerKB.user.$accType", $msPerKb );
					$stats->timing( "timing.editResponseTimePerKB.entry.$entry", $msPerKb );
				}
			}
		);
	}

	/**
	 * Log and update statistics whenever an editor reaches the active editor
	 * threshold for this month.
	 *
	 * @see https://meta.wikimedia.org/wiki/Schema:EditorActivation
	 * @see https://www.mediawiki.org/wiki/Analytics/Metric_definitions#Active_editor
	 *
	 * @param RevisionRecord $revRecord
	 */
	public static function onRevisionRecordInserted( RevisionRecord $revRecord ) {
		// Only mainspace edits qualify
		if ( !$revRecord->getPageAsLinkTarget()->inNamespace( NS_MAIN ) ) {
			return;
		}

		$userIdentity = $revRecord->getUser( RevisionRecord::RAW );
		$user = User::newFromIdentity( $userIdentity );

		// Anonymous users and bots don't count (sorry!)
		if ( $user->isAnon() || $user->isAllowed( 'bot' ) ) {
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
	 * Logs edit conflicts with the EditConflict schema.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/EditPageBeforeConflictDiff
	 * @see https://meta.wikimedia.org/wiki/Schema:EditConflict
	 * @param EditPage $editPage
	 * @param OutputPage &$out
	 * @return bool true in all cases
	 */
	public static function onEditPageBeforeConflictDiff( EditPage $editPage, &$out ) {
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

	/**
	 * Set static (not request-specific) JS configuration variables
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ResourceLoaderGetConfigVars
	 * @param array &$vars Array of variables to be added into the output of the startup module
	 * @param string $skinName Current skin name to restrict config variables to a certain skin
	 */
	public static function onResourceLoaderGetConfigVars( &$vars, $skinName ) {
		global $wgWMEClientErrorIntakeURL, $wgWMEStatsdBaseUri,
			$wgWMEDesktopWebUIActionsTracking, $wgWMESessionTick,
			$wgWMESchemaEditAttemptStepSamplingRate, $wgWMEMobileWebUIActionsTracking,
			$wgWMEWikidataCompletionSearchClicks,
			$wgWMEInukaPageViewEnabled, $wgWMEInukaPageViewCookiesDomain,
			$wgWMEInukaPageViewSamplingRatePerOs;

		// WARNING: Do not add new entries here.
		//
		// This legacy mechanism is suboptimial for performance and code quality.
		//
		// For new variables you need to access in a JS module, use a virtual 'config.json' file.
		// See <https://www.mediawiki.org/wiki/ResourceLoader/Package_modules>
		//
		$vars['wgWMEClientErrorIntakeURL'] = $wgWMEClientErrorIntakeURL;
		$vars['wgWMEStatsdBaseUri'] = $wgWMEStatsdBaseUri;
		$vars['wgWMESchemaEditAttemptStepSamplingRate'] = $wgWMESchemaEditAttemptStepSamplingRate;
		$vars['wgWMEWikidataCompletionSearchClicks'] = $wgWMEWikidataCompletionSearchClicks;
		$vars['wgWMESessionTick'] = $wgWMESessionTick;
		if ( $skinName === 'minerva' ) {
			$vars['wgWMEMobileWebUIActionsTracking'] = $wgWMEMobileWebUIActionsTracking;
			$vars['wgWMEInukaPageViewEnabled'] = $wgWMEInukaPageViewEnabled;
			$vars['wgWMEInukaPageViewCookiesDomain'] = $wgWMEInukaPageViewCookiesDomain;
			$vars['wgWMEInukaPageViewSamplingRatePerOs'] = $wgWMEInukaPageViewSamplingRatePerOs;
		} else {
			$vars['wgWMEDesktopWebUIActionsTracking'] = $wgWMEDesktopWebUIActionsTracking;
		}
	}

	/**
	 * Register change tags.
	 *
	 * @param array &$tags
	 * @return bool
	 */
	public static function onListDefinedTags( &$tags ) {
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

	/**
	 * @param string $term
	 * @param Title $title
	 * @param string|null &$url
	 * @return true
	 */
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

	/**
	 * @param ResourceLoader $resourceLoader
	 */
	public static function onResourceLoaderRegisterModules( ResourceLoader $resourceLoader ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' ) ) {
			return;
		}

		$dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;

		$resourceLoader->register( "ext.wikimediaEvents.visualEditor", [
			'localBasePath' => $dir . 'modules',
			'remoteExtPath' => 'WikimediaEvents/modules',
			"scripts" => "ext.wikimediaEvents.visualEditor/campaigns.js",
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
	 * @param array &$vars
	 * @param OutputPage $out
	 * @return true
	 */
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

	/**
	 * @param IContextSource $context
	 * @return bool
	 */
	public static function shouldSchemaEditAttemptStepOversample( IContextSource $context ) {
		global $wgWMEUnderstandingFirstDay;
		// Conditions under which Schema:EditAttemptStep should oversample (always log)

		// Oversample when UnderstandingFirstDay is enabled and the user is in the UFD cohort
		$pageViews = new PageViews( $context );
		$userInCohort = $wgWMEUnderstandingFirstDay && $pageViews->userIsInCohort();

		// The editingStatsOversample request parameter can trigger oversampling
		$fromRequest = $context->getRequest()->getBool( 'editingStatsOversample' );

		$shouldOversample = $userInCohort || $fromRequest;
		Hooks::run(
			'WikimediaEventsShouldSchemaEditAttemptStepOversample',
			[ $context, &$shouldOversample ]
		);

		return $shouldOversample;
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
	 * Log user's selection on SpecialMute form via EventLogging
	 *
	 * @param array $data
	 */
	public static function onSpecialMuteSubmit( $data ) {
		$event = [];
		if ( isset( $data['email-blacklist'] ) ) {
			$event['emailsBefore'] = $data['email-blacklist']['before'];
			$event['emailsAfter'] = $data['email-blacklist']['after'];
		}

		if ( isset( $data['echo-notifications-blacklist'] ) ) {
			$event['notificationsBefore'] = $data['echo-notifications-blacklist']['before'];
			$event['notificationsAfter'] = $data['echo-notifications-blacklist']['after'];
		}

		DeferredUpdates::addCallableUpdate(
			function () use ( $event ) {
				// NOTE!  SpecialMuteSubmit has been migrated to Event Platform,
				// and is no longer using the metawiki based schema.  This revision_id
				// will be overridden by the value of the EventLogging Schemas extension attribute
				// set in extension.json.
				EventLogging::logEvent( 'SpecialMuteSubmit', 19265572, $event );
			}
		);
	}
}
