<?php

namespace PV\Redirects\Tests;

use PV\Redirects\Matcher;

use function preg_match;

/**
 * Pure unit tests for the matching primitives. No WordPress state is needed; the WP
 * bootstrap is still up because the whole suite boots one install (see tests/bootstrap.php).
 */
final class MatcherTest extends TestCase {

	public static function normalizeKeyProvider(): array {
		return [
			'adds leading slash'            => [ 'old', false, '/old' ],
			'keeps leading slash'           => [ '/old/', false, '/old/' ],
			'case folds when insensitive'   => [ '/Mixed-Case/', false, '/mixed-case/' ],
			'preserves case when sensitive' => [ '/Mixed-Case/', true, '/Mixed-Case/' ],
			'keeps trailing slash as typed' => [ '/old/', true, '/old/' ],
		];
	}

	/** @dataProvider normalizeKeyProvider */
	public function testNormalizeKey( string $path, bool $case_sensitive, string $expected ): void {
		static::assertSame( $expected, Matcher::normalizeKey( $path, $case_sensitive ) );
	}

	public static function normalizeExactKeyProvider(): array {
		return [
			'no query falls back to path normalize' => [ '/Path', false, '/path' ],
			'path folded, query kept raw'           => [ '/Path/?Q=1&X=Y', false, '/path/?Q=1&X=Y' ],
			'sensitive keeps both verbatim'         => [ '/Path/?q=1', true, '/Path/?q=1' ],
			'leading slash added to path part only' => [ 'path?a=1', false, '/path?a=1' ],
			'encoded query stays untouched'         => [ '/a/?utm_source=x%20y', false, '/a/?utm_source=x%20y' ],
		];
	}

	/** @dataProvider normalizeExactKeyProvider */
	public function testNormalizeExactKey( string $key, bool $case_sensitive, string $expected ): void {
		static::assertSame( $expected, Matcher::normalizeExactKey( $key, $case_sensitive ) );
	}

	/**
	 * Golden regexes. preg_quote() with the '#' delimiter escapes '-' as well, so literals
	 * like 'old-blog' come out as 'old\-blog' (harmless: '\-' still matches '-').
	 */
	public static function wildcardGoldenProvider(): array {
		return [
			'trailing rest capture'  => [ '/old-blog/*', '#^/old\-blog/(?P<rest>.+)$#' ],
			'metachar kept literal'  => [ '/2024/*-report.html', '#^/2024/(?P<s1>[^/]+)\-report\.html$#' ],
			'two ordered captures'   => [ '/*/*-guide', '#^/(?P<s1>[^/]+)/(?P<s2>[^/]+)\-guide$#' ],
			'trailing ** collapses'  => [ '/old/**', '#^/old/(?P<rest>.+)$#' ],
			'mid ** splits into adjacent captures (** is unsupported in v1)' => [ '/a/**/b', '#^/a/(?P<s1>[^/]+)(?P<s2>[^/]+)/b$#' ],
			'bare star is the rest'  => [ '/*', '#^/(?P<rest>.+)$#' ],
			'leading slash added'    => [ 'old/*', '#^/old/(?P<rest>.+)$#' ],
			'plain segments quoted'  => [ '/a.b/c', '#^/a\.b/c$#' ],
		];
	}

	/** @dataProvider wildcardGoldenProvider */
	public function testCompileWildcardGoldenRegexes( string $pattern, string $expected_regex ): void {
		static::assertSame( $expected_regex, Matcher::compileWildcard( $pattern ) );
	}

	public function testCompiledWildcardKeepsMetacharactersLiteral(): void {
		$regex = Matcher::compileWildcard( '/2024/*-report.html' );

		static::assertSame( 0, preg_match( $regex, '/2024/x-reportXhtml' ), 'The dot must not act as a wildcard' );
		static::assertSame( 0, preg_match( $regex, '/2024/sub/dir-report.html' ), 'Mid-pattern * must not cross slashes' );
		static::assertSame( 1, preg_match( $regex, '/2024/x-report.html', $matches ) );
		static::assertSame( 'x', $matches['s1'] );
	}

