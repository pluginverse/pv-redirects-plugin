<?php

namespace PV\Redirects\Tests;

use PV\Redirects\Matcher;
use PV\Redirects\RedirectHandler;

use function add_action;
use function add_filter;
use function get_option;
use function remove_action;
use function remove_filter;
use function update_option;

/**
 * Front-end handler tests. send() calls wp_redirect()+exit(), so the suite never drives a
 * real matched request end-to-end; instead the send path is observed by throwing a
 * SendTrap from the last-chance pv/redirects/destination filter (which fires before
 * wp_redirect), and the guard paths assert the trap never fires. Private statics are
 * reached via reflection (see TestCase::invokeHandler).
 */
final class RedirectHandlerTest extends TestCase {

	/** @var array<string, string|null> */
	private array $server_backup = [];

	protected function setUp(): void {
		parent::setUp();

		$this->server_backup = [
			'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
			'HTTP_HOST'   => $_SERVER['HTTP_HOST'] ?? null,
		];
		unset( $_GET['action'], $_GET['rest_route'] );
	}

	protected function tearDown(): void {
		foreach ( $this->server_backup as $key => $value ) {
			if ( $value === null ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		unset( $_GET['action'], $_GET['rest_route'] );

		parent::tearDown();
	}

	/**
	 * Install the send trap plus a probe on the redirect action. Returns a cleanup closure
	 * the test must call in a finally block. $probe records [request, rule] pairs in the
	 * order the action fired.
	 *
	 * @param  array<int, array<int, mixed>>  $probe
	 *
	 * @return callable(): void
	 */
	private function trapSend( array &$probe ): callable {
		$probe = [];

		$action = static function ( string $request, array $rule ) use ( &$probe ): void {
			$probe[] = [ $request, $rule ];
		};
		add_action( 'pv/redirects/redirect', $action, 10, 2 );

		$filter = static function ( string $url ): string {
			throw new SendTrap( 'pv-redirect-send: ' . $url );
		};
		add_filter( 'pv/redirects/destination', $filter, 10, 1 );

		return static function () use ( $action, $filter ): void {
			remove_action( 'pv/redirects/redirect', $action, 10 );
			remove_filter( 'pv/redirects/destination', $filter, 10 );
		};
	}

	private static function customSettings( bool $enabled, bool $case_sensitive = false, bool $preserve_qs = true ): array {
		return [
			'enabled'        => $enabled,
			'case_sensitive' => $case_sensitive,
			'preserve_qs'    => $preserve_qs,
			'default_status' => 301,
		];
	}

	// ---------------------------------------------------------------- currentRequest

	public function testCurrentRequestSplitsPathAndQuery(): void {
		$_SERVER['REQUEST_URI'] = '/old-page/?utm=1';
		$_SERVER['HTTP_HOST']   = 'localhost';

		static::assertSame( [ '/old-page/', 'utm=1' ], self::invokeHandler( 'currentRequest', [] ) );
	}

	public function testCurrentRequestDefaultsWhenServerVarsMissing(): void {
		unset( $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'] );

		static::assertSame( [ '/', '' ], self::invokeHandler( 'currentRequest', [] ) );
	}

	public function testCurrentRequestStripsHomeSubdirectory(): void {
		$home    = get_option( 'home' );
		$siteurl = get_option( 'siteurl' );
		update_option( 'home', 'http://localhost/blog' );
		update_option( 'siteurl', 'http://localhost/blog' );

		try {
			$_SERVER['REQUEST_URI'] = '/blog/x/?q=1';
			$_SERVER['HTTP_HOST']   = 'localhost';

			static::assertSame( [ '/x/', 'q=1' ], self::invokeHandler( 'currentRequest', [] ) );
		} finally {
			update_option( 'home', $home );
			update_option( 'siteurl', $siteurl );
		}
	}

	// ---------------------------------------------------------------- matchPath

	public function testSendUsesComputedDestinationAndRetainsOriginalRuleForHooks(): void {
		$rule = [ 'to' => '/middle', 'resolved_to' => '/end', 'status' => 301, 'src' => 'o' ];
		$probe = [];
		$cleanup = $this->trapSend( $probe );
		try {
			try {
				self::invokeHandler( 'send', [ self::makeMap(), '/first', 'x=1', $rule, [] ] );
				static::fail( 'Expected redirect trap' );
			} catch ( SendTrap $exception ) {
				static::assertSame( 'pv-redirect-send: '.home_url().'/end?x=1', $exception->getMessage() );
			}
			static::assertSame( '/middle', $probe[0][1]['to'] );
		} finally {
			$cleanup();
		}
	}

	public function testMatchPathHitsQueryEmbeddedExactKey(): void {
		$rule = [ 'to' => '/qs-target/', 'status' => 301, 'src' => 'o' ];
		$map  = self::makeMap( [ '/path/?q=1' => $rule ] );

		static::assertSame(
			[ 'rule' => $rule, 'captures' => [] ],
			self::invokeHandler( 'matchPath', [ $map, '/path/', 'q=1' ] )
		);
	}

	public function testMatchPathQueryFollowsCaseSensitivity(): void {
		$rule = [ 'to' => '/qs-target/', 'status' => 301, 'src' => 'o' ];
		$map  = self::makeMap( [ '/path/?q=1' => $rule ] );

		static::assertNotNull( self::invokeHandler( 'matchPath', [ $map, '/path/', 'Q=1' ] ) );
		$sensitive = self::makeMap( [ '/path/?q=1' => $rule ], [], [], self::customSettings( true, true ) );
		static::assertNull( self::invokeHandler( 'matchPath', [ $sensitive, '/path/', 'Q=1' ] ) );
	}

	public function testMatchPathHitsPlainPath(): void {
		$rule = [ 'to' => '/target/', 'status' => 302, 'src' => 'o' ];
		$map  = self::makeMap( [ '/path/' => $rule ] );

		static::assertSame(
			[ 'rule' => $rule, 'captures' => [] ],
			self::invokeHandler( 'matchPath', [ $map, '/path/', '' ] )
		);
	}

	public function testMatchPathTogglesTrailingSlashBothWays(): void {
		$slashed   = [ 'to' => '/slashed/', 'status' => 301, 'src' => 'o' ];
		$unslashed = [ 'to' => '/unslashed/', 'status' => 301, 'src' => 'o' ];

		$with_slashed_key = self::makeMap( [ '/path/' => $slashed ] );

		static::assertSame(
			$slashed,
			self::invokeHandler( 'matchPath', [ $with_slashed_key, '/path', '' ] )['rule'],
			'A request without the trailing slash finds the slashed key'
		);

		$with_unslashed_key = self::makeMap( [ '/path' => $unslashed ] );

		static::assertSame(
			$unslashed,
			self::invokeHandler( 'matchPath', [ $with_unslashed_key, '/path/', '' ] )['rule'],
			'A request with the trailing slash finds the unslashed key'
		);
	}

	public function testMatchPathPrefersPlainHitOverToggledHit(): void {
		$plain   = [ 'to' => '/plain/', 'status' => 301, 'src' => 'o' ];
		$toggled = [ 'to' => '/toggled/', 'status' => 301, 'src' => 'o' ];
		$map     = self::makeMap( [ '/path/' => $toggled, '/path' => $plain ] );

		static::assertSame( $plain, self::invokeHandler( 'matchPath', [ $map, '/path', '' ] )['rule'] );
	}

	public function testMatchPathCaseFoldingFollowsSettings(): void {
		$rule = [ 'to' => '/t/', 'status' => 301, 'src' => 'o' ];

		// A real map stores build-time-normalized keys, so the insensitive map holds the
		// folded key while the sensitive map holds the user's casing.
		$insensitive = self::makeMap( [ '/old-page/' => $rule ] );

		static::assertSame(
			$rule,
			self::invokeHandler( 'matchPath', [ $insensitive, '/old-page/', '' ] )['rule']
		);
		static::assertSame(
			$rule,
			self::invokeHandler( 'matchPath', [ $insensitive, '/OLD-PAGE/', '' ] )['rule'],
			'Request paths fold when case_sensitive is off'
		);

		$sensitive = self::makeMap( [ '/Old-Page/' => $rule ], [], [], self::customSettings( true, true ) );

		static::assertNull( self::invokeHandler( 'matchPath', [ $sensitive, '/old-page/', '' ] ) );
		static::assertNotNull( self::invokeHandler( 'matchPath', [ $sensitive, '/Old-Page/', '' ] ) );
	}

	public function testLegacyMapMatchesQueryCaseInsensitively(): void {
		$rule = [ 'to' => '/target', 'status' => 301, 'src' => 'o' ];
		$map = self::makeMap( [ '/old/?route=One' => $rule ] );
		$map['v'] = 1;
		static::assertSame( $rule, self::invokeHandler( 'matchPath', [ $map, '/old/', 'route=one' ] )['rule'] );
	}

	public function testMatchPathExactBeatsWildcard(): void {
		$exact = [ 'to' => '/exact/', 'status' => 301, 'src' => 'o' ];
		$wild  = [ 'regex' => Matcher::compileWildcard( '/old/*' ), 'to' => '/wild/*', 'status' => 302, 'src' => 'o' ];
		$map   = self::makeMap( [ '/old/x/' => $exact ], [ $wild ] );

		static::assertSame( $exact, self::invokeHandler( 'matchPath', [ $map, '/old/x/', '' ] )['rule'] );

		$wildcard_match = self::invokeHandler( 'matchPath', [ $map, '/old/other/', '' ] );

		static::assertSame( $wild, $wildcard_match['rule'] );
		static::assertSame( [ 'rest' => 'other/' ], $wildcard_match['captures'] );
	}

	public function testMatchPathFirstWildcardWins(): void {
		$first  = [ 'regex' => Matcher::compileWildcard( '/old/*' ), 'to' => '/first/*', 'status' => 301, 'src' => 'o' ];
		$second = [ 'regex' => Matcher::compileWildcard( '/old/*' ), 'to' => '/second/*', 'status' => 302, 'src' => 'o' ];
		$map    = self::makeMap( [], [ $first, $second ] );

		static::assertSame( $first, self::invokeHandler( 'matchPath', [ $map, '/old/a/', '' ] )['rule'] );
	}

	public function testMatchPathWildcardToleratesTrailingSlash(): void {
		$wild = [ 'regex' => Matcher::compileWildcard( '/wp*' ), 'to' => '/captured/', 'status' => 301, 'src' => 'o' ];
		$map  = self::makeMap( [], [ $wild ] );

		$direct = self::invokeHandler( 'matchPath', [ $map, '/wp-foo', '' ] );

		static::assertNotNull( $direct );
		static::assertSame( $wild, $direct['rule'] );
		static::assertSame( [ 's1' => '-foo' ], $direct['captures'] );

		// Trailing-slash form must hit the same rule via the toggled retry.
		$toggled_match = self::invokeHandler( 'matchPath', [ $map, '/wp-foo/', '' ] );

		static::assertNotNull( $toggled_match );
		static::assertSame( $wild, $toggled_match['rule'] );
		static::assertSame( [ 's1' => '-foo' ], $toggled_match['captures'] );
	}

	public function testMatchPathMissReturnsNull(): void {
		static::assertNull( self::invokeHandler( 'matchPath', [ self::makeMap(), '/nothing/', '' ] ) );
	}

	// ---------------------------------------------------------------- init (guards)

	public function testInitBypassParamSkipsMatching(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );
		$_GET['action']         = 'no-redirect';
		$_SERVER['REQUEST_URI'] = '/old/';
		$_SERVER['HTTP_HOST']   = 'localhost';

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::init();

			static::assertSame( [], $probe, 'The no-redirect bypass must skip matching entirely' );
		} finally {
			$cleanup();
		}
	}

	public function testInitDisabledSettingSkipsMatching(): void {
		$this->seedMap( self::makeMap(
			[ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ],
			[],
			[],
			self::customSettings( false )
		) );
		$_SERVER['REQUEST_URI'] = '/old/';
		$_SERVER['HTTP_HOST']   = 'localhost';

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::init();

			static::assertSame( [], $probe, 'enabled=false must disable matching' );
		} finally {
			$cleanup();
		}
	}

