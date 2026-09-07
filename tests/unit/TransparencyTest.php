<?php

use PHPUnit\Framework\TestCase;

final class TransparencyTest extends TestCase {

	public function test_transparent_png_is_detected(): void {
		$path = $this->fixture_path(
			'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAGElEQVR4nGP4z8DAwPCf4T8LI8P/BhAHADONBYGvJC9HAAAAAElFTkSuQmCC'
		);

		$this->assertTrue( JMI_Transparency::has_transparency( $path, 'image/png' ) );

		wp_delete_file( $path );
	}

	public function test_opaque_png_is_detected_without_decoding_every_pixel(): void {
		$path = $this->fixture_path(
			'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAE0lEQVR4nGP8//8/AwMDEwMYAAAkBgMBXaJOiAAAAABJRU5ErkJggg=='
		);

		$this->assertFalse( JMI_Transparency::has_transparency( $path, 'image/png' ) );

		wp_delete_file( $path );
	}

	public function test_converter_rejects_an_output_that_lost_alpha(): void {
		$path = $this->fixture_path(
			'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAE0lEQVR4nGP8//8/AwMDEwMYAAAkBgMBXaJOiAAAAABJRU5ErkJggg=='
		);
		$capabilities = new JMI_Test_Transparency_Capabilities();
		$converter    = new JMI_Converter( new stdClass(), $capabilities, new stdClass(), new stdClass() );
		$method       = new ReflectionMethod( JMI_Converter::class, 'validate_output' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$converter,
			$path,
			'image/png',
			array(
				'width'            => 2,
				'height'           => 2,
				'bytes'            => wp_filesize( $path ) + 100,
				'has_transparency' => true,
			)
		);

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'alpha_lost', $result['reason'] );
		$this->assertSame( array( 'image/png', 'alpha_lost' ), $capabilities->last_failure );

		wp_delete_file( $path );
	}

	private function fixture_path( $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'jmi-alpha-' );
		file_put_contents( $path, base64_decode( $contents ) );

		return $path;
	}
}

final class JMI_Test_Transparency_Capabilities {

	public $last_failure = array();

	public function record_transparency_failure( $mime_type, $reason ): void {
		$this->last_failure = array( $mime_type, $reason );
	}
}
