<?php

/**
 * Hooks used for Wikimedia-related logging
 *
 * @author Ori Livneh <ori@wikimedia.org>
 * @author Matthew Flaschen <mflaschen@wikimedia.org>
 * @author Benny Situ <bsitu@wikimedia.org>
 */
class WikimediaEventsHooks {

	/**
	 * Adds 'ext.wikimediaEvents.deprecate' logging module for logged-in users.
	 * @see https://meta.wikimedia.org/wiki/Schema:DeprecatedUsage
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( &$out, &$skin ) {
		if ( !$out->getUser()->isAnon() ) {
			$out->addModules( 'ext.wikimediaEvents.deprecate' );
		}

		if ( self::enabledHHVMBetaFeature( $out->getUser() ) ) {
			$out->addModules( 'ext.wikimediaEvents.backendPerformance' );
		}
	}

	/**
	 * Tag changes made via HHVM
	 *
	 * @param RecentChange $rc
	 * @return bool
	 */
	public static function onRecentChange_save( RecentChange $rc ) {
		if ( wfIsHHVM() ) {
			ChangeTags::addTags( 'HHVM', $rc->getAttribute( 'rc_id' ) );
		}

		return true;
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

		// Get the user's edit count.
		$editCount = $user->getEditCount();

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
		if ( ( $wgOut->getTitle() && $wgOut->getTitle()->isSpecial( 'Preferences' ) )
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
	public static function onTitleMoveComplete( Title &$oldTitle, Title &$newTitle, User &$user, $pageId, $redirectId, $reason ) {
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

	/**
	 * On MakeGlobalVariablesScript, set 'wgPoweredByHHVM' config var.
	 *
	 * @param array &$vars
	 * @param OutputPage $out
	 */
	public static function onMakeGlobalVariablesScript( &$vars, OutputPage $out ) {
		if ( wfIsHHVM() ) {
			$vars['wgPoweredByHHVM'] = true;
		}
	}

	/**
	 * Register 'HHVM' change tag.
	 *
	 * @param array &$tags
	 */
	public static function onListDefinedTags( &$tags ) {
		$tags[] = 'HHVM';
	}

	/**
	 * Set the HHVM cookie on login
	 *
	 * @param User $user
	 * @param array &$session
	 * @param array &$cookies
	 */
	public static function onUserSetCookies( User $user, &$session, &$cookies ) {
		// We can't use $cookies because we need to override the default prefix.
		self::setHHVMCookie(
			$user->getRequest()->response(),
			self::enabledHHVMBetaFeature( $user )
		);
	}

	/**
	 * Set the HHVM cookie for the given WebResponse.
	 *
	 * @param WebResponse $response
	 * @param bool $value
	 */
	private static function setHHVMCookie( WebResponse $response, $value ) {
		$response->setcookie(
			'HHVM',
			$value ? 'true': '', // must be the string "true"
			0, // expiry
			array( 'prefix' => '' )
		);
	}

	/**
	 * Set the HHVM cookie after saving preferences
	 *
	 * @param $formData
	 * @param PreferencesForm $form
	 * @param User $user
	 */
	public static function onPreferencesFormPreSave( $formData, PreferencesForm $form, User $user ) {
		self::setHHVMCookie(
			$form->getRequest()->response(),
			self::enabledHHVMBetaFeature( $user )
		);
	}

	/**
	 * Whether the user has enabled the HHVM BetaFeature
	 *
	 * @param User $user
	 * @return bool
	 */
	private static function enabledHHVMBetaFeature( User $user ) {
		return class_exists( 'BetaFeatures' ) && BetaFeatures::isFeatureEnabled( $user, 'HHVM' );
	}

	/**
	 * Register HHVM as a toggleable beta feature.
	 *
	 * @param User $user
	 * @param array &$prefs
	 */
	public static function onGetBetaFeaturePreferences( User $user, array &$prefs ) {
		$prefs['HHVM'] = array(
			'label-message'   => 'hhvm-label',
			'desc-message'    => 'hhvm-desc',
			'info-link'       => '//www.mediawiki.org/wiki/Special:MyLanguage/HHVM/About',
			'discussion-link' => '//www.mediawiki.org/wiki/Talk:HHVM/About',
		);
	}
}
