<?php
namespace WikimediaEvents\Tests\Integration\CreateAccount;

use MediaWiki\Tests\Integration\HTMLForm\HTMLFormFieldTestCase;
use WikimediaEvents\CreateAccount\HTMLNoScriptHiddenField;

/**
 * @covers \WikimediaEvents\CreateAccount\HTMLNoScriptHiddenField
 */
class HTMLNoScriptHiddenFieldTest extends HTMLFormFieldTestCase {
	/** @var string */
	protected $className = HTMLNoScriptHiddenField::class;

	public static function provideInputHtml(): iterable {
		yield [
			'params' => [ 'name' => 'test' ],
			'value' => 'foo',
			'expected' => '<noscript><input name="test" type="hidden" value="1"></noscript>'
		];
	}

	public static function provideInputCodex(): iterable {
		yield [
			'params' => [ 'name' => 'test' ],
			'value' => 'foo',
			'hasErrors' => false,
			'expected' => '<noscript><input name="test" type="hidden" value="1"></noscript>'
		];
	}

	public static function provideInputOOUI(): iterable {
		yield [
			'params' => [ 'name' => 'test' ],
			'value' => 'foo',
			// Idiom for "use the HTML version".
			'expected' => false
		];
	}
}
