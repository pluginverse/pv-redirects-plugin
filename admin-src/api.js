/**
 * REST client for the `pv-redirects/v1` namespace.
 *
 * Error responses are standard WP_Error JSON — top-level `code`, `message`, `data` — so
 * api-fetch rejects with the parsed error and `error.message` is the human message.
 * Success bodies are `{ success, data }` and the `data` payload is unwrapped here.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const NAMESPACE = '/pv-redirects/v1';
const PER_PAGE = 20;

/**
 * Perform one REST request and unwrap the `{ success, data }` envelope.
 *
 * @param {string}           path    Path below the namespace, e.g. `/redirects`.
 * @param {Object|undefined} options Extra api-fetch options (method, data).
 * @return {Promise<Object>} The response's `data` payload.
 */
async function request( path, options = {} ) {
	const body = await apiFetch( {
		...options,
		path: NAMESPACE + path,
	} );

	if ( ! body || body.success !== true ) {
		throw new Error(
			body?.message ?? __( 'Request failed.', 'pv-redirects' )
		);
	}

	return body.data;
}

/**
 * List redirects (one page). Rows are keyed by request path.
 *
 * @param {number} page 1-based page number.
 * @return {Promise<Object>} Paged data: `redirects` keyed by request path, plus `total`, `page`, and `per_page`.
 */
export function listRedirects( page ) {
	return request( `/redirects?page=${ page }&per_page=${ PER_PAGE }` );
}

/**
 * Create one redirect.
 *
 * @param {string} requestPath Request path (site path starting with `/`).
 * @param {string} to          Destination.
 * @param {number} status      HTTP status (301/302/307/308).
 */
export function createRedirect( requestPath, to, status ) {
	return request( '/redirects', {
		method: 'POST',
		data: { request: requestPath, to, status },
	} );
}

/**
 * Update one redirect by request key. The key is URL-encoded for the route regex; the
 * controller rawurldecodes it on the other side.
 *
 * @param {string} requestPath Request path.
 * @param {string} to          Destination.
 * @param {number} status      HTTP status.
 */
export function updateRedirect( requestPath, to, status ) {
	return request( `/redirects/${ encodeURIComponent( requestPath ) }`, {
		method: 'PUT',
		data: { to, status },
	} );
}

/**
 * Delete one redirect by request key.
 *
 * @param {string} requestPath Request path.
 */
export function deleteRedirect( requestPath ) {
	return request( `/redirects/${ encodeURIComponent( requestPath ) }`, {
		method: 'DELETE',
	} );
}

/**
 * @return {Promise<Object>} The settings object.
 */
export function getSettings() {
	return request( '/settings' );
}

/**
 * Save settings (the server merges the partial object and rebuilds the map).
 *
 * @param {Object} settings Settings object.
 * @return {Promise<Object>} The saved settings object.
 */
export function saveSettings( settings ) {
	return request( '/settings', { method: 'POST', data: { settings } } );
}
