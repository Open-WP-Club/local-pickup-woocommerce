<?php

final class VersionTest extends LPS_TestCase {
	public function test_runtime_asset_version_matches_the_plugin_header(): void {
		preg_match( '/^ \* Version:\s*(\S+)/m', file_get_contents( LPS_FILE ), $matches );
		$this->assertNotEmpty( $matches[1] );
		$this->assertSame( $matches[1], LPS_VERSION );
		LPS_Plugin::instance()->enqueue_checkout_assets();
		$this->assertSame( $matches[1], $GLOBALS['lps_test_scripts'][0][3] );
		$this->assertSame( $matches[1], $GLOBALS['lps_test_styles'][0][3] );
	}

	public function test_private_npm_tooling_does_not_duplicate_the_plugin_version(): void {
		$package = json_decode( file_get_contents( LPS_PATH . 'package.json' ), true );
		$lock = json_decode( file_get_contents( LPS_PATH . 'package-lock.json' ), true );
		$this->assertTrue( $package['private'] );
		$this->assertArrayNotHasKey( 'version', $package );
		$this->assertArrayNotHasKey( 'version', $lock );
		$this->assertArrayNotHasKey( 'version', $lock['packages'][''] );
		$this->assertSame( $package['devDependencies'], $lock['packages']['']['devDependencies'] );
	}
}
