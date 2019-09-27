<?php

namespace WikimediaEvents\Tests;

use FauxRequest;
use HashConfig;
use MediaWikiTestCase;
use MultiConfig;
use MWException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit_Framework_MockObject_MockObject;
use RequestContext;
use Title;
use User;
use WikimediaEvents\PageViews;

/**
 * Class PageViewsTest
 * @covers \WikimediaEvents\PageViews
 */
class PageViewsTest extends MediaWikiTestCase {

	public static function getDefaultContext() {
		$user = User::newFromName( 'Test' );
		$user->setToken( 'salty' );
		$context = new RequestContext();
		$context->setTitle( Title::newFromText( 'Test' ) );
		$context->setUser( $user );
		return $context;
	}

	/**
	 * @covers \WikimediaEvents\PageViews::hashSensitiveQueryParams
	 * @dataProvider dataProviderSensitiveQueryParams
	 * @param string $input
	 * @param string $output
	 */
	public function testHashSensitiveQueryParams( $input, $output ) {
		$event = [ PageViews::EVENT_QUERY => $input ];
		$pageViews = new PageViews( self::getDefaultContext() );
		$pageViews->setEvent( $event );
		$pageViews->hashSensitiveQueryParams();
		$this->assertArrayEquals( $pageViews->getEvent(), [ PageViews::EVENT_QUERY => $output ],
			false, true );
	}

	/**
	 * Data provider for testHashSensitiveQueryParams
	 *
	 * @return array
	 */
	public function dataProviderSensitiveQueryParams() {
		$pageViews = new PageViews( self::getDefaultContext() );
		return [
			[
				'',
				''
			],
			[
				'search=Secret&title=Special%3ASearch&go=Go&fulltext=1',
				'search=' . $pageViews->hash( 'Secret' ) . '&title=redacted&go=Go&fulltext=1',
			],
			[
				'search=Secret&return=MoreSecret&returnto=SuperSecret&random=blah',
				'search=' . $pageViews->hash( 'Secret' ) . '&return=' . $pageViews->hash( 'MoreSecret' ) .
					'&returnto=' . $pageViews->hash( 'SuperSecret' ) . '&random=blah',
			]
		];
	}

	/**
	 * @covers \WikimediaEvents\PageViews::userIsInCohort
	 * @group Database
	 */
	public function testUserIsInCohort() {
		$user = User::newFromId( 0 );
		$context = self::getDefaultContext();
		$context->setUser( $user );
		$pageViews = new PageViews( $context );
		$this->assertEquals( false, $pageViews->userIsInCohort() );

		$user = static::getTestUser()->getUser();
		$context->setUser( $user );
		$pageViews = new PageViews( $context );
		$this->assertEquals( true, $pageViews->userIsInCohort() );

		/** @var User|MockObject $userMock */
		$userMock = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();
		$userMock->expects( $this->any() )
			->method( 'getRegistration' )
			->willReturn( '‌20171116174505' );
		$userMock->expects( $this->any() )
			->method( 'getId' )
			->willReturn( 5 );
		$context = self::getDefaultContext();
		$context->setUser( $userMock );
		$pageViews = new PageViews( $context );
		$pageViews->setUser( $userMock );
		$this->assertEquals( false, $pageViews->userIsInCohort() );
	}

	/**
	 * @covers \WikimediaEvents\PageViews::getSensitiveNamespaces
	 */
	public function testGetSensitiveNamespaces() {
		$pageViews = new PageViews( self::getDefaultContext() );
		foreach ( $pageViews->getSensitiveNamespaces() as $namespace ) {
			$this->assertNotNull( $namespace );
		}
		$this->assertArrayHasKey( 0, $pageViews->getSensitiveNamespaces() );
		$additionalNamespaces = [
			'NS_PORTAL' => 123,
			'NS_PORTAL_TALK' => 124,
			'NS_DRAFT' => 125,
			'NS_DRAFT_TALK' => 126,
		];
		foreach ( $additionalNamespaces as $namespace => $id ) {
			define( $namespace, $id );
			$this->assertNotFalse( array_search( $namespace, $pageViews->getSensitiveNamespaces() ) );
		}
	}

