<?php

define( 'ABSPATH', dirname(__DIR__, 4) . '/' );

const WP_TESTS_DOMAIN = 'localhost';
const WP_TESTS_EMAIL  = 'admin@localhost';
const WP_TESTS_TITLE  = 'Unit Test Site';
const WP_PHP_BINARY   = 'php';

// ** Database settings - You can get this info from your web host ** //

/** The name of the database for WordPress */

define( 'DB_NAME', getenv('WORDPRESS_DB_NAME', 'wordpress') );


/** Database username */

define( 'DB_USER', getenv('WORDPRESS_DB_USER', 'example username') );


/** Database password */

define( 'DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD', 'example password') );


/**

 * Docker image fallback values above are sourced from the official WordPress installation wizard:

 * https://github.com/WordPress/WordPress/blob/1356f6537220ffdc32b9dad2a6cdbe2d010b7a88/wp-admin/setup-config.php#L224-L238

 * (However, using "example username" and "example password" in your database is strongly discouraged.  Please use strong, random credentials!)

 */


/** Database hostname */

define( 'DB_HOST', getenv('WORDPRESS_DB_HOST', 'mysql') );


/** Database charset to use in creating database tables. */

define( 'DB_CHARSET', getenv('WORDPRESS_DB_CHARSET', 'utf8') );


/** The database collate type. Don't change this if in doubt. */

define( 'DB_COLLATE', getenv('WORDPRESS_DB_COLLATE', '') );

$table_prefix = 'wp_';
