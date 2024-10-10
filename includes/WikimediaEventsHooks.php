<?php

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace WikimediaEvents;

use Article;
use ExtensionRegistry;
use ISearchResultSet;
use MediaWiki\Actions\ActionEntryPoint;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\Hook\AuthManagerLoginAuthenticateAuditHook;
use MediaWiki\ChangeTags\Hook\ChangeTagsListActiveHook;
use MediaWiki\ChangeTags\Hook\ListDefinedTagsHook;
use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Hook\SpecialSearchGoResultHook;
use MediaWiki\Hook\SpecialSearchResultsHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\Hook\MakeGlobalVariablesScriptHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\ArticleViewHeaderHook;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Request\WebRequest;
use MediaWiki\ResourceLoader as RL;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderRegisterModulesHook;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use MobileContext;
use RecentChange;
use Skin;
use WikimediaEvents\Hooks\HookRunner;
use WikiPage;

/**
 * Hooks used for Wikimedia-related logging
 *
 * @author Ori Livneh <ori@wikimedia.org>
 * @author Matthew Flaschen <mflaschen@wikimedia.org>
 * @author Benny Situ <bsitu@wikimedia.org>
 */
class WikimediaEventsHooks implements
	BeforeInitializeHook,
	BeforePageDisplayHook,
	PageSaveCompleteHook,
	ArticleViewHeaderHook,
	ListDefinedTagsHook,
	ChangeTagsListActiveHook,
	SpecialSearchGoResultHook,
	SpecialSearchResultsHook,
	RecentChange_saveHook,
	ResourceLoaderRegisterModulesHook,
	MakeGlobalVariablesScriptHook,
	AuthManagerLoginAuthenticateAuditHook

