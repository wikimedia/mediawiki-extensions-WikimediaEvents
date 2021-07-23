<?php

namespace WikimediaEvents\Tests;

use LogicException;

/**
 * @coversNothing
 */
class OwnersStructureTest extends \PHPUnit\Framework\TestCase {
	/** @var array */
	private static $ownerSections;

	public static function setUpBeforeClass(): void {
		// Parse the owners data and store it in an array.
		$sections = [];
		$lines = file( __DIR__ . '/../../OWNERS.md',  FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$section = null;
		foreach ( $lines as $line ) {
			if ( strpos( $line, '* ' ) === 0 ) {
				list( $label, $value ) = explode( ':', substr( $line, 2 ), 2 );
				if ( $label === 'Files' ) {
					$section[$label] = array_map( 'trim', explode( ',', $value ) );
				} else {
					$section[$label] = trim( $value );
				}
			}
			if ( strpos( $line, '## ' ) === 0 ) {
				if ( $section !== null ) {
					// Commit previous section
					$sections[ $section['title'] ] = $section;
				}
				$section = [ 'title' => trim( substr( $line, 2 ) ) ];
			}
		}
		if ( $section !== null ) {
			// Commit last section
			$sections[ $section['title'] ] = $section;
			$section = null;
		}
		self::$ownerSections = $sections;
	}

	private function getResources(): array {
		$extension = json_decode(
			file_get_contents( __DIR__ . '/../../extension.json' ),
			JSON_OBJECT_AS_ARRAY
		);
		$modules = $extension['ResourceModules'];
		$resources = [];
		foreach ( $modules as $moduleName => $moduleInfo ) {
			foreach ( $moduleInfo as $key => $value ) {
				$files = [];
				switch ( $key ) {
					case 'packageFiles':
						foreach ( $value as $entry ) {
							if ( is_string( $entry ) && $entry !== 'index.js' ) {
								$files[] = $entry;
							}
							if (
								is_array( $entry ) &&
								isset( $entry['name'] ) &&
								str_ends_with( $entry['name'], '.js' )
							) {
								$files[] = $entry['name'];
							}
						}
						break;
					case 'localBasePath':
					case 'remoteExtPath':
					case 'dependencies':
					case 'targets':
						// ignore
						break;
					default:
						throw new LogicException( "Unknown module info key: {$key}" );
				}
				foreach ( $files as $file ) {
					$resources[ basename( $file ) ] = $moduleName;
				}
			}
		}
		return $resources;
	}

	public function testOwnersFile() {
		$expected = [ 'Files', 'Contact', 'Since' ];
		foreach ( self::$ownerSections as $title => $section ) {
			$actual = array_intersect( $expected, array_keys( $section ) );
			$this->assertSame( $expected, $actual, "Labels under OWNERS.md § $title" );
		}
	}

	/**
	 * @depends testOwnersFile
	 */
	public function testResourceOwnersFile() {
		$ownedFiles = [];
		foreach ( self::$ownerSections as $section ) {
			foreach ( $section['Files'] as $file ) {
				$ownedFiles[] = $file;
			}
		}

		$resources = $this->getResources();
		foreach ( $resources as $file => $moduleName ) {
			$this->assertContains(
				$file, $ownedFiles,
				"File $file in $moduleName has an owner"
			);
		}
		foreach ( $ownedFiles as $file ) {
			$this->assertTrue(
				isset( $resources[$file] ),
				"Owned file $file is a registered resource"
			);
		}
	}
}
