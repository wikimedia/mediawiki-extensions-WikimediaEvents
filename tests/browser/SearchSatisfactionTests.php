<?php

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverKeys;
// Used to get arround lack of support for advanced css selectors in selenium
use \Symfony\Component\CssSelector\CssSelectorConverter;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Best run from inside vagrant, as accessing eventlogging.log from the mwv
 * host is sometimes not instantaneous.
 *
 * IMPORTANT: For this to work right the * ext.wikimediaEvents.searchSatisfaction.js
 * script needs to be edited such that the oneIn() function returns true no
 * matter what.
 *
 * Steps to run in a chrome browser on the host:
 *
 * Retrieve a modern version of chromedriver and unzip to ~/bin/:
 *   http://chromedriver.storage.googleapis.com/2.22/chromedriver_linux64.zip
 * Run the following on the host to start chrome driver and forward it into mwv:
 *   ~/bin/chromedriver &
 *   vagrant ssh -- -R 9515:localhost:9515
 * From inside the vagrant session:
 *   SELENIUM_BROWSER=chrome phpunit /vagrant/mediawiki/extensions/WikimediaEvents/tests/browser/SearchSatisfactionTest.php
 *
 * Specific tests can be run using phpunit's --filter argument against test names:
 *   SELENIUM_BROWSER=firefox phpunit /.../SearchSatisfactionTest.php --filter 'full text search'
 *
 * Note that this can be very slow under default mediawiki vagrant, timeouts
 * are possible. Consider enabling apache disk caching for /w/load.php to speed
 * up the test. To enable the cache inside mediawiki vagrant:
 *
 *   sudo a2enmod cache_disk
 *   echo CacheEnable disk /w/load.php | sudo tee /etc/apache2/site-confs/devwiki/50-cache.conf
 *   echo CacheIgnoreNoLastMod On | sudo tee -a /etc/apache2/site-confs/devwiki/50-cache.conf
 *   sudo service apache2 reload
 *
 * To clear the disk cache after editing some js use:
 *   sudo rm -rf /var/cache/apache2/mod_cache_disk/*
 */
class SearchSatisfactionTest extends PHPUnit_Framework_TestCase {
	protected $webDriver;

	public function setUp() {
		$browser = getenv( 'SELENIUM_BROWSER' );
		switch ( strtolower( $browser ) ) {
		case 'chrome':
			// requires driver from http://chromedriver.storage.googleapis.com/
			$url = 'http://localhost:9515';
			$cap = DesiredCapabilities::chrome();
			break;
		case 'phantomjs':
			// Runs via selenium-server-standalone jar
			$url = 'http://localhost:4444/wd/hub';
			$cap = DesiredCapabilities::phantomjs();
			break;
		case 'firefox':
			// Runs via selenium-server-standalone jar
			$url = 'http://localhost:4444/wd/hub';
			$cap = DesiredCapabilities::firefox();
			break;
		default:
			$url = '';
			$capClass = 'Facebook\WebDriver\Remote\DesiredCapabilities';
			if ( $browser && method_exists( $capClass, $browser ) ) {
				$cap = call_user_func( array( $capClass, $browser ) );
			} else {
				throw new \RuntimeException( 'SELENIUM_BROWSER environment var must be set to a known browser' );
			}
		}
		if ( getenv( 'SELENIUM_BROWSER_URL' ) ) {
			$url = getenv( 'SELENIUM_BROWSER_URL' );
		} elseif ( !$url ) {
			throw new \RuntimeException( 'SELENIUM_BROWSER_URL environment var must be set' );
		}
		$this->webDriver = RemoteWebDriver::create( $url, $cap );

		$baseUrl = getenv( 'SELENIUM_URL_BASE' );
		if ( !$baseUrl ) {
			$baseUrl = "http://localhost:8080/wiki/";
		}
		// evil hax to attach our own property
		$this->webDriver->mwBaseUrl = $baseUrl;

		$eventLoggingPath = getenv( 'MW_EVENT_LOG' );
		if ( $eventLoggingPath ) {
			$this->eventLoggingPath = $eventLoggingPath;
		} else {
			$this->eventLoggingPath = '/vagrant/logs/eventlogging.log';
		}
		if ( !is_file( $this->eventLoggingPath ) ) {
			throw new \RuntimeException( "Couldn't find eventlogging.log. Please provide a path with MW_EVENT_LOG environment var" );
		}
	}

