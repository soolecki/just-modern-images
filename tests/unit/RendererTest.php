<?php

use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_manifests']  = array();
		$GLOBALS['jmi_test_options']    = array();
		$GLOBALS['jmi_test_post_meta']  = array();
		$GLOBALS['jmi_test_mime_types'] = array();
		$GLOBALS['jmi_test_scheduled']  = array();
	}

	public function test_it_adds_avif_and_webp_without_replacing_the_original_image(): void {
		$GLOBALS['jmi_test_manifests'][10] = $this->manifest();
		$renderer = new JMI_Renderer( new JMI_Manifest() );
		$html     = '<img src="https://cdn.example.test/2026/08/photo-300x200.jpg" srcset="https://cdn.example.test/2026/08/photo-300x200.jpg 300w, https://cdn.example.test/2026/08/photo.jpg 1200w" sizes="(max-width: 300px) 100vw, 300px" alt="Example">';

		$result = $renderer->filter_attachment_image( $html, '10', 'medium', false, 'unexpected third-party value' );

		$this->assertStringStartsWith( '<picture class="jmi-picture">', $result );
		$this->assertLessThan( strpos( $result, 'type="image/webp"' ), strpos( $result, 'type="image/avif"' ) );
		$this->assertStringContainsString( 'photo-300x200.jpg.avif 300w', $result );
		$this->assertStringContainsString( 'photo.jpg.avif 1200w', $result );
		$this->assertStringContainsString( 'photo-300x200.jpg.webp 300w', $result );
		$this->assertStringContainsString( 'src="https://cdn.example.test/2026/08/photo-300x200.jpg"', $result );
		$this->assertStringContainsString( 'data-jmi-processed="1"', $result );
	}

	public function test_it_is_idempotent(): void {
		$GLOBALS['jmi_test_manifests'][10] = $this->manifest();
		$renderer = new JMI_Renderer( new JMI_Manifest() );
		$html     = '<img src="https://example.test/wp-content/uploads/2026/08/photo.jpg" alt="Example">';

		$first  = $renderer->filter_content_image( $html, 'the_content', 10 );
		$second = $renderer->filter_content_image( $first, 'the_content', 10 );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, substr_count( $second, '<picture' ) );
	}

	public function test_it_fails_open_for_an_invalid_attachment_id(): void {
		$renderer = new JMI_Renderer( new JMI_Manifest() );
		$html     = '<img src="https://example.test/wp-content/uploads/2026/08/photo.jpg" alt="Example">';

		$this->assertSame(
			$html,
			$renderer->filter_attachment_image( $html, array( 'invalid' ), 'full', false, array() )
		);
	}

	public function test_it_only_uses_sizes_present_in_the_original_srcset(): void {
		$GLOBALS['jmi_test_manifests'][10] = $this->manifest();
		$renderer = new JMI_Renderer( new JMI_Manifest() );
		$html     = '<img src="https://example.test/wp-content/uploads/2026/08/photo-300x200.jpg" srcset="https://example.test/wp-content/uploads/2026/08/photo-300x200.jpg 300w">';

		$result = $renderer->filter_content_image( $html, 'the_content', 10 );

		$this->assertStringContainsString( 'photo-300x200.jpg.avif 300w', $result );
		$this->assertStringNotContainsString( 'photo.jpg.avif', $result );
	}

	public function test_it_omits_a_format_when_its_responsive_set_is_incomplete(): void {
		$manifest = $this->manifest();
		unset( $manifest['sources']['full']['variants']['image/avif'] );
		$GLOBALS['jmi_test_manifests'][10] = $manifest;
		$renderer = new JMI_Renderer( new JMI_Manifest() );
		$html     = '<img src="https://example.test/wp-content/uploads/2026/08/photo-300x200.jpg" srcset="https://example.test/wp-content/uploads/2026/08/photo-300x200.jpg 300w, https://example.test/wp-content/uploads/2026/08/photo.jpg 1200w">';

		$result = $renderer->filter_content_image( $html, 'the_content', 10 );

		$this->assertStringNotContainsString( 'type="image/avif"', $result );
		$this->assertStringContainsString( 'type="image/webp"', $result );
	}

	public function test_it_prioritizes_an_unprocessed_image_used_on_the_frontend(): void {
		$GLOBALS['jmi_test_mime_types'][10] = 'image/jpeg';
		$queue                              = new JMI_Queue( new stdClass(), new JMI_Quality_Profiles(), new JMI_Media_Status() );
		$renderer                           = new JMI_Renderer( new JMI_Manifest(), $queue );
		$html                               = '<img src="https://example.test/wp-content/uploads/2026/08/photo.jpg" alt="Example">';

		$this->assertSame( $html, $renderer->filter_content_image( $html, 'the_content', 10 ) );
		$queue->flush_demanded();

		$this->assertCount( 1, $GLOBALS['jmi_test_scheduled'] );
		$this->assertSame( array( 10 ), $GLOBALS['jmi_test_scheduled'][0]['args'] );
		$this->assertSame( 'demand', ( new JMI_Media_Status() )->get( 10, ( new JMI_Quality_Profiles() )->generation_profile() )['priority'] );
	}

	private function manifest(): array {
		return array(
			'schema'             => 1,
			'generation_profile' => 'v1:standard:webp-78:avif-48',
			'updated_at'         => time(),
			'sources'            => array(
				'medium' => array(
					'relative_path' => '2026/08/photo-300x200.jpg',
					'variants'      => array(
						'image/avif' => $this->variant( '2026/08/photo-300x200.jpg.avif', 'image/avif' ),
						'image/webp' => $this->variant( '2026/08/photo-300x200.jpg.webp', 'image/webp' ),
					),
				),
				'full'   => array(
					'relative_path' => '2026/08/photo.jpg',
					'variants'      => array(
						'image/avif' => $this->variant( '2026/08/photo.jpg.avif', 'image/avif' ),
						'image/webp' => $this->variant( '2026/08/photo.jpg.webp', 'image/webp' ),
					),
				),
			),
		);
	}

	private function variant( string $path, string $mime_type ): array {
		return array(
			'status'        => 'ready',
			'relative_path' => $path,
			'mime_type'     => $mime_type,
		);
	}
}