	public function testCompiledWildcardQuotingBehavior(): void {
		$regex = Matcher::compileWildcard( '/a.b/c' );

		static::assertSame( 1, preg_match( $regex, '/a.b/c' ) );
		static::assertSame( 0, preg_match( $regex, '/aXb/c' ) );
	}

	public function testTrailingDoubleStarCapturesRestIncludingSlashes(): void {
		$regex = Matcher::compileWildcard( '/old/**' );

		static::assertSame( 1, preg_match( $regex, '/old/a/b', $matches ) );
		static::assertSame( 'a/b', $matches['rest'] );
	}

	public function testMatchWildcardFirstRuleWins(): void {
		$first  = [ 'regex' => Matcher::compileWildcard( '/blog/*' ), 'to' => '/first/*', 'status' => 301, 'src' => 'o' ];
		$second = [ 'regex' => Matcher::compileWildcard( '/*' ), 'to' => '/second/*', 'status' => 302, 'src' => 'o' ];

		$result = Matcher::matchWildcard( [ $first, $second ], '/blog/2024/x' );

		static::assertNotNull( $result );
		static::assertSame( $first, $result['rule'] );
		static::assertSame( [ 'rest' => '2024/x' ], $result['captures'] );
	}

	public function testMatchWildcardExtractsOrderedCaptures(): void {
		$rule = [ 'regex' => Matcher::compileWildcard( '/*/*-guide' ), 'to' => '/guides/*/*', 'status' => 301, 'src' => 'o' ];

		$result = Matcher::matchWildcard( [ $rule ], '/seo/buying-guide' );

		static::assertNotNull( $result );
		static::assertSame( [ 's1' => 'seo', 's2' => 'buying' ], $result['captures'] );
	}

	public function testMatchWildcardReturnsNullOnMiss(): void {
		$rule = [ 'regex' => Matcher::compileWildcard( '/old/*' ), 'to' => '/new/*', 'status' => 301, 'src' => 'o' ];

		static::assertNull( Matcher::matchWildcard( [ $rule ], '/other/x' ) );
		static::assertNull( Matcher::matchWildcard( [], '/old/x' ) );
	}

	public static function resolveDestinationProvider(): array {
		return [
			'schemed passthrough'             => [ 'https://ext.example/pr', [], 'http://localhost', 'https://ext.example/pr' ],
			'schemed with query passthrough'  => [ 'http://e.x/p?a=1', [], 'http://localhost', 'http://e.x/p?a=1' ],
			'leading slash gets home prefix'  => [ '/new-page/', [], 'http://localhost', 'http://localhost/new-page/' ],
			'numeric becomes home p query'    => [ '123', [], 'http://localhost', 'http://localhost/?p=123' ],
			'relative falls back to home'     => [ 'some/path', [], 'http://localhost', 'http://localhost/some/path' ],
			'star replaced by rest capture'   => [ '/new/*', [ 'rest' => 'a/b' ], 'http://localhost', 'http://localhost/new/a/b' ],
			'two stars consume in order'      => [ '/x/*/*', [ 's1' => 'a', 's2' => 'b' ], 'http://localhost', 'http://localhost/x/a/b' ],
			'leftover star stays literal'     => [ '/a/*/*', [ 's1' => 'q' ], 'http://localhost', 'http://localhost/a/q/*' ],
			'home trailing slash trimmed'     => [ '/x', [], 'http://localhost/', 'http://localhost/x' ],
		];
	}

	/** @dataProvider resolveDestinationProvider */
	public function testResolveDestination( string $to, array $captures, string $home_url, string $expected ): void {
		static::assertSame( $expected, Matcher::resolveDestination( $to, $captures, $home_url ) );
	}

	public static function appendQueryProvider(): array {
		return [
			'question mark form'      => [ '/new/', 'a=1', '/new/?a=1' ],
			'ampersand form'          => [ '/new/?x=2', 'a=1', '/new/?x=2&a=1' ],
			'empty query is a no-op'  => [ '/new/', '', '/new/' ],
			'schemed destination'     => [ 'https://e.x/p', 'a=1&b=2', 'https://e.x/p?a=1&b=2' ],
		];
	}

	/** @dataProvider appendQueryProvider */
	public function testAppendQuery( string $destination, string $query_string, string $expected ): void {
		static::assertSame( $expected, Matcher::appendQuery( $destination, $query_string ) );
	}
}
