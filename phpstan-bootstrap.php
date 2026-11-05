<?php
/**
 * phpstan bootstrap: defines the runtime constants the plugin root file creates, so
 * static analysis sees them in src/ (they exist only after the bootstrap runs).
 */

define( 'PV_REDIRECTS_VERSION', '1.0.0' );
define( 'PV_REDIRECTS_PATH', __DIR__.'/' );
define( 'PV_REDIRECTS_URL', 'http://example.org/wp-content/plugins/pv-redirects-plugin/' );
