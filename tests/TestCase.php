<?php

namespace PV\Redirects\Tests;

use PV\Redirects\RedirectHandler;

use function delete_option;
use function update_option;

/**
 * Thrown by the send-trap filter: send() calls wp_redirect()+exit(), so handler tests
 * observe a send attempt by throwing from the last-chance destination filter instead.
 */
final class SendTrap extends \RuntimeException {
}

/**
 * Shared test base: clean canonical stores between tests, plus helpers for crafting maps
 * and reaching RedirectHandler's private static methods.
 */
abstract class TestCase extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		delete_option( 'pv_redirects' );
		delete_option( 'pv_redirects_map' );
		delete_option( 'pv_redirects_settings' );
	}

	/**
	 * A minimal but structurally valid computed map (schema v1) for handler-level tests.
	 *
	 * @param array<string, array<string, mixed>>               $exact
	 * @param array<int, array<string, mixed>>                  $wildcards
	 * @param array<int|string, array<string, mixed>>           $posts
	 * @param array{enabled: bool, case_sensitive: bool, preserve_qs: bool, default_status: int}|null $settings
	 */
	protected static function makeMap( array $exact = [], array $wildcards = [], array $posts = [], ?array $settings = null ): array {
		return [
			'v'         => 1,
			'built'     => 1700000000,
			'settings'  => $settings ?? self::defaultSettings(),
			'exact'     => $exact,
			'wildcards' => $wildcards,
			'posts'     => $posts,
		];
	}

	/**
	 * @return array{enabled: bool, case_sensitive: bool, preserve_qs: bool, default_status: int}
	 */
	protected static function defaultSettings(): array {
		return [
			'enabled'        => true,
			'case_sensitive' => false,
			'preserve_qs'    => true,
			'default_status' => 301,
		];
	}

	protected function seedMap( array $map ): void {
		update_option( 'pv_redirects_map', $map );
	}

	/**
	 * Invoke a private static RedirectHandler method via reflection; no visibility is widened.
	 *
	 * @param  array<int, mixed>  $args
	 */
	protected static function invokeHandler( string $method, array $args ): mixed {
		$ref = new \ReflectionMethod( RedirectHandler::class, $method );
		$ref->setAccessible( true );

		return $ref->invoke( null, ...$args );
	}
}