	/**
	 * @throws MWException
	 * @covers \WikimediaEvents\PageViews::getPermissionErrors
	 */
	public function testGetPermissionErrors() {
		$context = self::getDefaultContext();
		$request = new FauxRequest();
		$request->setVal( 'action', 'edit' );
		$context->setRequest( $request );
		$pageViews = new PageViews( $context );
		$this->assertEquals( '', $pageViews->getPermissionErrors() );
		/** @var Title|PHPUnit_Framework_MockObject_MockObject $titleMock */
		$titleMock = $this->getMockBuilder( Title::class )
			->disableOriginalConstructor()
			->getMock();
		$titleMock->expects( $this->once() )
			->method( 'getUserPermissionsErrors' )
			->willReturn( [ [
					'protectedpagetext',
					'editprotected',
					'edit',
				] ]
			);
		$context->setTitle( $titleMock );
		$pageViews = new PageViews( $context );
		$this->assertEquals(
			'protectedpagetext,editprotected,edit',
			$pageViews->getPermissionErrors()
		);
		/** @var Title|PHPUnit_Framework_MockObject_MockObject $titleMock */
		$titleMock = $this->getMockBuilder( Title::class )
			->disableOriginalConstructor()
			->getMock();
		$titleMock->expects( $this->once() )
			->method( 'getUserPermissionsErrors' )
			->willReturn( [ [
					'badaccess-group0'
				] ]
			);
		$context->setTitle( $titleMock );
		$pageViews = new PageViews( $context );
		$this->assertEquals( 'badaccess-group0', $pageViews->getPermissionErrors() );

		/** @var Title|PHPUnit_Framework_MockObject_MockObject $titleMock */
		$titleMock = $this->getMockBuilder( Title::class )
			->disableOriginalConstructor()
			->getMock();
		$titleMock->expects( $this->once() )
			->method( 'getUserPermissionsErrors' )
			->willReturn( [] );
		$context->setTitle( $titleMock );
		$pageViews = new PageViews( $context );
		$this->assertEquals( '', $pageViews->getPermissionErrors() );
	}

	/**
	 * @param array $event
	 * @param array $postRedactEvent
	 * @param $context
	 * @covers \WikimediaEvents\PageViews::redactSensitiveData
	 * @dataProvider dataProviderRedactSensitiveData
	 */
	public function testRedactSensitiveData( $event, $postRedactEvent, $context ) {
		$pageViews = new PageViews( $context );
		$pageViews->setEvent( $event );
		$pageViews->redactSensitiveData();
		$this->assertArrayEquals( $postRedactEvent, $pageViews->getEvent(),
			false, true );
	}

