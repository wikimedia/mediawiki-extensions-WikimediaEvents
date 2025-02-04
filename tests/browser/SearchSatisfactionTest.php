<?php

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverKeys;
// Used to get arround lack of support for advanced css selectors in selenium
use Symfony\Component\CssSelector\CssSelectorConverter;

if ( is_readable( __DIR__ . '/../../vendor/autoload.php' ) ) {
	require_once __DIR__ . '/../../vendor/autoload.php';
}

/**
 * Best run from inside vagrant, as accessing eventlogging.log from the mwv
 * host is sometimes not instantaneous.
 *
 * IMPORTANT: For this to work right the searchSatisfaction.js script needs to
 * be edited such that all sessions are in-sample.
 *
 * Steps to run in a chrome browser on the host:
 *
 * Retrieve a modern version of chromedriver and unzip to ~/bin/:
 *   http://chromedriver.storage.googleapis.com/2.22/chromedriver_linux64.zip
 * Run the following on the host to start chrome driver and forward it into mwv:
 *   ~/bin/chromedriver &
 *   vagrant ssh -- -R 9515:localhost:9515
 * From inside the vagrant session:
 *   SELENIUM_BROWSER=chrome phpunit
 *       /vagrant/mediawiki/extensions/WikimediaEvents/tests/browser/SearchSatisfactionTest.php
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
 *
 * @coversNothing
 * @group Broken
 */
class SearchSatisfactionTest extends PHPUnit\Framework\TestCase {

	/** @var string */
	private static $mwBaseUrl;

	/** @var RemoteWebDriver */
	protected $webDriver;

	public function setUp(): void {
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
				if ( $browser && method_exists( DesiredCapabilities::class, $browser ) ) {
					$cap = DesiredCapabilities::$browser();
				} else {
					throw new \RuntimeException(
						'SELENIUM_BROWSER environment var must be set to a known browser' );
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
			// Need to use cirrustest for the api action to rebuild the suggester
			// to work. Note sure yet if this causes any issues for Cirrus browser
			// tests...
			$baseUrl = "http://cirrustest.wiki.local.wmftest.net:8080/wiki/";
		}
		// evil hax to attach our own property
		self::$mwBaseUrl = $baseUrl;

		$eventLoggingPath = getenv( 'MW_EVENT_LOG' );
		if ( $eventLoggingPath ) {
			$this->eventLoggingPath = $eventLoggingPath;
		} else {
			$this->eventLoggingPath = '/vagrant/logs/eventlogging.log';
		}
		if ( !is_file( $this->eventLoggingPath ) ) {
			throw new \RuntimeException( "Couldn't find eventlogging.log. " .
				"Please provide a path with MW_EVENT_LOG environment var" );
		}

		static $initializedSuggester = null;
		$initializedSuggester ??= (bool)getenv( 'SKIP_SUGGESTER_INIT' );
		if ( !$initializedSuggester ) {
			// The autocomplete tests expect nothing more than 'Main Page' to exist, so
			// no other setup is necessary.
			self::apiCall( [ 'action' => 'cirrus-suggest-index' ] );
			$initializedSuggester = true;
		}
	}

