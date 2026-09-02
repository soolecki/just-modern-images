<?php

use PHPUnit\Framework\TestCase;

final class ManifestRetentionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_manifests'] = array();
		$GLOBALS['jmi_test_post_meta'] = array();
	}

	public function test_replaced_variant_is_retained_for_cached_markup(): void {
		$manifest = new JMI_Manifest();
		$previous = $this->manifest_with_variant( '2026/old.jmi-111.webp' );
		$next     = $this->manifest_with_variant( '2026/new.jmi-222.webp' );

		$prepared = $manifest->prepare_replacement( $previous, $next, 12 );

		$this->assertArrayHasKey( '2026/old.jmi-111.webp', $prepared['retired'] );
		$this->assertArrayNotHasKey( '2026/new.jmi-222.webp', $prepared['retired'] );
		$this->assertGreaterThan( time(), $prepared['retired']['2026/old.jmi-111.webp'] );
	}

	public function test_expired_retired_variant_is_deleted(): void {
		$manifest      = new JMI_Manifest();
		$relative_path = 'jmi-test-' . uniqid( '', true ) . '.webp';
		$absolute_path = trailingslashit( sys_get_temp_dir() ) . $relative_path;
		file_put_contents( $absolute_path, 'retired' );

		$data            = $manifest->empty_manifest();
		$data['retired'] = array( $relative_path => time() - 1 );
		$manifest->save( 44, $data );

		$this->assertSame( 1, $manifest->cleanup_retired_variants( 44 ) );
		$this->assertFileDoesNotExist( $absolute_path );
		$this->assertSame( array(), $manifest->get( 44 )['retired'] );
	}

	private function manifest_with_variant( string $relative_path ): array {
		return array(
			'schema'             => JMI_Manifest::SCHEMA_VERSION,
			'generation_profile' => 'standard:1',
			'updated_at'         => time(),
			'retired'            => array(),
			'sources'            => array(
				'full' => array(
					'variants' => array(
						'image/webp' => array(
							'status'        => 'ready',
							'relative_path' => $relative_path,
						),
					),
				),
			),
		);
	}
}