	/**
	 * @return array
	 */
	public function dataProviderRedactSensitiveData() {
		$pageViews = new PageViews( self::getDefaultContext() );
		return [
			[
				[
					PageViews::EVENT_TITLE => 'Test',
					PageViews::EVENT_NAMESPACE => NS_USER
				],
				[
					PageViews::EVENT_TITLE => 'Test',
					PageViews::EVENT_NAMESPACE => NS_USER
				],
				self::getDefaultContext()
			],
			[
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Test',
					PageViews::EVENT_PATH => '/wiki/Test',
					PageViews::EVENT_NAMESPACE => NS_MAIN,
					PageViews::EVENT_QUERY => 'search=Secret&title=Special%3ASearch&go=Go',
				],
				[
					PageViews::EVENT_PAGE_ID => $pageViews->hash( 1 ),
					PageViews::EVENT_TITLE => $pageViews->hash( 'Test' ),
					PageViews::EVENT_PATH => '/wiki/' . $pageViews->hash( 'Test' ),
					PageViews::EVENT_NAMESPACE => NS_MAIN,
					PageViews::EVENT_QUERY => 'search=' . $pageViews->hash( 'Secret' ) .
					  '&title=redacted&go=Go',
				],
				self::getDefaultContext()
			],
			[
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Test',
					PageViews::EVENT_PATH => '/wiki/Test',
					PageViews::EVENT_NAMESPACE => NS_HELP,
					PageViews::EVENT_QUERY => 'search=Secret&title=Special%3ASearch&go=Go',
				],
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Test',
					PageViews::EVENT_PATH => '/wiki/Test',
					PageViews::EVENT_NAMESPACE => NS_HELP,
					PageViews::EVENT_QUERY => 'search=' . $pageViews->hash( 'Secret' ) .
					  '&title=redacted&go=Go',
				],
				self::getDefaultContext()
			],
			[
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Admin',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_QUERY => 'title=User:Admin&type=revision&diff=542&oldid=119',
					PageViews::EVENT_NAMESPACE => NS_USER
				],
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Admin',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_NAMESPACE => NS_USER,
					PageViews::EVENT_QUERY => 'title=redacted&type=revision&diff=redacted&oldid=redacted',
				],
				self::getDefaultContext()
			],
			[
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Admin',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_QUERY => 'title=Test',
					PageViews::EVENT_NAMESPACE => NS_USER
				],
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Admin',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_NAMESPACE => NS_USER,
					PageViews::EVENT_QUERY => 'title=redacted',
				],
				(
				function () {
					$context = self::getDefaultContext();
					$title = Title::newFromText( 'Admin' );
					$skin = $context->getSkin();
					$skin->setRelevantTitle( $title );
					$context->setTitle( $title );
					$context->setSkin( $skin );
					$request = $context->getRequest();
					$request->setVal( 'title', 'Test' );
					$context->setRequest( $request );
					return $context;
				} )()
			],
			[
				[
					PageViews::EVENT_PAGE_ID => "0",
					PageViews::EVENT_TITLE => 'UserLogin',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_QUERY => 'title=Special:UserLogin&returnto=Roslyn_Wintheiser',
					PageViews::EVENT_NAMESPACE => -1
				],
				[
					PageViews::EVENT_PAGE_ID => "0",
					PageViews::EVENT_TITLE => 'UserLogin',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_NAMESPACE => -1,
					PageViews::EVENT_QUERY =>
						'title=redacted&returnto=' . $pageViews->hash( 'Roslyn_Wintheiser' ),
				],
				self::getDefaultContext()
			],
			[
				[
					PageViews::EVENT_QUERY => 'title=대구광역시에서/대구광역시에서:대구광역시에서&action=edit',
					PageViews::EVENT_TITLE => '대구광역시에서/대구광역시에서:대구광역시에서',
					PageViews::EVENT_PAGE_ID => 0,
					PageViews::EVENT_NAMESPACE => 0,
					PageViews::EVENT_PATH => '/w/index.php',
				],
				[
					PageViews::EVENT_QUERY => 'title=redacted&action=edit',
					PageViews::EVENT_TITLE => $pageViews->hash( '대구광역시에서/대구광역시에서:대구광역시에서' ),
					PageViews::EVENT_PAGE_ID => 0,
					PageViews::EVENT_NAMESPACE => 0,
					PageViews::EVENT_PATH => '/w/index.php',
				],
				(
				function () {
					$context = self::getDefaultContext();
					$skin = $context->getSkin();
					$skin->setRelevantTitle( Title::newFromText( '대구광역시에서/대구광역시에서:대구광역시에서' ) );
					$context->setSkin( $skin );
					return $context;
				} )()
			],
			[
				[
					PageViews::EVENT_QUERY => 'title=Foo/Bar_(Baz)&action=edit',
					PageViews::EVENT_TITLE => 'Foo/Bar (Baz)',
					PageViews::EVENT_PAGE_ID => 2,
					PageViews::EVENT_NAMESPACE => 0,
					PageViews::EVENT_PATH => '/w/index.php',
				],
				[
					PageViews::EVENT_QUERY => 'title=redacted&action=edit',
					PageViews::EVENT_TITLE => $pageViews->hash( 'Foo/Bar (Baz)' ),
					PageViews::EVENT_PAGE_ID => $pageViews->hash( 2 ),
					PageViews::EVENT_NAMESPACE => 0,
					PageViews::EVENT_PATH => '/w/index.php',
				],
				(
				function () {
					$context = self::getDefaultContext();
					$skin = $context->getSkin();
					$skin->setRelevantTitle( Title::newFromText( 'Foo/Bar (Baz)' ) );
					$context->setSkin( $skin );
					return $context;
				} )()
			],

			[
				[
					PageViews::EVENT_QUERY => 'title=X(y)%3Dz&action=edit',
					PageViews::EVENT_TITLE => 'X(y)=z',
					PageViews::EVENT_PAGE_ID => 3,
					PageViews::EVENT_NAMESPACE => 0,
					PageViews::EVENT_PATH => '/w/index.php',
				],
				[
					PageViews::EVENT_QUERY => 'title=redacted&action=edit',
					PageViews::EVENT_TITLE => $pageViews->hash( 'X(y)=z' ),
					PageViews::EVENT_PAGE_ID => $pageViews->hash( 3 ),
					PageViews::EVENT_NAMESPACE => 0,
					PageViews::EVENT_PATH => '/w/index.php',
				],
				(
				function () {
					$context = self::getDefaultContext();
					$skin = $context->getSkin();
					$skin->setRelevantTitle( Title::newFromText( 'X(y)=z' ) );
					$context->setSkin( $skin );
					return $context;
				} )()
			],

			[
				[
					PageViews::EVENT_QUERY => 'title=Talk:Foo&action=edit',
					PageViews::EVENT_TITLE => 'Foo',
					PageViews::EVENT_PAGE_ID => 4,
					PageViews::EVENT_NAMESPACE => 1,
					PageViews::EVENT_PATH => '/w/index.php',
				],
				[
					PageViews::EVENT_QUERY => 'title=redacted&action=edit',
					PageViews::EVENT_TITLE => $pageViews->hash( 'Foo' ),
					PageViews::EVENT_PAGE_ID => $pageViews->hash( 4 ),
					PageViews::EVENT_NAMESPACE => 1,
					PageViews::EVENT_PATH => '/w/index.php',
				],
				(
				function () {
					$context = self::getDefaultContext();
					$skin = $context->getSkin();
					$skin->setRelevantTitle( Title::newFromText( 'Talk:Foo' ) );
					$context->setSkin( $skin );
					return $context;
				} )()
			],

			[
				[
					PageViews::EVENT_TITLE => 'Main Page',
					PageViews::EVENT_PAGE_ID => '1',
					PageViews::EVENT_REQUEST_METHOD => 'GET',
					PageViews::EVENT_ACTION => '',
					PageViews::EVENT_PERMISSION_ERRORS => '',
					PageViews::EVENT_HTTP_RESPONSE_CODE => 200,
					PageViews::EVENT_IS_MOBILE => false,
					PageViews::EVENT_NAMESPACE => 0,
					PageViews::EVENT_PATH => '/mediawiki/index.php',
					PageViews::EVENT_QUERY => 'title=Special:MovePage/Main_Page',
					PageViews::EVENT_USER_ID => 2,
				],
				[
					PageViews::EVENT_TITLE => 'Main Page',
					PageViews::EVENT_PAGE_ID => '1',
					PageViews::EVENT_REQUEST_METHOD => 'GET',
					PageViews::EVENT_ACTION => '',
					PageViews::EVENT_PERMISSION_ERRORS => '',
					PageViews::EVENT_HTTP_RESPONSE_CODE => 200,
					PageViews::EVENT_IS_MOBILE => false,
					PageViews::EVENT_NAMESPACE => 0,
					PageViews::EVENT_PATH => '/mediawiki/index.php',
					PageViews::EVENT_QUERY => 'title=redacted',
					PageViews::EVENT_USER_ID => 2,
				],
				(
					function () {
						$context = self::getDefaultContext();
						$skin = $context->getSkin();
						$skin->setRelevantTitle( Title::newFromText( 'Main Page' ) );
						$context->setSkin( $skin );
						return $context;
					} )()
			]
		];
	}

	/**
	 * @covers \WikimediaEvents\PageViews::log
	 * @group Database
	 * @throws MWException
	 */
	public function testLog() {
		// Anon users are excluded from logging.
		$context = self::getDefaultContext();
		$context->setUser( User::newFromId( 0 ) );
		$pageViews = new PageViews( $context );
		$this->assertFalse( $pageViews->log() );

		// Test event.
		$context = new RequestContext();
		$request = new FauxRequest();
		$request->setVal( PageViews::EVENT_ACTION, 'edit' );
		$request->setRequestURL(
			'/w/index.php?search=Secret&title=Special%3ASearch&go=Go&fulltext=1&token=abcdefghijklmnop'
		);
		$context->setRequest( $request );
		$title = Title::newFromText( 'TestTitle (Bar)', NS_TALK );
		$context->setTitle( $title );
		$output = $context->getOutput();
		$output->setPageTitle( '<i>TestTitle</i> (Bar)' );
		$context->setOutput( $output );
		$user = static::getTestUser()->getUser();
		$user->setToken( 'salty' );
		$context->setUser( $user );
		$pageViews = new PageViews( $context );
		$expectedEvent = [
			PageViews::EVENT_TITLE => $pageViews->hash( 'TestTitle (Bar)' ),
			PageViews::EVENT_PAGE_ID => "0",
			PageViews::EVENT_REQUEST_METHOD => 'GET',
			PageViews::EVENT_ACTION => 'edit',
			PageViews::EVENT_PERMISSION_ERRORS => '',
			PageViews::EVENT_HTTP_RESPONSE_CODE => false,
			PageViews::EVENT_IS_MOBILE => false,
			PageViews::EVENT_NAMESPACE => 1,
			PageViews::EVENT_PATH => '/w/index.php',
			PageViews::EVENT_QUERY => 'search=' . $pageViews->hash( 'Secret' ) .
			  '&title=redacted&go=Go&fulltext=1&token=redacted',
		];
		$pageViews = new PageViews( $context );
		$pageViews->log();
		$actualEvent = $pageViews->getEvent();
		// The user ID is often incremented by previous tests that don't
		// correctly set $this->tablesUsed, so just check that it is an
		// integer, don't worry about the exact value.
		$this->assertTrue( is_int( $actualEvent[PageViews::EVENT_USER_ID] ) );
		unset( $actualEvent[PageViews::EVENT_USER_ID] );
		$this->assertArrayEquals( $expectedEvent, $actualEvent, false, true );
	}

	/**
	 * @throws \ConfigException
	 * @throws MWException
	 */
	public function testGetAccountAgeLimit() {
		$pageViews = new PageViews( self::getDefaultContext() );
		$this->assertEquals( PageViews::DAY_LIMIT_IN_SECONDS, $pageViews->getAccountAgeLimit() );

		if ( \ExtensionRegistry::getInstance()->isLoaded( 'GrowthExperiments' ) ) {
			// Page view matches help desk, but panel isn't enabled.
			// Expect 24 hour limit.
			$context = self::getDefaultContext();
			$context->setConfig( new MultiConfig( [
				new HashConfig( [
					'GEHelpPanelHelpDeskTitle' => 'Test',
					'GEHelpPanelEnabled' => false
				] ),
				$context->getConfig()
			] ) );
			$pageViews = new PageViews( $context );
			$this->assertEquals(
				PageViews::DAY_LIMIT_IN_SECONDS,
				$pageViews->getAccountAgeLimit()
			);

			// Page view matches subpage of help desk, and panel is enabled.
			// Expect 14 day limit.
			$context = self::getDefaultContext();
			$context->setTitle( Title::newFromText( 'Test/Foo' ) );
			$context->setConfig( new MultiConfig( [
				new HashConfig( [
					'GEHelpPanelHelpDeskTitle' => 'Test',
					'GEHelpPanelEnabled' => true
				] ),
				$context->getConfig()
			] ) );
			$pageViews = new PageViews( $context );
			$this->assertEquals(
				PageViews::HELP_DESK_DAY_LIMIT_IN_SECONDS,
				$pageViews->getAccountAgeLimit()
			);

			// Page view matches help desk, and panel is enabled.
			// Expect 14 day limit.
			$context = self::getDefaultContext();
			$context->setConfig( new MultiConfig( [
				new HashConfig( [
					'GEHelpPanelHelpDeskTitle' => 'Test',
					'GEHelpPanelEnabled' => true
				] ),
				$context->getConfig()
			] ) );
			$pageViews = new PageViews( $context );
			$this->assertEquals(
				PageViews::HELP_DESK_DAY_LIMIT_IN_SECONDS,
				$pageViews->getAccountAgeLimit()
			);

			// Page view matches parent page of help desk, and panel is enabled.
			// Expect 14 day limit.
			$context = self::getDefaultContext();
			$context->setTitle( Title::newFromText( 'Help_Desk' ) );
			$context->setConfig( new MultiConfig( [
				new HashConfig( [
					'GEHelpPanelHelpDeskTitle' => 'Help_Desk/{{SITENAME}}',
					'GEHelpPanelEnabled' => true
				] ),
				$context->getConfig()
			] ) );
			$pageViews = new PageViews( $context );
			$this->assertEquals(
				PageViews::HELP_DESK_DAY_LIMIT_IN_SECONDS,
				$pageViews->getAccountAgeLimit()
			);

			// Page visit is not to help desk. Expect 24 hour limit.
			$context = self::getDefaultContext();
			$context->setConfig( new MultiConfig( [
				new HashConfig( [
					'GEHelpPanelHelpDeskTitle' => 'SomeOtherPage',
					'GEHelpPanelEnabled' => true
				] ),
				$context->getConfig()
			] ) );
			$pageViews = new PageViews( $context );
			$this->assertEquals(
				PageViews::DAY_LIMIT_IN_SECONDS,
				$pageViews->getAccountAgeLimit()
			);
		}
	}

}
