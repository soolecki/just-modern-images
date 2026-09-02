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
