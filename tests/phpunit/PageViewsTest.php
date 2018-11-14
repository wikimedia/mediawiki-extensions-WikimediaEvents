<?php

namespace WikimediaEvents\Tests;

use FauxRequest;
use MediaWikiTestCase;
use MWException;
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
		$this->assertArrayEquals( $pageViews->getEvent(), [ PageViews::EVENT_QUERY => $output ] );
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
				'search=' . $pageViews->hash( 'Secret' ) . '&title=Special%3ASearch&go=Go&fulltext=1',
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
		$user = User::createNew( 'UserTestActorId1' );
		$id = $user->getId();
		$db = wfGetDB( DB_MASTER );
		$row = $db->selectRow( 'user', User::getQueryInfo()['fields'], [ 'user_id' => $id ], __METHOD__ );
		$row->user_registration = $db->timestamp( time() - 864000 );
		$user = User::newFromRow( $row );

		$context = self::getDefaultContext();
		$context->setUser( $user );
		$pageViews = new PageViews( $context );
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
		$this->assertArrayEquals( $pageViews->getEvent(), $postRedactEvent );
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
					PageViews::EVENT_PAGE_TITLE => 'Test',
					PageViews::EVENT_PATH => '/wiki/Test',
					PageViews::EVENT_NAMESPACE => NS_MAIN,
					PageViews::EVENT_QUERY => 'search=Secret&title=Special%3ASearch&go=Go',
				],
				[
					PageViews::EVENT_PAGE_ID => $pageViews->hash( 1 ),
					PageViews::EVENT_TITLE => $pageViews->hash( 'Test' ),
					PageViews::EVENT_PAGE_TITLE => $pageViews->hash( 'Test' ),
					PageViews::EVENT_PATH => '/wiki/' . $pageViews->hash( 'Test' ),
					PageViews::EVENT_NAMESPACE => NS_MAIN,
					PageViews::EVENT_QUERY => 'search=' . $pageViews->hash( 'Secret' ) .
					  '&title=Special%3ASearch&go=Go',
				],
				self::getDefaultContext()
			],
			[
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Test',
					PageViews::EVENT_PAGE_TITLE => 'Test',
					PageViews::EVENT_PATH => '/wiki/Test',
					PageViews::EVENT_NAMESPACE => NS_HELP,
					PageViews::EVENT_QUERY => 'search=Secret&title=Special%3ASearch&go=Go',
				],
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Test',
					PageViews::EVENT_PAGE_TITLE => 'Test',
					PageViews::EVENT_PATH => '/wiki/Test',
					PageViews::EVENT_NAMESPACE => NS_HELP,
					PageViews::EVENT_QUERY => 'search=' . $pageViews->hash( 'Secret' ) .
					  '&title=Special%3ASearch&go=Go',
				],
				self::getDefaultContext()
			],
			[
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Admin',
					PageViews::EVENT_PAGE_TITLE => 'User:Admin',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_QUERY => 'title=User:Admin',
					PageViews::EVENT_NAMESPACE => NS_USER
				],
				[
					PageViews::EVENT_PAGE_ID => 1,
					PageViews::EVENT_TITLE => 'Admin',
					PageViews::EVENT_PAGE_TITLE => 'User:Admin',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_NAMESPACE => NS_USER,
					PageViews::EVENT_QUERY => 'title=User:Admin',
				],
				self::getDefaultContext()
			],
			[
				[
					PageViews::EVENT_PAGE_ID => "0",
					PageViews::EVENT_TITLE => 'UserLogin',
					PageViews::EVENT_PAGE_TITLE => 'Log in',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_QUERY => 'title=Special:UserLogin&returnto=Roslyn_Wintheiser',
					PageViews::EVENT_NAMESPACE => -1
				],
				[
					PageViews::EVENT_PAGE_ID => "0",
					PageViews::EVENT_TITLE => 'UserLogin',
					PageViews::EVENT_PAGE_TITLE => 'Log in',
					PageViews::EVENT_PATH => '/w/index.php',
					PageViews::EVENT_NAMESPACE => -1,
					PageViews::EVENT_QUERY =>
						'title=Special%3AUserLogin&returnto=' . $pageViews->hash( 'Roslyn_Wintheiser' ),
				],
				self::getDefaultContext()
			],
			[
				[
					PageViews::EVENT_TITLE => 'Main Page',
					PageViews::EVENT_PAGE_TITLE => 'Move Main Page',
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
					PageViews::EVENT_PAGE_TITLE => 'Move Main Page',
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
		$output = $context->getOutput();
		$output->setPageTitle( 'TestTitle' );
		$context->setOutput( $output );
		$user = static::getTestUser()->getUser();
		$user->setToken( 'salty' );
		$context->setUser( $user );
		$title = Title::newFromText( 'TestTitle', NS_TALK );
		$context->setTitle( $title );
		$pageViews = new PageViews( $context );
		$expectedEvent = [
			PageViews::EVENT_PAGE_TITLE => $pageViews->hash( 'TestTitle' ),
			PageViews::EVENT_TITLE => $pageViews->hash( 'TestTitle' ),
			PageViews::EVENT_PAGE_ID => "0",
			PageViews::EVENT_REQUEST_METHOD => 'GET',
			PageViews::EVENT_ACTION => 'edit',
			PageViews::EVENT_PERMISSION_ERRORS => '',
			PageViews::EVENT_HTTP_RESPONSE_CODE => false,
			PageViews::EVENT_IS_MOBILE => false,
			PageViews::EVENT_NAMESPACE => 1,
			PageViews::EVENT_PATH => '/w/index.php',
			PageViews::EVENT_QUERY => 'search=' . $pageViews->hash( 'Secret' ) .
			  '&title=Special%3ASearch&go=Go&fulltext=1&token=redacted',
			PageViews::EVENT_USER_ID => 2
		];
		$pageViews = new PageViews( $context );
		$pageViews->log();
		$this->assertArrayEquals( $expectedEvent, $pageViews->getEvent() );
	}

}