{
	private AccountCreationLogger $accountCreationLogger;
	private Config $config;

	public function __construct(
		AccountCreationLogger $accountCreationLogger,
		Config $config
	) {
		$this->accountCreationLogger = $accountCreationLogger;
		$this->config = $config;
	}

	/**
	 * Handles the AuthManagerLoginAuthenticateAudit hook.
	 *
	 * Invoked after the authentication process of a user login attempt.
	 * Logs the event type (success/failure), the performer of the login attempt,
	 * and the authentication response object.
	 *
	 * @param AuthenticationResponse $response The response from the authentication process,
	 *                                         indicating whether the login attempt passed or failed.
	 * @param User|null $user The User object for the attempted login.
	 * @param string $username The username used in the login attempt.
	 * @param array $extraData Additional data associated with the login attempt. Includes
	 *                         a 'performer' key representing the User object attempting the login.
	 */
	public function onAuthManagerLoginAuthenticateAudit( $response, $user, $username, $extraData ) {
		$eventType = $response->status === AuthenticationResponse::PASS ? 'success' : 'failure';
		$this->accountCreationLogger->logLoginEvent( $eventType, $extraData[ 'performer' ]->getUser(), $response );
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModules( 'ext.wikimediaEvents' );

		if ( ExtensionRegistry::getInstance()->isLoaded( 'WikibaseRepository' ) ) {
			// If we are in Wikibase Repo, load Wikibase module
			$out->addModules( 'ext.wikimediaEvents.wikibase' );
		}
		$this->accountCreationLogger->logPageImpression(
			$out->getTitle(),
			$out->getRequest()->getSession()->getUser()
		);
	}

	/**
	 * On XAnalyticsSetHeader
	 *
	 * When adding new headers here please update the docs:
	 * https://wikitech.wikimedia.org/wiki/X-Analytics
	 *
	 * Insert a 'page_id' key with the page ID as value (if the request is for a page with a pageid)
	 * Insert a 'ns' key with the namespace ID as value (if the request is for a valid title)
	 * Insert a 'special' key with the resolved name of the special page (if the request is for a
	 * special page).  If the name does not resolve, special is set to 'unknown' (see T304362).
	 *
	 * Add a 'loggedIn' key with the value of 1 if the user is logged in
	 * @param OutputPage $out
	 * @param array &$headerItems
	 */
	public static function onXAnalyticsSetHeader( OutputPage $out, array &$headerItems ): void {
		$title = $out->getTitle();
		if ( $title !== null && !defined( 'MW_API' ) ) {
			$pageId = $title->getArticleID();
			$headerItems['ns'] = $title->getNamespace();
			if ( is_int( $pageId ) && $pageId > 0 ) {
				$headerItems['page_id'] = $pageId;
			}
			if ( $title->isSpecialPage() ) {
				[ $name, /* $subpage */ ] = MediaWikiServices::getInstance()->getSpecialPageFactory()
					->resolveAlias( $title->getDBkey() );

				$headerItems['special'] = $name ?? 'unknown';
			}
			$revId = $out->getRevisionId();
			// The revision ID will be positive for page and diff views, as well
			// as viewing old revisions.
			if ( $revId > 0 ) {
				$headerItems['rev_id'] = $revId;
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
	public function onPageSaveComplete(
		$wikiPage,
		$userIdentity,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		if ( PHP_SAPI === 'cli' && !defined( 'MW_PHPUNIT_TEST' ) ) {
			return; // ignore maintenance scripts
		}

		// Discard null edits from these metrics as they do not produce a
		// saved an edit to a page, and thus notably differ in code execution.
		// Note that null edits can still be relatively slow, as they do perform
		// reparses.
		if ( $editResult->isNullEdit() ) {
			return;
		}

		$title = $wikiPage->getTitle();

		$request = RequestContext::getMain()->getRequest();
		$services = MediaWikiServices::getInstance();
		$nsInfo = $services->getNamespaceInfo();
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
		} elseif ( $user->isTemp() ) {
			$accType = 'temp';
		} elseif ( $user->isAnon() ) {
			$accType = 'anon';
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

		if ( MW_ENTRY_POINT === 'index' ) {
			// non-AJAX submission from user interface
			// (for non-WMF this could also mean jobrunner, since jobs run post-send
			// from index.php by default)
			$entry = 'index';
		} elseif ( MW_ENTRY_POINT === 'api' || MW_ENTRY_POINT === 'rest' ) {
			$entry = 'api';
		} else {
			// jobrunner, maint/cli
			$entry = 'other';
		}

		$size = $content->getSize();

		DeferredUpdates::addCallableUpdate(
			static function () use ( $size, $nsType, $accType, $entry ) {
				$requestContext = RequestContext::getMain();
				$reqCtxTiming = $requestContext->getTiming();
				// It's possible to use Minerva on a desktop device, or Vector on a mobile
				// device, but defining Minerva usage as a proxy for "is mobile" is good enough
				// for monitoring.
				$isMobile = $requestContext->getSkin()->getSkinName() === 'minerva' ? '1' : '0';

				// Would make sense to gate the following lines behind $entry === 'api', but
				// the entrypoint is hardcoded via MW_ENTRY_POINT, which can't be overridden in tests.

				// Check if the request was Android/iOS/Commons app.
				$userAgent = $requestContext->getRequest()->getHeader( "User-agent" );
				$isWikipediaApp = strpos( $userAgent, "WikipediaApp/" ) === 0;
				$isCommonsApp = strpos( $userAgent, "Commons/" ) === 0;
				if ( $isWikipediaApp || $isCommonsApp ) {
					// Consider apps to be "mobile" for instrumentation purposes
					$isMobile = '1';
				}
				if ( $isCommonsApp ) {
					$platform = 'commons';
				} elseif ( strpos( $userAgent, "Android" ) > 0 ) {
					$platform = 'android';
				} elseif ( strpos( $userAgent, "iOS" ) > 0 || strpos( $userAgent, "iPadOS" ) > 0 ) {
					$platform = 'ios';
				} elseif ( $entry === 'index' ) {
					$platform = 'web';
				} else {
					$platform = 'unknown';
				}

				$measure = $reqCtxTiming->measure(
					'editResponseTime', 'requestStart', 'requestShutdown' );
				if ( $measure === false ) {
					return;
				}

				$timeMs = $measure['duration'] * 1000;

				$statsFactory = MediaWikiServices::getInstance()->getStatsFactory()
					->withComponent( 'WikimediaEvents' );

				$statsFactory->getTiming( 'editResponseTime_seconds' )
					->setLabel( 'page', $nsType )
					->setLabel( 'user', $accType )
					->setLabel( 'is_mobile', $isMobile )
					->setLabel( 'platform', $platform )
					->setLabel( 'entry', $entry )
					->copyToStatsdAt( [
						"timing.editResponseTime",
						"timing.editResponseTime.page.$nsType",
						"timing.editResponseTime.user.$accType",
						"timing.editResponseTime.entry.$entry",
					] )->observe( $timeMs );

				$msPerKb = $timeMs / ( max( $size, 1 ) / 1e3 ); // T224686
				$statsFactory->getTiming( 'editResponseTimePerKB_seconds' )
					->setLabel( 'page', $nsType )
					->setLabel( 'user', $accType )
					->setLabel( 'platform', $platform )
					->setLabel( 'is_mobile', $isMobile )
					->setLabel( 'entry', $entry )
					->copyToStatsdAt( [
						"timing.editResponseTimePerKB.page.$nsType",
						"timing.editResponseTimePerKB.user.$accType",
						"timing.editResponseTimePerKB.entry.$entry",
					] )->observe( $msPerKb );
			}
		);
	}

	/**
	 * Callback for ext.wikimediaEvents virtual config.json file.
	 *
	 * @param RL\Context $context
	 * @param Config $config
	 * @return array
	 */
	public static function getModuleConfig( RL\Context $context, Config $config ) {
		$vars = [];
		$vars['clientErrorIntakeURL'] = $config->get( 'WMEClientErrorIntakeURL' );
		$vars['statsdBaseUri'] = $config->get( 'WMEStatsdBaseUri' );
		$vars['wikidataCompletionSearchClicks'] = $config->get( 'WMEWikidataCompletionSearchClicks' );
		$vars['sessionTick'] = $config->get( 'WMESessionTick' );
		$vars['readingDepthSamplingRate'] = $config->get( 'WMEReadingDepthSamplingRate' );
		$vars['newPHPSamplingRate'] = $config->get( 'WMENewPHPSamplingRate' );
		$vars['newPHPVersion'] = $config->get( 'WMENewPHPVersion' );
		$skin = $context->getSkin();
		if ( $skin === 'minerva' ) {
			$vars['mobileWebUIActionsTracking'] = $config->get( 'WMEMobileWebUIActionsTracking' );
		} elseif ( in_array( $skin, [ 'vector', 'vector-2022' ] ) ) {
			$vars['desktopWebUIActionsTracking'] = $config->get( 'WMEDesktopWebUIActionsTracking' );
			$vars['desktopWebUIActionsTrackingOversampleLoggedInUsers'] =
				$config->get( 'WMEDesktopWebUIActionsTrackingOversampleLoggedInUsers' );
			$vars['webUIScrollTrackingSamplingRate'] = $config->get( 'WMEWebUIScrollTrackingSamplingRate' );
			$vars['webUIScrollTrackingSamplingRateAnons'] = $config->get( 'WMEWebUIScrollTrackingSamplingRateAnons' );
			$vars['webUIScrollTrackingTimeToWaitBeforeScrollUp'] =
				$config->get( 'WMEWebUIScrollTrackingTimeToWaitBeforeScrollUp' );
		}

		// editAttemptStep.js
		$vars['WMESchemaEditAttemptStepSamplingRate'] =
			$config->get( 'WMESchemaEditAttemptStepSamplingRate' );
		$vars['WMESchemaVisualEditorFeatureUseSamplingRate'] =
			$config->get( 'WMESchemaVisualEditorFeatureUseSamplingRate' );
		$vars['DTSchemaEditAttemptStepSamplingRate'] =
			$config->get( 'DTSchemaEditAttemptStepSamplingRate' );
		$vars['DTSchemaEditAttemptStepOversample'] =
			$config->get( 'DTSchemaEditAttemptStepOversample' );
		$vars['MFSchemaEditAttemptStepOversample'] =
			$config->get( 'MFSchemaEditAttemptStepOversample' );

		return $vars;
	}

	/**
	 * Callback for dynamic source files, for conditional loading based on the current skin.
	 *
	 * @param RL\Context $context
	 * @param Config $config
	 * @param string $param callback param - corresponds to the file name to conditionally load
	 * @return RL\FilePath|string
	 */
	public static function getModuleFile( RL\Context $context, Config $config, $param ) {
		$skin = $context->getSkin();

		switch ( $skin ) {
			case 'vector':
			case 'vector-2022':
				switch ( $param ) {
					case 'clickTracking/desktop':
					case 'searchSatisfaction':
					case 'searchSli':
					case 'universalLanguageSelector':
					case 'webUIScroll':
						return new RL\FilePath( $param . '.js' );
					default:
						return '';
				}
			case 'minerva':
				switch ( $param ) {
					case 'clickTracking/mobile':
						return new RL\FilePath( $param . '.js' );
					default:
						return '';
				}
			default:
				switch ( $param ) {
					case 'searchSatisfaction':
					case 'searchSli':
						return new RL\FilePath( $param . '.js' );
					default:
						return '';
				}
		}
	}

	/**
	 * Register change tags.
	 *
	 * @param array &$tags
	 */
	public function onListDefinedTags( &$tags ): void {
		if ( WikiMap::getCurrentWikiId() === 'commonswiki' ) {
			$tags[] = 'cross-wiki-upload';
		}
	}

	/**
	 * Mark active change tags.
	 *
	 * @param array &$tags
	 */
	public function onChangeTagsListActive( &$tags ): void {
		$this->onListDefinedTags( $tags );
	}

	/**
	 * @param string $term
	 * @param Title $title
	 * @param string|null &$url
	 */
	public function onSpecialSearchGoResult( $term, $title, &$url ): void {
		$request = RequestContext::getMain()->getRequest();

		$wprov = $request->getRawVal( 'wprov' );
		if ( $wprov ) {
			$url = $title->getFullURL( [ 'wprov' => $wprov ] );
		}
	}

	/**
	 * The javascript that records search metrics needs to know if it is on a
	 * SERP or not. This ends up being non-trivial due to localization, so
	 * make it trivial by injecting a boolean value to check.
	 *
	 * @param string $term
	 * @param ?ISearchResultSet &$titleMatches
	 * @param ?ISearchResultSet &$textMatches
	 */
	public function onSpecialSearchResults( $term, &$titleMatches, &$textMatches ): void {
		$out = RequestContext::getMain()->getOutput();

		$out->addJsConfigVars( [
			'wgIsSearchResultPage' => true,
		] );
	}

	/**
	 * @param RecentChange $rc
	 */
	public function onRecentChange_save( $rc ): void {
		self::onRecentChangeSaveCrossWikiUpload( $rc );
		$this->onRecentChangeSaveEditCampaign( $rc );
	}

	/**
	 * Add a change tag 'cross-wiki-upload' to cross-wiki uploads to Commons, to track usage of the
	 * new feature. (Both to track adoption, and to let Commons editors review the uploads.) (T115328)
	 *
	 * @param RecentChange $rc
	 */
	public static function onRecentChangeSaveCrossWikiUpload( RecentChange $rc ): void {
		if ( !defined( 'MW_API' ) || WikiMap::getCurrentWikiId() !== 'commonswiki' ) {
			return;
		}

		if (
			$rc->getAttribute( 'rc_log_type' ) !== 'upload' ||
			$rc->getAttribute( 'rc_log_action' ) !== 'upload'
		) {
			return;
		}
		$request = RequestContext::getMain()->getRequest();
		if ( !$request->response()->getHeader( 'Access-Control-Allow-Origin' ) ) {
			return;
		}

		$tags = [ 'cross-wiki-upload' ];
		$rc->addTags( $tags );
	}

	/**
	 * Add a change tag 'campaign-...' to edits made via edit campaigns (identified by an URL
	 * parameter passed to the API via VisualEditor). (T209132)
	 *
	 * @param RecentChange $rc
	 */
	public function onRecentChangeSaveEditCampaign( RecentChange $rc ): void {
		if ( !defined( 'MW_API' ) ) {
			return;
		}

		$request = RequestContext::getMain()->getRequest();
		$campaign = $request->getRawVal( 'campaign' );
		if ( !in_array( $campaign, $this->config->get( 'WMEEditCampaigns' ) ) ) {
			return;
		}

		$tags = [ "campaign-$campaign" ];
		$rc->addTags( $tags );
	}

	/**
	 * @param ResourceLoader $rl
	 */
	public function onResourceLoaderRegisterModules( ResourceLoader $rl ): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' ) ) {
			return;
		}

		$dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;

		$rl->register( "ext.wikimediaEvents.visualEditor", [
			'localBasePath' => $dir . 'modules',
			'remoteExtPath' => 'WikimediaEvents/modules',
			"scripts" => "ext.wikimediaEvents.visualEditor/campaigns.js",
			"dependencies" => "ext.visualEditor.targetLoader",
		] );
	}

	/**
	 * @param Article $article
	 * @param bool|ParserOutput|null &$outputDone
	 * @param bool &$pcache
	 */
	public function onArticleViewHeader( $article, &$outputDone, &$pcache ) {
		DeferredUpdates::addCallableUpdate( static function () {
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
				MediaWikiServices::getInstance()->getStatsFactory()
					->withComponent( 'WikimediaEvents' )
					->getTiming( 'viewResponseTime_seconds' )
					->setLabel( 'platform', $platform )
					->copyToStatsdAt( "timing.viewResponseTime.{$platform}" )
					->observeSeconds( $measure['duration'] );
			}
		} );
	}

	/**
	 * @param array &$vars
	 * @param OutputPage $out
	 */
	public function onMakeGlobalVariablesScript( &$vars, $out ): void {
		$vars['wgWMESchemaEditAttemptStepOversample'] =
			static::shouldSchemaEditAttemptStepOversample( $out->getContext() );

		// Set page length for reading depth instrument T294777.
		$length = $out->getTitle()->getLength();
		$log = log10( $length );
		$vars[ 'wgWMEPageLength' ] = round( $length, -intval( $log ) );
	}

	/**
	 * @param IContextSource $context
	 * @return bool
	 */
	public static function shouldSchemaEditAttemptStepOversample( IContextSource $context ) {
		// The editingStatsOversample request parameter can trigger oversampling
		$shouldOversample = $context->getRequest()->getBool( 'editingStatsOversample' );
		( new HookRunner( MediaWikiServices::getInstance()->getHookContainer() ) )
			->onWikimediaEventsShouldSchemaEditAttemptStepOversample( $context, $shouldOversample );
		return $shouldOversample;
	}

	/**
	 * WMDE runs banner campaigns to encourage users to create an account and edit.
	 *
	 * The tracking already implemented in the Campaigns extension doesn't quite cover the WMDE
	 * use case. WMDE has a landing page that must be shown before the user progresses to
	 * registration. This could one day be factored out into its own extension, or made
	 * part of the Campaigns extension.
	 *
	 * Task for moving this to the Campaigns extension:
	 * https://phabricator.wikimedia.org/T174939
	 *
	 * Active WMDE campaigns tracked at:
	 * https://phabricator.wikimedia.org/project/subprojects/2821/
	 *
	 * @author addshore on behalf of WMDE
	 *
	 * @param Title $title
	 * @param mixed $unused
	 * @param OutputPage $output
	 * @param User $user
	 * @param WebRequest $request
	 * @param ActionEntryPoint $mediaWiki
	 */
	public function onBeforeInitialize(
		$title,
		$unused,
		$output,
		$user,
		$request,
		$mediaWiki
	): void {
		// Only run for dewiki
		if ( WikiMap::getCurrentWikiId() !== 'dewiki' ) {
			return;
		}

		// Setup the campaign prefix.
		// Everything below this block is agnostic to which campaign is being run.
		$campaignPrefix = 'WMDE_';
		$cookieName = 'wmdecampaign-' . $campaignPrefix;

		$hasCampaignCookie = $request->getCookie( $cookieName ) !== null;
		$hasCampaignQuery = strpos( $request->getRawVal( 'campaign' ) ?? '', $campaignPrefix ) === 0;

		// Get the campaign name from either the cookie or query param
		// Cookie has precedence
		if ( $hasCampaignCookie ) {
			$campaign = $request->getCookie( $cookieName );
		} elseif ( $hasCampaignQuery ) {
			$campaign = $request->getRawVal( 'campaign' );
		} else {
			// Request has nothing to do with our campaign
			return;
		}

		// If an anon user clicks on the banner and doesn't yet have a session cookie then
		// add a session cookie and log the click.
		if ( !$hasCampaignCookie && $hasCampaignQuery && !$user->isRegistered() ) {
			$request->response()->setCookie( $cookieName, $campaign, null );
			wfDebugLog( 'WMDE', "$campaign - 1 - Banner click by anon user without cookie" );
		}

		// If an anon user with the cookie, views the create account page without a campaign
		// query param, then inject it into the WebRequest object to influence the Campaigns
		// extension.
		if (
			!$hasCampaignQuery &&
			$hasCampaignCookie &&
			!$user->isRegistered() &&
			$title->isSpecial( 'CreateAccount' )
		) {
			$request->setVal( 'campaign', $campaign );
			wfDebugLog( 'WMDE', "$campaign - 2 - Inject campaign value on CreateAccount" );
		}
	}
}