	public function testInitEmptyMapSkipsMatching(): void {
		$_SERVER['REQUEST_URI'] = '/old/';
		$_SERVER['HTTP_HOST']   = 'localhost';

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::init();

			static::assertSame( [], $probe, 'An absent map must skip matching' );
		} finally {
			$cleanup();
		}
	}

	public function testInitSkipsWpJsonPathPrefix(): void {
		// REST_REQUEST is not defined until parse_request (after init), so the /wp-json/
		// path prefix is the guard: a '/wp*' wildcard must never 301 a REST route.
		$this->seedMap( self::makeMap( [], [
			[ 'regex' => Matcher::compileWildcard( '/wp*' ), 'to' => '/caught/', 'status' => 301, 'src' => 'o' ],
		] ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$_SERVER['HTTP_HOST']   = 'localhost';

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::init();

			static::assertSame( [], $probe, 'A /wp-json/ request must never match' );
		} finally {
			$cleanup();
		}
	}

	public function testInitSkipsRestRouteQueryParam(): void {
		// ?rest_route= requests bypass rewrite parsing, so a plain-path rule would still
		// match — the rest_route param check is the guard for that shape.
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );
		$_SERVER['REQUEST_URI'] = '/old/';
		$_SERVER['HTTP_HOST']   = 'localhost';
		$_GET['rest_route']     = '/wp/v2/posts';

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::init();

			static::assertSame( [], $probe, 'A ?rest_route= request must never match' );
		} finally {
			$cleanup();

			unset( $_GET['rest_route'] );
		}
	}

	// ---------------------------------------------------------------- init (send path)

	public function testInitSendsExactMatchWithResolvedDestinationAndQuery(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/?src=map', 'status' => 302, 'src' => 'o' ] ] ) );
		$_SERVER['REQUEST_URI'] = '/old/?utm=1';
		$_SERVER['HTTP_HOST']   = 'localhost';

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::init();
			static::fail( 'A matching request must attempt a redirect' );
		} catch ( SendTrap $trap ) {
			static::assertSame( 'pv-redirect-send: http://localhost/new/?src=map&utm=1', $trap->getMessage() );
			static::assertSame( '/old/', $probe[0][0] ?? null, 'The redirect action must fire with the request path' );
			static::assertSame( 302, $probe[0][1]['status'] ?? null, 'The redirect action must fire with the rule' );
		} finally {
			$cleanup();
		}
	}

	public function testInitSendsWildcardMatchWithCaptureSubstitution(): void {
		$this->seedMap( self::makeMap( [], [
			[ 'regex' => Matcher::compileWildcard( '/old-blog/*' ), 'to' => '/new-blog/*', 'status' => 301, 'src' => 'o' ],
		] ) );
		$_SERVER['REQUEST_URI'] = '/old-blog/2024/hello';
		$_SERVER['HTTP_HOST']   = 'localhost';

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::init();
			static::fail( 'A wildcard hit must attempt a redirect' );
		} catch ( SendTrap $trap ) {
			static::assertSame( 'pv-redirect-send: http://localhost/new-blog/2024/hello', $trap->getMessage() );
		} finally {
			$cleanup();
		}
	}

	// ---------------------------------------------------------------- canonical

	public function testCanonicalReturnsOurDestinationForMatchingUrl(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );

		static::assertSame(
			'http://localhost/new/',
			RedirectHandler::canonical( 'http://example.org/wp-orig', 'http://localhost/old/' )
		);
	}

	public function testCanonicalAppendsIncomingQueryWhenPreserving(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );

		static::assertSame(
			'http://localhost/new/?utm=1',
			RedirectHandler::canonical( 'orig', 'http://localhost/old/?utm=1' )
		);
	}

	public function testCanonicalMergesQueryIntoDestinationQuery(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/?src=m', 'status' => 301, 'src' => 'o' ] ] ) );

		static::assertSame(
			'http://localhost/new/?src=m&utm=1',
			RedirectHandler::canonical( 'orig', 'http://localhost/old/?utm=1' )
		);
	}

	public function testCanonicalDropsIncomingQueryWhenPreserveQsOff(): void {
		$this->seedMap( self::makeMap(
			[ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ],
			[],
			[],
			self::customSettings( true, false, false )
		) );

		static::assertSame(
			'http://localhost/new/',
			RedirectHandler::canonical( 'orig', 'http://localhost/old/?utm=1' )
		);
	}

	public function testCanonicalMatchesWildcards(): void {
		$this->seedMap( self::makeMap( [], [
			[ 'regex' => Matcher::compileWildcard( '/old-blog/*' ), 'to' => '/new-blog/*', 'status' => 301, 'src' => 'o' ],
		] ) );

		static::assertSame(
			'http://localhost/new-blog/2024/x',
			RedirectHandler::canonical( 'orig', 'http://localhost/old-blog/2024/x' )
		);
	}

	public function testCanonicalStripsHomeSubdirectoryFromRequestedUrl(): void {
		$home    = get_option( 'home' );
		$siteurl = get_option( 'siteurl' );
		update_option( 'home', 'http://localhost/blog' );
		update_option( 'siteurl', 'http://localhost/blog' );

		try {
			$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );

			static::assertSame(
				'http://localhost/blog/new/',
				RedirectHandler::canonical( 'orig', 'http://localhost/blog/old/' ),
				'The requested URL must be home-stripped like a live request, so subdirectory keys match'
			);
		} finally {
			update_option( 'home', $home );
			update_option( 'siteurl', $siteurl );
		}
	}

	public function testCanonicalPassesFalseAndNullThroughEvenOnMatch(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );

		static::assertFalse( RedirectHandler::canonical( false, 'http://localhost/old/' ) );
		static::assertNull( RedirectHandler::canonical( null, 'http://localhost/old/' ) );
	}

	public function testCanonicalBypassParamReturnsOriginal(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );
		$_GET['action'] = 'no-redirect';

		static::assertSame(
			'http://example.org/orig',
			RedirectHandler::canonical( 'http://example.org/orig', 'http://localhost/old/' )
		);
	}

	public function testCanonicalRecognizesSlashedBypassParameter(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );
		$_GET['action'] = 'no\\-redirect';

		static::assertSame( 'http://example.org/orig', RedirectHandler::canonical( 'http://example.org/orig', 'http://localhost/old/' ) );
	}

	public function testCanonicalWithoutMapReturnsOriginal(): void {
		static::assertSame(
			'http://example.org/orig',
			RedirectHandler::canonical( 'http://example.org/orig', 'http://localhost/old/' )
		);
	}

	public function testCanonicalDisabledReturnsOriginal(): void {
		$this->seedMap( self::makeMap(
			[ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ],
			[],
			[],
			self::customSettings( false )
		) );

		static::assertSame(
			'http://example.org/orig',
			RedirectHandler::canonical( 'http://example.org/orig', 'http://localhost/old/' )
		);
	}

	public function testCanonicalEmptyPathReturnsOriginal(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );

		static::assertSame(
			'http://example.org/orig',
			RedirectHandler::canonical( 'http://example.org/orig', 'http://localhost' )
		);
	}

	public function testCanonicalNoMatchReturnsOriginal(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );

		static::assertSame(
			'http://example.org/orig',
			RedirectHandler::canonical( 'http://example.org/orig', 'http://localhost/other/' )
		);
	}

	public function testCanonicalFiresRedirectActionAndDestinationFilter(): void {
		$this->seedMap( self::makeMap( [ '/old/' => [ 'to' => '/new/', 'status' => 301, 'src' => 'o' ] ] ) );

		$probe  = [];
		$action = static function ( string $request, array $rule ) use ( &$probe ): void {
			$probe[] = [ $request, $rule ];
		};
		add_action( 'pv/redirects/redirect', $action, 10, 2 );

		$filter = static fn ( string $url ): string => 'https://final.example/last';
		add_filter( 'pv/redirects/destination', $filter, 10, 1 );

		try {
			static::assertSame(
				'https://final.example/last',
				RedirectHandler::canonical( 'orig', 'http://localhost/old/' ),
				'The destination filter is the last-chance override'
			);
			static::assertSame( '/old/', $probe[0][0] ?? null );
			static::assertSame( 301, $probe[0][1]['status'] ?? null );
		} finally {
			remove_action( 'pv/redirects/redirect', $action, 10 );
			remove_filter( 'pv/redirects/destination', $filter, 10 );
		}
	}

	// ---------------------------------------------------------------- templateRedirect

	public function testTemplateRedirectRedirectsDriftedPost(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'drift-slug' ] );
		$this->set_permalink_structure( '/%postname%/' );

		// Simulate drift: the permalink changed after the last rebuild, so only the
		// ID-keyed posts index still knows this post.
		$this->seedMap( self::makeMap( [], [], [ $post_id => [ 'to' => '/new-home/', 'status' => 302 ] ] ) );

		$this->go_to( '/drift-slug/' );

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::templateRedirect();
			static::fail( 'A queried post present in the posts index must redirect' );
		} catch ( SendTrap $trap ) {
			static::assertSame( 'pv-redirect-send: http://localhost/new-home/', $trap->getMessage() );
			static::assertSame( '/drift-slug/', $probe[0][0] ?? null );
			static::assertSame( 302, $probe[0][1]['status'] ?? null );
		} finally {
			$cleanup();
		}
	}

	public function testTemplateRedirectBypassParamSkips(): void {
		$post_id = self::factory()->post->create( [ 'post_name' => 'bypass-slug' ] );
		$this->set_permalink_structure( '/%postname%/' );
		$this->seedMap( self::makeMap( [], [], [ $post_id => [ 'to' => '/new-home/', 'status' => 301 ] ] ) );

		$this->go_to( '/bypass-slug/' );

		// go_to() resets superglobals, so the bypass must be set after it.
		$_GET['action'] = 'no-redirect';

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::templateRedirect();

			static::assertSame( [], $probe, 'The no-redirect bypass must cover the drift fallback' );
		} finally {
			$cleanup();
		}
	}

	public function testTemplateRedirectReturnsWhenPostNotIndexed(): void {
		self::factory()->post->create( [ 'post_name' => 'plain-slug' ] );
		$this->set_permalink_structure( '/%postname%/' );
		$this->seedMap( self::makeMap() );

		$this->go_to( '/plain-slug/' );

		$probe   = [];
		$cleanup = $this->trapSend( $probe );
		try {
			RedirectHandler::templateRedirect();

			static::assertSame( [], $probe, 'A post missing from the posts index must not redirect' );
		} finally {
			$cleanup();
		}
	}
}
