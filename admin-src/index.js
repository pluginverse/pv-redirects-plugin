/**
 * Entry point: mounts the admin app into the #pv-redirects-app mount point that
 * Admin::renderApp() renders on the PV Redirects menu page.
 */

import { createRoot } from '@wordpress/element';
import App from './app';

const mountPoint = document.getElementById( 'pv-redirects-app' );

if ( mountPoint ) {
	createRoot( mountPoint ).render( <App /> );
}
