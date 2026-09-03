<?php

use PHPUnit\Framework\TestCase;

final class ConverterPublishingTest extends TestCase {

	public function test_validated_file_is_copied_to_a_new_immutable_target(): void {
		$source = tempnam( sys_get_temp_dir(), 'jmi-source-' );
		$target = $source . '.published.webp';
		file_put_contents( $source, 'validated-modern-image' );

		$converter = new JMI_Converter( new stdClass(), new stdClass(), new stdClass(), new stdClass() );
		$method    = new ReflectionMethod( JMI_Converter::class, 'publish_file' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $converter, $source, $target ) );
		$this->assertFileDoesNotExist( $source );
		$this->assertSame( 'validated-modern-image', file_get_contents( $target ) );

		wp_delete_file( $target );
	}

	public function test_matching_file_published_by_another_request_is_accepted(): void {
		$source = tempnam( sys_get_temp_dir(), 'jmi-source-' );
		$target = $source . '.published.webp';
		file_put_contents( $source, 'same-image' );
		file_put_contents( $target, 'same-image' );

		$converter = new JMI_Converter( new stdClass(), new stdClass(), new stdClass(), new stdClass() );
		$method    = new ReflectionMethod( JMI_Converter::class, 'publish_file' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $converter, $source, $target ) );
		$this->assertFileDoesNotExist( $source );
		$this->assertSame( 'same-image', file_get_contents( $target ) );

		wp_delete_file( $target );
	}

	public function test_different_existing_target_is_never_overwritten(): void {
		$source = tempnam( sys_get_temp_dir(), 'jmi-source-' );
		$target = $source . '.published.webp';
		file_put_contents( $source, 'new-image' );
		file_put_contents( $target, 'other-image' );

		$converter = new JMI_Converter( new stdClass(), new stdClass(), new stdClass(), new stdClass() );
		$method    = new ReflectionMethod( JMI_Converter::class, 'publish_file' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $converter, $source, $target ) );
		$this->assertSame( 'new-image', file_get_contents( $source ) );
		$this->assertSame( 'other-image', file_get_contents( $target ) );

		wp_delete_file( $source );
		wp_delete_file( $target );
	}

	public function test_variant_filename_does_not_inherit_a_long_source_name(): void {
		$converter = new JMI_Converter( new stdClass(), new stdClass(), new stdClass(), new stdClass() );
		$method    = new ReflectionMethod( JMI_Converter::class, 'variant_paths' );
		$method->setAccessible( true );
		$source = array(
			'path'          => 'C:\\uploads\\2026\\09\\' . str_repeat( 'a', 220 ) . '.jpg',
			'relative_path' => '2026/09/' . str_repeat( 'a', 220 ) . '.jpg',
		);

		$paths = $method->invoke( $converter, $source, '1234567890abcdef', 'webp' );

		$this->assertSame( 'jmi-1234567890abcdef.webp', basename( $paths['absolute'] ) );
		$this->assertSame( '2026/09/jmi-1234567890abcdef.webp', $paths['relative'] );
	}

	public function test_variant_token_changes_with_generation_profile(): void {
		$converter = new JMI_Converter( new stdClass(), new stdClass(), new stdClass(), new stdClass() );
		$method    = new ReflectionMethod( JMI_Converter::class, 'variant_token' );
		$method->setAccessible( true );
		$source = array( 'signature' => 'source-signature' );

		$standard = $method->invoke( $converter, $source, 'image/webp', 'standard:1' );
		$ultra    = $method->invoke( $converter, $source, 'image/webp', 'ultra:1' );

		$this->assertSame( 16, strlen( $standard ) );
		$this->assertNotSame( $standard, $ultra );
		$this->assertSame( $standard, $method->invoke( $converter, $source, 'image/webp', 'standard:1' ) );
	}
}
