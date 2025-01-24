<?php

namespace WikimediaEvents\Tests;

/**
 * @coversNothing
 */
class OwnersStructureTest extends \PHPUnit\Framework\TestCase {
	/** @var array */
	private static $ownerSections;

	public static function setUpBeforeClass(): void {
		// Parse the owners data and store it in an array.
		$sections = [];
		$lines = file( __DIR__ . '/../../OWNERS.md', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$section = null;
		foreach ( $lines as $line ) {
			if ( strpos( $line, '* ' ) === 0 ) {
				[ $label, $value ] = explode( ':', substr( $line, 2 ), 2 );
				if (
					$label === 'Files' ||
					$label === 'Folders' ||
					$label === 'Modules'
				) {
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

	public function testOwnersFile() {
		$expectedResourceLabels = [ 'Modules', 'Folders', 'Files' ];

		foreach ( self::$ownerSections as $title => $section ) {
			$this->assertArrayHasKey( 'Contact', $section, "OWNERS.md § $title has Contact label" );
			$this->assertArrayHasKey( 'Since', $section, "OWNERS.md § $title has Since label" );

			$actualResourceLabels = array_intersect( $expectedResourceLabels, array_keys( $section ) );

			$this->assertTrue(
				count( $actualResourceLabels ) >= 1,
				"OWNERS.md § $title has either a Files, Folders, or Modules label"
			);
		}
	}

	/**
	 * @depends testOwnersFile
	 */
	public function testResourcesAreOwned() {
		$ownedModules = [];
		$ownedFolders = [];
		$ownedFiles = [];

		foreach ( self::$ownerSections as $section ) {
			if ( isset( $section['Modules'] ) ) {
				$ownedModules = array_merge( $ownedModules, array_fill_keys( $section['Modules'], true ) );
			}

			if ( isset( $section['Folders'] ) ) {
				foreach ( $section['Folders'] as $folder ) {
					if ( !str_starts_with( $folder, 'modules' ) ) {
						$folder = 'modules/' . $folder;
					}

					$ownedFolders[] = $folder;
				}
			}

			if ( isset( $section['Files'] ) ) {
				$ownedFiles = array_merge( $ownedFiles, $section['Files'] );
			}
		}

		$extension = json_decode(
			file_get_contents( __DIR__ . '/../../extension.json' ),
			JSON_OBJECT_AS_ARRAY
		);
		$modules = $extension['ResourceModules'];
		foreach ( $modules as $moduleName => $moduleInfo ) {
			// #1: Is the module owned?
			if ( isset( $ownedModules[ $moduleName ] ) ) {
				continue;
			}

			foreach ( $moduleInfo['packageFiles'] as $entry ) {
				$name = $entry;

				// Skip index.js
				if ( is_array( $name ) ) {
					if ( !isset( $name['name'] ) ) {
						continue;
					}

					$name = $name['name'];
				}

				if ( !is_string( $name ) || !str_ends_with( $name, '.js' ) || $name === 'index.js' ) {
					continue;
				}

				// $relativePath is the path to the file relative to the project root.
				$relativePath = $moduleInfo['localBasePath'] . '/' . $name;

				// #2: Is the resource in an owned folder?
				$found = false;

				foreach ( $ownedFolders as $ownedFolder ) {
					if ( str_starts_with( $relativePath, $ownedFolder ) ) {
						$found = true;

						break;
					}
				}

				// #3: Finally, is the resource an owned file?
				if ( !$found ) {
					foreach ( $ownedFiles as $ownedFile ) {
						if ( str_ends_with( $relativePath, $ownedFile ) ) {
							$found = true;

							break;
						}
					}
				}

				if ( !$found ) {
					$this->fail( "Resource $relativePath ($moduleName) isn't document as owned in OWNERS.md" );
				}
			}
		}

		$this->assertTrue( true, 'OWNERS.md documents ownership of all resources' );
	}
}
