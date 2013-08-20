<?php
/**
 * CoreEvents extension
 *
 * @ingroup Extensions
 *
 * @author Ori Livneh <ori@wikimedia.org>
 * @author Matthew Flaschen <mflaschen@wikimedia.org>
 * @author Benny Situ <bsitu@wikimedia.org>
 *
 * @license GPL v2 or later
 * @version 1.0
 */

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'CoreEvents',
	'version' => '1.0',
	'url' => 'https://www.mediawiki.org/wiki/Extension:CoreEvents',
	'author' => array(
		'Matthew Flaschen',
		'Ori Livneh',
		'Benny Situ',
	),
	'descriptionmsg' => 'coreevents-desc',
);

// Messages

$wgExtensionMessagesFiles['CoreEvents'] = __DIR__ . '/CoreEvents.i18n.php';

// Configs

/**
 * @var int|bool: Conduct & log test for HTTPS support once per this
 *                many (non-HTTPS) requests.
 */
$wgHttpsFeatureDetectionSamplingFactor = 2500;

$wgResourceModules += array(
	'schema.HttpsSupport' => array(
		'class'         => 'ResourceLoaderSchemaModule',
		'schema'        => 'HttpsSupport',
		'revision'      => 5733378,
		'targets'       => array( 'desktop', 'mobile' ),
		'mobileTargets' => array( 'stable', 'beta', 'alpha' ),
	),
	'ext.coreEvents.httpsSupport' => array(
		'scripts'       => 'ext.coreEvents.httpsSupport.js',
		'localBasePath' => __DIR__ . '/modules',
		'remoteExtPath' => 'CoreEvents/modules',
		'dependencies'  => 'schema.HttpsSupport',
		'targets'       => array( 'desktop', 'mobile' ),
		'mobileTargets' => array( 'stable', 'beta', 'alpha' ),
	),
);

// Hooks

$wgHooks[ 'BeforePageDisplay' ][] = function ( &$out, &$skin ) {
	$out->addModules( array( 'schema.HttpsSupport', 'ext.coreEvents.httpsSupport' ) );
	return true;
};

$wgHooks[ 'ResourceLoaderGetConfigVars' ][] = function ( &$vars ) {
	global $wgHttpsFeatureDetectionSamplingFactor;
	$vars[ 'wgHttpsFeatureDetectionSamplingFactor' ] = $wgHttpsFeatureDetectionSamplingFactor;
};

/**
 * Log server-side event on successful page edit.
 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
 * @see https://meta.wikimedia.org/wiki/Schema:PageContentSaveComplete
 */
// Imported from EventLogging extension
$wgHooks['PageContentSaveComplete'][] = function ( $article, $user, $content, $summary,
	$isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId ) {

	if ( $revision ) {
		$event = array(
			'revisionId' => $revision->getId(),
			'isAPI' => defined( 'MW_API' ),
			'isMobile' => ( class_exists( 'MobileContext' )
				&& MobileContext::singleton()->shouldDisplayMobileView() ),
		);
		if ( isset( $_SERVER[ 'HTTP_USER_AGENT' ] ) ) {
			$event[ 'userAgent' ] = $_SERVER[ 'HTTP_USER_AGENT' ];
		}
		efLogServerSideEvent( 'PageContentSaveComplete', 5588433, $event );
	}
	return true;
};

/**
 * Handler for UserSaveOptions hook.
 * @see http://www.mediawiki.org/wiki/Manual:Hooks/UserSaveOptions
 * @param User $user user whose options are being saved
 * @param array $options Options being saved
 * @return bool true in all cases
 */
// Modified version of original method from the Echo extension
$wgHooks['UserSaveOptions'][] = function ( $user, &$options ) {
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
				efLogServerSideEvent( 'PrefUpdate', 5563398, $event );
			}
		}
	}

	return true;
};