	public static function somethingProvider() {
		return [
			"full text search click through" => [
				[
					self::visitPage( "Special:Search?search=main" ),
					self::clickSearchResult( 0 ),
				],
				[
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
					[ 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ],
				],
			],
			"full text search ctrl-click through" => [
				[
					self::visitPage( "Special:Search?search=main" ),
					self::ctrlClickSearchResult( 0 ),
				],
				[
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
					[ 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ],
				],
			],
			"full text search click through, back, click different result" => [
				[
					self::ensurePage( 'Something else', 'contains the word main in the content' ),
					self::visitPage( "Special:Search?search=main" ),
					self::clickSearchResult( 0 ),
					self::sleep( 2 ),
					self::clickBackButton(),
					self::clickSearchResult( 1 ),
				],
				[
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
					[ 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
					[ 'action' => 'click', 'source' => 'fulltext', 'position' => 1 ],
					[ 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 1 ],
				],
			],
			"full text search redirect click through" => [
				[
					self::ensurePage( "Redirect", "#REDIRECT [[Main Page]]" ),
					self::visitPage( "Special:Search?search=redirect&fulltext=1" ),
					self::clickRedirectSearchResult( 0 ),
				],
				[
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
					[ 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ],
				]
			],
			"full text search redirect ctrl-click through" => [
				[
					self::ensurePage( "Redirect", "#REDIRECT [[Main Page]]" ),
					self::visitPage( "Special:Search?search=redirect&fulltext=1" ),
					self::ctrlClickRedirectSearchResult( 0 ),
				],
				[
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
					[ 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ],
				]
			],
			"full text search alt title click through" => [
				[
					self::ensurePage( 'With Headings', "Something\n==Role==\nmore content" ),
					self::visitPage( "Special:Search?search=role" ),
					self::clickAltTitleSearchResult( 0 ),
				],
				[
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
					[ 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ],
				]
			],
			"full text search alt title ctrl-click through" => [
				[
					self::ensurePage( 'With Headings', "Something\n==Role==\nmore content" ),
					self::visitPage( "Special:Search?search=role" ),
					self::ctrlClickAltTitleSearchResult( 0 ),
				],
				[
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
					[ 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ],
				]
			],
			"skin autocomplete click through" => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "main" ),
					self::waitForSkinAutocomplete(),
					self::clickSkinAutocompleteResult( 0 ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
				],
			],
			"skin autocomplete via enter key" => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "main" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( "\n" ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			"skin autocomplete via click on 'containing...'" => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					// For reasons outside our control the 'containing' link doesn't
					// show up the first time we type, we have to do it twice.
					self::typeIntoSkinAutocomplete( "ma" ),
					self::typeIntoSkinAutocomplete( "in" ),
					self::waitForSkinAutocomplete(),
					self::clickSkinAutocompleteContaining(),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			"skin autocomplete via arrow up and enter on 'containing...'" => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "ma" ),
					self::typeIntoSkinAutocomplete( "in" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( WebDriverKeys::ARROW_UP . "\n" ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			"skin autocomplete via arrow down and enter" => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "main" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN . "\n" ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
				],
			],
			"skin autocomplete via arrow down and magnifying glass" => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "main" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					self::clickMagnifyingGlass(),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
				],
			],
			"skin autocomplete via typed exact match and enter" => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "Main Page" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( "\n" ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
				],
			],
			"skin autocomplete via typed exact match and magnifying glass" => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "Main Page" ),
					// the user might not do this, but it makes the test more reliable
					// to guarantee the SERP event comes in.
					self::waitForSkinAutocomplete(),
					self::clickMagnifyingGlass(),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
				],
			],
			'skin autocomplete selecting via down arrow, editing to title match, ' .
				'wait for results, and press enter' => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "Main Page" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete(
						WebDriverKeys::ARROW_DOWN .
						str_repeat( WebDriverKeys::BACKSPACE, 4 ) .
						"page"
					),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( "\n" ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
				],
			],
			'skin autocomplete selecting via down arrow, editing to title match, and press enter' => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "Main Page" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete(
						WebDriverKeys::ARROW_DOWN .
						str_repeat( WebDriverKeys::BACKSPACE, 4 ) .
						"page"
					),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( "\n" ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
				],
			],
			'skin autocomplete selecting via down arrow, editing to non-title match, and press enter' => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "Main Page" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					self::typeIntoSkinAutocomplete( str_repeat( WebDriverKeys::BACKSPACE, 4 ) ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( "\n" ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			'skin autocomplete selecting via down arrow, editing to non-title match, ' .
				'and click magnifying glass' => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "Main Page" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					self::typeIntoSkinAutocomplete( str_repeat( WebDriverKeys::BACKSPACE, 4 ) ),
					self::clickMagnifyingGlass(),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			'skin autocomplete selecting via down arrow, editing to title match, ' .
				'and click magnifying glass' => [
				// actions
				[
					self::visitPage( "Main_Page" ),
					self::typeIntoSkinAutocomplete( "Main Page" ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					self::typeIntoSkinAutocomplete( str_repeat( WebDriverKeys::BACKSPACE, 4 ) . "page" ),
					self::clickMagnifyingGlass(),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
				],
			],
			// Note that this test requires some page to exist with the text 'mani page', or the
			// did you mean will be rewritten automatically and return search results for 'main page'
			'full text search click the "did you mean" rewritten result' => [
				// actions
				[
					self::visitPage( "Special:Search?search=mani%20page" ),
					// if the button is clicked too quickly the event doesn't fire because
					// js hasn't loaded.
					self::sleep( 2 ),
					self::clickDidYouMeanRewritten(),
					self::sleep( 2 ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null,
						'inputLocation' => null, 'didYouMeanVisible' => 'autorewrite' ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null,
						'inputLocation' => 'dym-rewritten', 'didYouMeanVisible' => 'no' ],
				],
			],
			'full text search click the "did you mean" original result' => [
				// actions
				[
					self::visitPage( "Special:Search?search=mani%20page" ),
					// if the button is clicked too quickly the event doesn't fire because
					// js hasn't loaded.
					self::sleep( 2 ),
					self::clickDidYouMeanOriginal(),
					self::sleep( 2 ),
				],
				// expected events
				[
					// @TODO the did you mean should be integrated and trigger some click event
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null,
						'inputLocation' => null, 'didYouMeanVisible' => 'autorewrite' ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null,
						'inputLocation' => 'dym-original', 'didYouMeanVisible' => 'yes' ],
				],
			],
			'full text search click the "did you mean" suggestion result' => [
				// actions
				[
					self::ensurePage( "Misspelled", "main paeg" ),
					self::visitPage( "Special:Search?search=main%20paeg" ),
					// if the button is clicked too quickly the event doesn't fire because
					// js hasn't loaded.
					self::sleep( 2 ),
					self::clickDidYouMeanSuggestion(),
				],
				// expected events
				[
					// @TODO the did you mean should be integrated and trigger some click event
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null,
						'inputLocation' => null, 'didYouMeanVisible' => 'yes' ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null,
						'inputLocation' => 'dym-suggest', 'didYouMeanVisible' => 'no' ],
				],
			],
			'Special:Search bar type then enter' => [
				// actions
				[
					self::visitPage( 'Special:Search' ),
					self::typeIntoSearchAutocomplete( "main" ),
					self::waitForSearchAutocomplete(),
					self::typeIntoSearchAutocomplete( "\n" ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			'Special:Search bar type, arrow down, enter' => [
				// actions
				[
					self::visitPage( 'Special:Search' ),
					self::typeIntoSearchAutocomplete( "main" ),
					self::waitForSearchAutocomplete(),
					self::typeIntoSearchAutocomplete( WebDriverKeys::ARROW_DOWN . "\n" ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			'Special:Search bar type, click result with mouse' => [
				// actions
				[
					self::visitPage( 'Special:Search' ),
					self::typeIntoSearchAutocomplete( 'main' ),
					self::waitForSearchAutocomplete(),
					self::clickSearchAutocompleteResult( 0 ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			'full text search ctrl-click for new tab' => [
				// actions
				[
					self::visitPage( 'Special:Search?search=main' ),
					self::ctrlClickSearchResult( 0 ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
					[ 'action' => 'click', 'source' => 'fulltext', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'fulltext', 'position' => 0 ],
				],
			],
			'skin autocomplete ctrl-click result for new tab' => [
				// actions
				[
					self::visitPage( 'Special:Search' ),
					self::typeIntoSkinAutocomplete( 'main' ),
					self::waitForSkinAutocomplete(),
					self::ctrlClickSkinAutocompleteResult( 0 ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
				],
			],
			'skin autocomplete arrow down and ctrl-click result for new tab' => [
				// actions
				[
					self::visitPage( 'Special:Search' ),
					self::typeIntoSkinAutocomplete( 'main' ),
					self::waitForSkinAutocomplete(),
					self::typeIntoSkinAutocomplete( WebDriverKeys::ARROW_DOWN ),
					self::ctrlClickSkinAutocompleteResult( 0 ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
				],
			],
			// This test is a bit odd because the ctrl-click doesn't trigger a new tab,
			// it gets eaten by the ooui widget and a search is performed in the browser
			'Special:Search autocomplete ctrl-click result' => [
				// actions
				[
					self::visitPage( 'Special:Search' ),
					self::typeIntoSearchAutocomplete( 'main ' ),
					self::waitForSearchAutocomplete(),
					self::ctrlClickSearchAutocompleteResult( 0 ),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => 0 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			'skin autocomplete non-exact match ctrl-click magnifying glass for new tab' => [
				// actions
				[
					self::visitPage( 'Main_Page' ),
					self::typeIntoSkinAutocomplete( 'main' ),
					self::waitForSkinAutocomplete(),
					self::ctrlClickMagnifyingGlass(),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'searchResultPage', 'source' => 'fulltext', 'position' => null ],
				],
			],
			'skin autocomplete exact match ctrl-click magnifying glass for new tab' => [
				// actions
				[
					self::visitPage( 'Main_Page' ),
					self::typeIntoSkinAutocomplete( 'main page' ),
					self::waitForSkinAutocomplete(),
					self::ctrlClickMagnifyingGlass(),
				],
				// expected events
				[
					[ 'action' => 'searchResultPage', 'source' => 'autocomplete', 'position' => null ],
					[ 'action' => 'click', 'source' => 'autocomplete', 'position' => -1 ],
					[ 'action' => 'visitPage', 'source' => 'autocomplete', 'position' => -1 ],
				],
			],
		];
	}

	/**
	 * @dataProvider somethingProvider
	 */
	public function testSomething( array $actions, array $expectedEvents ) {
		$logPosition = $this->getEventLogPosition();
		try {
			foreach ( $actions as $action ) {
				$action( $this->webDriver );
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
			array_fill( 0, count( $wantedKeys ), null )
		);
		$finalEvents = [];
		$seen = [];
		foreach ( $actualEvents as $idx => $envelope ) {
			// Only concerned with satisfaction events.
			if ( ( $envelope['schema'] ?? '' ) !== 'SearchSatisfaction' ) {
				continue;
			}
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

			$this->assertValidEvent( $actualEvent );
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
		$sorter = static function ( $a, $b ) {
			ksort( $a );
			ksort( $b );
			return strcmp( json_encode( $a ), json_encode( $b ) );
		};
		usort( $expectedEvents, $sorter );
		usort( $finalEvents, $sorter );

		$this->assertEquals( $expectedEvents, $finalEvents, $debug );
	}

	/**
	 * @return int
	 */
	private function getEventLogPosition() {
		return strlen( file_get_contents( $this->eventLoggingPath ) );
	}

	/**
	 * @param int $prevPosition
	 * @return array[]
	 */
	private function collectEvents( $prevPosition ) {
		$log = file_get_contents( $this->eventLoggingPath );
		$events = [];
		foreach ( explode( "\n", substr( $log, $prevPosition ) ) as $line ) {
			if ( trim( $line ) ) {
				$events[] = json_decode( $line, true );
			}
		}

		return $events;
	}

	/**
	 * @param array $event
	 */
	private function assertValidEvent( array $event ) {
		$searchTokenActions = [ 'searchResultPage', 'click' ];
		if ( in_array( $event['action'], $searchTokenActions ) ) {
			$this->assertArrayHasKey( 'searchToken', $event );
			$this->assertNotNull( $event['searchToken'] );
		}
	}

	/**
	 * @param string $url
	 * @return callable
	 */
	protected static function visitPage( $url ) {
		return static function ( $webDriver ) use ( $url ) {
			$webDriver->get( self::$mwBaseUrl . $url );
		};
	}

	protected static function waitForSkinAutocomplete() {
		return static function ( $webDriver ) {
			$webDriver->wait()->until(
				WebDriverExpectedCondition::presenceOfElementLocated(
					WebDriverBy::cssSelector( '.suggestions-results a' )
				)
			);
		};
	}

	protected static function typeIntoSkinAutocomplete( $chars ) {
		return static function ( $webDriver ) use ( $chars ) {
			sleep( 1 );
			$webDriver->findElement( WebDriverBy::id( 'searchInput' ) )->sendKeys( $chars );
		};
	}

	protected static function clickSkinAutocompleteResult( $position ) {
		return static function ( $webDriver ) use ( $position ) {
			$webDriver->findElement(
				WebDriverBy::cssSelector( ".suggestions-result[rel='$position']" )
			)->click();
		};
	}

	protected static function ctrlClickSkinAutocompleteResult( $position ) {
		return static function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, WebDriverBy::cssSelector(
				".suggestions-result[rel='$position']"
			) );
		};
	}

	protected static function waitForSearchAutocomplete() {
		return static function ( $webDriver ) {
			$webDriver->wait()->until(
				WebDriverExpectedCondition::presenceOfElementLocated(
					WebDriverBy::cssSelector( '.mw-widget-searchWidget-menu a' )
				)
			);
		};
	}

	protected static function typeIntoSearchAutocomplete( $chars ) {
		return static function ( $webDriver ) use ( $chars ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				'#searchText input.oo-ui-inputWidget-input'
			) )->sendKeys( $chars );
		};
	}

	/**
	 * Shown when the original search query was run, but the
	 * search engine has a suggestion for a better query
	 * @return callable
	 */
	protected static function clickDidYouMeanSuggestion() {
		return static function ( $webDriver ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				'#mw-search-DYM-suggestion'
			) )->click();
		};
	}

	/**
	 * Shown when the rewritten search query was run. Gives
	 * the user a direct link to this search, which might show
	 * a new did you mean.
	 * @return callable
	 */
	protected static function clickDidYouMeanRewritten() {
		return static function ( $webDriver ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				'#mw-search-DYM-rewritten'
			) )->click();
		};
	}

	/**
	 * Shown when the rewritten search query was run. Gives
	 * the user a direct link to the original search without
	 * it being rewritten.
	 * @return callable
	 */
	protected static function clickDidYouMeanOriginal() {
		return static function ( $webDriver ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				'#mw-search-DYM-original'
			) )->click();
		};
	}

	protected static function clickSearchAutocompleteResult( $position ) {
		$position += 1;
		return static function ( $webDriver ) use ( $position ) {
			$webDriver->findElement(
				self::byExtendedCss( ".mw-widget-searchWidget-menu a:nth-of-type($position)" )
			)->click();
		};
	}

	protected static function ctrlClickSearchAutocompleteResult( $position ) {
		$position += 1;
		return static function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, self::byExtendedCss(
				".mw-widget-searchWidget-menu a:nth-of-type($position)"
			) );
		};
	}

	protected static function clickSearchResult( $position ) {
		return static function ( $webDriver ) use ( $position ) {
			$webDriver->findElement(
				WebDriverBy::cssSelector( "*[data-serp-pos='$position']" )
			)->click();
		};
	}

	protected static function ctrlClickSearchResult( $position ) {
		return static function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, WebDriverBy::cssSelector(
				"*[data-serp-pos='$position']"
			) );
		};
	}

	protected static function clickRedirectSearchResult( $position ) {
		return static function ( $webDriver ) use ( $position ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				"*[data-serp-pos='$position'] ~ span a.mw-redirect"
			) )->click();
		};
	}

	protected static function ctrlClickRedirectSearchResult( $position ) {
		return static function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, WebDriverBy::cssSelector(
				"*[data-serp-pos='$position'] ~ span a.mw-redirect"
			) );
		};
	}

	protected static function clickAltTitleSearchResult( $position ) {
		return static function ( $webDriver ) use ( $position ) {
			$webDriver->findElement( WebDriverBy::cssSelector(
				"[data-serp-pos='$position'] ~ span.searchalttitle a"
			) )->click();
		};
	}

	protected static function ctrlClickAltTitleSearchResult( $position ) {
		return static function ( $webDriver ) use ( $position ) {
			self::ctrlClick( $webDriver, WebDriverBy::cssSelector(
				"[data-serp-pos='$position'] ~ span.searchalttitle a"
			) );
		};
	}

	protected static function clickSkinAutocompleteContaining() {
		return static function ( $webDriver ) {
			$label = WebDriverBy::cssSelector( '.suggestions-special .special-label' );
			// If the first autocomplete query is in-flight this might not have been
			// created yet. Need to wait around for the box to show up.
			$webDriver->wait()->until(
				WebDriverExpectedCondition::presenceOfElementLocated(
					$label
				)
			);
			$webDriver->findElement( $label )->click();
		};
	}

	protected static function clickMagnifyingGlass() {
		return static function ( $webDriver ) {
			$webDriver->findElement(
				WebDriverBy::id( 'searchButton' )
			)->click();
		};
	}

	protected static function ctrlClickMagnifyingGlass() {
		return static function ( $webDriver ) {
			self::ctrlClick( $webDriver, WebDriverBy::id( 'searchButton' ) );
		};
	}

	protected static function clickBackButton() {
		return static function ( $webDriver ) {
			$webDriver->navigate()->back();
		};
	}

	protected static function sleep( $length ) {
		return static function () use ( $length ) {
			sleep( $length );
		};
	}

	protected static function getContent( $title ) {
		$url = 'http://localhost:8080/w/api.php';
		$response = self::apiCall( [
			'titles' => $title,
			'action' => 'query',
			'prop' => 'revisions',
			'rvlimit' => 1,
			'rvprop' => 'content',
		] );
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

	protected static function ensurePage( $title, $content ) {
		static $seen = [];
		return static function () use ( $title, $content, &$seen ) {
			// makes bold assumption title/content will always match
			if ( isset( $seen[$title] ) ) {
				return;
			}
			$seen[$title] = true;

			$currentContent = self::getContent( $title );
			if ( trim( $content ) === trim( $currentContent ) ) {
				return;
			}
			$response = self::apiCall( [
				'action' => 'query',
				'meta' => 'tokens',
			] );
			$response = self::apiCall( [], [
				'action' => 'edit',
				'title' => $title,
				'text' => $content,
				'summary' => 'automated WikimediaEvents browser test edit',
				'token' => $response['query']['tokens']['csrftoken'],
			] );
			// give time for jobs to work their way through
			sleep( 10 );
		};
	}

	protected static function apiCall( array $params, $postData = null ) {
		if ( $postData ) {
			$context = stream_context_create( [
				'http' => [
					'method' => 'POST',
					'header' => 'Content-type: application/x-www-form-urlencoded',
					'content' => http_build_query( $postData ),
				],
			] );
		} else {
			$context = null;
		}

		$apiUrl = str_replace( '/wiki/', '/w/api.php', self::$mwBaseUrl );
		return json_decode( file_get_contents( $apiUrl . '?' . http_build_query( $params + [
			'format' => 'json',
			'formatversion' => 2,
		] ), false, $context ), true );
	}

	/**
	 * Helper function to perform ctrl-click (open in new tab/window)
	 * @param WebDriver $webDriver
	 * @param string $webDriverBy
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
	 * @param string $selector
	 * @return string
	 */
	protected static function byExtendedCss( $selector ) {
		static $conv;
		$conv ??= new CssSelectorConverter();
		return WebDriverBy::xpath( $conv->toXPath( $selector ) );
	}

}