	public function somethingProvider() {
		return array(
			"full text search click through" => array(
				array(
					$this->visitPage( "Special:Search?search=main" ),
					$this->clickSearchResult( 0 ),
				),
				array(
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ),
				),
			),
			"full text search ctrl-click through" => array(
				array(
					$this->visitPage( "Special:Search?search=main" ),
					$this->ctrlClickSearchResult( 0 ),
				),
				array(
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ),
				),
			),
			"full text search click through, back, click different result" => array(
				array(
					$this->visitPage( "Special:Search?search=main" ),
					$this->clickSearchResult( 0 ),
					$this->sleep( 2 ),
					$this->clickBackButton(),
					$this->clickSearchResult( 1 ),
				),
				array(
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'click', 'source' => 'fulltext', 'position' => 1 ),
					array( 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 1 ),
				),
			),
			"full text search redirect click through" => array(
				array(
					$this->ensurePage( "Redirect", "#REDIRECT [[Main Page]]" ),
					$this->visitPage( "Special:Search?search=redirect&fulltext=1" ),
					$this->clickRedirectSearchResult( 0 ),
				),
				array(
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ),
				)
			),
			"full text search redirect ctrl-click through" => array(
				array(
					$this->ensurePage( "Redirect", "#REDIRECT [[Main Page]]" ),
					$this->visitPage( "Special:Search?search=redirect&fulltext=1" ),
					$this->ctrlClickRedirectSearchResult( 0 ),
				),
				array(
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ),
				)
			),
			"full text search alt title click through" => array(
				array(
					$this->visitPage( "Special:Search?search=role" ),
					$this->ctrlClickAltTitleSearchResult( 0 ),
				),
				array(
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ),
				)
			),
			"full text search alt title ctrl-click through" => array(
				array(
					$this->visitPage( "Special:Search?search=role" ),
					$this->ctrlClickAltTitleSearchResult( 0 ),
				),
				array(
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ),
				)
			),
			"skin autocomplete click through" => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "main" ),
					$this->waitForSkinAutocomplete(),
					$this->clickSkinAutocompleteResult( 0 ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
				),
			),
			"skin autocomplete via enter key" => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "main" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( "\n" ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			"skin autocomplete via click on 'containing...'" => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "main" ),
					$this->waitForSkinAutocomplete(),
					$this->clickSkinAutocompleteContaining(),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			"skin autocomplete via arrow up and enter on 'containing...'" => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "main" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( WebDriverKeys::ARROW_UP . "\n" ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			"skin autocomplete via arrow down and enter" => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "main" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN . "\n" ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
				),
			),
			"skin autocomplete via arrow down and magnifying glass" => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "main" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					$this->clickMagnifyingGlass(),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
				),
			),
			"skin autocomplete via typed exact match and enter" => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "Main Page" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( "\n" ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
				),
			),
			"skin autocomplete via typed exact match and magnifying glass" => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "Main Page" ),
					// the user might not do this, but it makes the test more reliable
					// to guarantee the SERP event comes in.
					$this->waitForSkinAutocomplete(),
					$this->clickMagnifyingGlass(),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
				),
			),
			'skin autocomplete selecting via down arrow, editing to title match, wait for results, and press enter' => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "Main Page" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete(
						WebDriverKeys::ARROW_DOWN .
						str_repeat( WebDriverKeys::BACKSPACE, 4 ) .
						"page"
					),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( "\n" ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
				),
			),
			'skin autocomplete selecting via down arrow, editing to title match, and press enter' => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "Main Page" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete(
						WebDriverKeys::ARROW_DOWN .
						str_repeat( WebDriverKeys::BACKSPACE, 4 ) .
						"page\n"
					),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
				),
			),
			'skin autocomplete selecting via down arrow, editing to non-title match, and press enter' => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "Main Page" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					$this->typeIntoSkinAutocomplete( str_repeat( WebDriverKeys::BACKSPACE, 4 ) . "\n" ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			'skin autocomplete selecting via down arrow, editing to non-title match, and click magnifying glass' => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "Main Page" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					$this->typeIntoSkinAutocomplete( str_repeat( WebDriverKeys::BACKSPACE, 4 ) ),
					$this->clickMagnifyingGlass(),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			'skin autocomplete selecting via down arrow, editing to title match, and click magnifying glass' => array(
				// actions
				array(
					$this->visitPage( "Main_Page" ),
					$this->typeIntoSkinAutocomplete( "Main Page" ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					$this->typeIntoSkinAutocomplete( str_repeat( WebDriverKeys::BACKSPACE, 4 ) . "page" ),
					$this->clickMagnifyingGlass(),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
				),
			),
			// Note that this test requires some page to exist with the text 'mani page', or the
			// did you mean will be rewritten automatically and return search results for 'main page'
			'full text search click the "did you mean" result' => array(
				// actions
				array(
					$this->visitPage( "Special:Search?search=mani%20page" ),
					// if the button is clicked too quickly the event doesn't fire because
					// js hasn't loaded.
					$this->sleep( 2 ),
					$this->clickDidYouMeanSuggestion(),
				),
				// expected events
				array(
					// @TODO the did you mean should be integrated and trigger some click event
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			'Special:Search bar type then enter' => array(
				// actions
				array(
					$this->visitPage( 'Special:Search' ),
					$this->typeIntoSearchAutocomplete( "main" ),
					$this->waitForSearchAutocomplete(),
					$this->typeIntoSearchAutocomplete( "\n" ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			'Special:Search bar type, arrow down, enter' => array(
				// actions
				array(
					$this->visitPage( 'Special:Search' ),
					$this->typeIntoSearchAutocomplete( "main" ),
					$this->waitForSearchAutocomplete(),
					$this->typeIntoSearchAutocomplete( WebDriverKeys::ARROW_DOWN . "\n" ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			'Special:Search bar type, click result with mouse' => array(
				// actions
				array(
					$this->visitPage( 'Special:Search' ),
					$this->typeIntoSearchAutocomplete( 'main' ),
					$this->waitForSearchAutocomplete(),
					$this->clickSearchAutocompleteResult( 0 ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			'full text search ctrl-click for new tab' => array(
				// actions
				array(
					$this->visitPage( 'Special:Search?search=main' ),
					$this->ctrlClickSearchResult( 0 ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
					array( 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ),
				),
			),
			'skin autocomplete ctrl-click result for new tab' => array(
				// actions
				array(
					$this->visitPage( 'Special:Search' ),
					$this->typeIntoSkinAutocomplete( 'main' ),
					$this->waitForSkinAutocomplete(),
					$this->ctrlClickSkinAutocompleteResult( 0 ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
				),
			),
			'skin autocomplete arrow down and ctrl-click result for new tab' => array(
				// actions
				array(
					$this->visitPage( 'Special:Search' ),
					$this->typeIntoSkinAutocomplete( 'main' ),
					$this->waitForSkinAutocomplete(),
					$this->typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					$this->ctrlClickSkinAutocompleteResult( 0 ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
				),
			),
			// This test is a bit odd because the ctrl-click doesn't trigger a new tab,
			// it gets eaten by the ooui widget and a search is performed in the browser
			'Special:Search autocomplete ctrl-click result' => array(
				// actions
				array(
					$this->visitPage( 'Special:Search' ),
					$this->typeIntoSearchAutocomplete( 'main '),
					$this->waitForSearchAutocomplete(),
					$this->ctrlClickSearchAutocompleteResult( 0 ),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			'skin autocomplete non-exact match ctrl-click magnifying glass for new tab' => array(
				// actions
				array(
					$this->visitPage( 'Main_Page' ),
					$this->typeIntoSkinAutocomplete( 'main' ),
					$this->waitForSkinAutocomplete(),
					$this->ctrlClickMagnifyingGlass(),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ),
				),
			),
			'skin autocomplete exact match ctrl-click magnifying glass for new tab' => array(
				// actions
				array(
					$this->visitPage( 'Main_Page' ),
					$this->typeIntoSkinAutocomplete( 'main page' ),
					$this->waitForSkinAutocomplete(),
					$this->ctrlClickMagnifyingGlass(),
				),
				// expected events
				array(
					array( 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ),
					array( 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ),
					array( 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ),
				),
			),
		);
	}

	/**
	 * @dataProvider somethingProvider
	 */
	public function testSomething( array $actions, array $expectedEvents ) {
		$logPosition = $this->getEventLogPosition();
		try {
			foreach ( $actions as $action ) {
				call_user_func( $action, $this->webDriver );
			}
		} catch ( \Exception $e ) {
			// This delay is necessary to ensure all events have been collected
			// before shutting down the browser, so they don't invade the next test.
			sleep( 5 );
			// @todo there can be multiple tabs, we need to close them all.
			$this->webDriver->close();
			throw $e;
		}
		// This delay is necessary to ensure all events have been collected
		// before shutting down the browser, so they don't invade the next test.
		sleep( 5 );
		// @todo there can be multiple tabs, we need to close them all. But calling
		// this twice doesn't help (in chromedriver at least).
		$this->webDriver->close();
		$actualEvents = $this->collectEvents( $logPosition );

		// All expected events must have same keys
		$wantedKeys = array_flip( array_keys( reset( $expectedEvents ) ) );
		$defaults = array_combine(
			array_keys( $wantedKeys ),
			array_fill(0, count( $wantedKeys ), null )
		);
		$finalEvents = array();
		$seen = array();
		foreach ( $actualEvents as $idx => $envelope ) {
			$actualEvent = $envelope['event'];
			// Filter unreliable checkin events
			if ( $actualEvent['action'] === 'checkin' ) {
				continue;
			}

			// Filter out duplicate events
			if ( isset( $seen[$actualEvent['uniqueId']] ) ) {
				continue;
			}
			$seen[$actualEvent['uniqueId']] = true;

			foreach ( $actualEvent as $k => $v ) {
				if ( !isset( $wantedKeys[$k] ) ) {
					unset( $actualEvent[$k] );
				}
			}

			$finalEvents[] = $actualEvent + $defaults;
		}

		// Give a reasonable debug output to look at problems. Do this
		// before sorting to give a better idea of the order of events.
		$debug = sprintf(
			"expected: %s\nactual: %s",
			json_encode( $expectedEvents, JSON_PRETTY_PRINT ),
			json_encode( $finalEvents, JSON_PRETTY_PRINT )
		);

		// Sometimes the events come in with different orders due to
		// sendBeacon, so lets sort them.
		$sorter = function ( $a, $b ) {
			ksort( $a );
			ksort( $b );
			return strcmp( json_encode( $a ), json_encode( $b ) );
		};
		usort( $expectedEvents, $sorter );
		usort( $finalEvents, $sorter );

		$this->assertEquals( $expectedEvents, $finalEvents, $debug );
	}

	private function getEventLogPosition() {
		return strlen( file_get_contents( $this->eventLoggingPath ) );
	}

	private function collectEvents( $prevPosition ) {
		$log = file_get_contents( $this->eventLoggingPath );
		$events = array();
		foreach ( explode( "\n", substr( $log, $prevPosition ) ) as $line ) {
			if ( trim( $line ) ) {
				$events[] = json_decode( $line, true );
			}
		}

		return $events;
	}

	protected function visitPage( $url ) {
		return function ( $webDriver ) use ( $url ) {
			$webDriver->get( $webDriver->mwBaseUrl . $url );
		};
	}


	protected function waitForSkinAutocomplete() {
		return function ( $webDriver ) {
			$webDriver->wait()->until(
				WebDriverExpectedCondition::presenceOfElementLocated(
					WebDriverBy::cssSelector( '.suggestions-results a' )
				)
			);
		};
	}

	protected function typeIntoSkinAutocomplete( $chars ) {
		return function ( $webDriver ) use ( $chars ) {
			sleep(1);
			$webDriver->findElement( WebDriverBy::id( 'searchInput' ) )->sendKeys( $chars );
		};
	}

	protected function clickSkinAutocompleteResult( $position ) {
		return function ( $webDriver ) use ( $position ) {
			$webDriver->findElement(
				WebDriverBy::cssSelector( ".suggestions-result[rel='$position']" )
			)->click();
		};
	}

	protected function ctrlClickSkinAutocompleteResult( $position ) {
		return function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, WebDriverBy::cssSelector(
				".suggestions-result[rel='$position']"
			) );
		};
	}

	protected function waitForSearchAutocomplete() {
		return function ( $webDriver ) {
			$webDriver->wait()->until(
				WebDriverExpectedCondition::presenceOfElementLocated(
					WebDriverBy::cssSelector( '#searchText a' )
				)
			);
		};
	}

	protected function typeIntoSearchAutocomplete( $chars ) {
		return function ( $webDriver ) use ( $chars ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				'#searchText input.oo-ui-inputWidget-input'
			) )->sendKeys( $chars );
		};
	}

	protected function clickDidYouMeanSuggestion() {
		return function ( $webDriver ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				'.searchdidyoumean a'
			) )->click();
		};
	}

	protected function clickSearchAutocompleteResult( $position ) {
		$position += 1;
		return function ( $webDriver ) use ( $position ) {
			$webDriver->findElement(
				self::byExtendedCss( "#searchText a:nth-of-type($position)" )
			)->click();
		};
	}

	protected function ctrlClickSearchAutocompleteResult( $position ) {
		$position += 1;
		return function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, self::byExtendedCss(
				"#searchText a:nth-of-type($position)"
			) );
		};
	}

	protected function clickSearchResult( $position ) {
		return function ( $webDriver ) use ( $position ) {
			$webDriver->findElement(
				WebDriverBy::cssSelector( "*[data-serp-pos='$position']" )
			)->click();
		};
	}

	protected function ctrlClickSearchResult( $position ) {
		return function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, WebDriverBy::cssSelector(
				"*[data-serp-pos='$position']"
			) );
		};
	}

	protected function clickRedirectSearchResult( $position ) {
		return function ( $webDriver ) use ( $position ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				"*[data-serp-pos='$position'] ~ span a.mw-redirect"
			) )->click();
		};
	}

	protected function ctrlClickRedirectSearchResult( $position ) {
		return function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, WebDriverBy::cssSelector(
				"*[data-serp-pos='$position'] ~ span a.mw-redirect"
			) );
		};
	}

	protected function clickAltTitleSearchResult( $position ) {
		return function ( $webDriver ) use ( $position ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				"[data-serp-pos='$position'] ~ span.searchalttitle a"
			) )->click();
		};
	}

	protected function ctrlClickAltTitleSearchResult( $position ) {
		return function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, WebDriverBy::cssSelector(
				"[data-serp-pos='$position'] ~ span.searchalttitle a"
			) );
		};
	}

	protected function clickSkinAutocompleteContaining() {
		return function ( $webDriver ) {
			$webDriver->findElement(
				WebDriverBy::cssSelector( '.suggestions .special-label' )
			)->click();
		};
	}

	protected function clickMagnifyingGlass() {
		return function ( $webDriver ) {
			$webDriver->findElement(
				WebDriverBy::id( 'searchButton' )
			)->click();
		};
	}

	protected function ctrlClickMagnifyingGlass() {
		return function ( $webDriver ) {
			self::ctrlClick( $webDriver, WebDriverBy::id( 'searchButton' ) );
		};
	}

	protected function clickBackButton() {
		return function ( $webDriver ) {
			$webDriver->navigate()->back();
		};
	}

	protected function sleep( $length ) {
		return function () use ( $length ) {
			sleep( $length );
		};
	}


	protected function getContent( $title ) {
		$url = 'http://localhost:8080/w/api.php';
		$response = $this->apiCall( array(
			'titles' => $title,
			'action' => 'query',
			'prop' => 'revisions',
			'rvlimit' => 1,
			'rvprop' => 'content',
		) );
		if ( empty( $response['query']['pages'] ) ) {
			return null;
		}
		$page = reset( $response['query']['pages'] );
		if ( empty( $page['revisions'] ) ) {
			return null;
		}
		$rev = reset( $page['revisions'] );
		return $rev['content'];
	}

	protected function ensurePage( $title, $content ) {
		static $seen = array();
		return function () use ( $title, $content, &$seen ) {
			// makes bold assumption title/content will always match
			if ( isset( $seen[$title] ) ) {
				return;
			}
			$seen[$title] = true;

			$currentContent = $this->getContent( $title );
			if ( trim( $content ) === trim( $currentContent ) ) {
				return;
			}
			$response = $this->apiCall( array(
				'action' => 'query',
				'meta' => 'tokens',
			) );
			$response = $this->apiCall( array(), array(
				'action' => 'edit',
				'title' => $title,
				'text' => $content,
				'summary' => 'automated WikimediaEvents browser test edit',
				'token' => $response['query']['tokens']['csrftoken'],
			) );
			// give time for jobs to work their way through
			sleep( 10 );
		};
	}

	protected function apiCall( array $params, $postData = null ) {
		if ( $postData ) {
			$context = stream_context_create( array(
				'http' => array(
					'method' => 'POST',
					'header' => 'Content-type: application/x-www-form-urlencoded',
					'content' => http_build_query( $postData ),
				),
			) );
		} else {
			$context = null;
		}

		return json_decode( file_get_contents( 'http://localhost:8080/w/api.php?' . http_build_query( $params + array(
			'format' => 'json',
			'formatversion' => 2,
		) ), false, $context ), true );
	}

	/**
	 * Helper function to perform ctrl-click (open in new tab/window)
	 */
	protected static function ctrlClick( $webDriver, $webDriverBy ) {
		$webDriver->action()
			->keyDown( null, WebDriverKeys::CONTROL )
			->click( $webDriver->findElement( $webDriverBy ) )
			->keyUp( null, WebDriverKeys::CONTROL )
			->perform();
		// some drivers don't wait for the resulting page to load
		// because it's not the active window. Throw in some extra
		// wait time to try and deal with that.
		sleep( 5 );
	}

	/**
	 * Supports advanced css selector syntax that selenium can't
	 * such as attribute selection
	 */
	protected static function byExtendedCss( $selector ) {
		static $conv;
		if ( $conv === null ) {
			$conv = new CssSelectorConverter();
		}
		return WebDriverBy::xpath( $conv->toXPath( $selector ) );
	}

}
