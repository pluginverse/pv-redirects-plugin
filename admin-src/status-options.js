/**
 * Shared redirect status choices: the admin table, the admin settings panel, and the
 * editor sidebar all present the same four codes in the same order.
 */

import { __ } from '@wordpress/i18n';

export const STATUS_OPTIONS = [
	{ value: '301', label: __( '301 (Permanent)', 'pv-redirects' ) },
	{ value: '302', label: __( '302 (Found)', 'pv-redirects' ) },
	{ value: '307', label: __( '307 (Temporary)', 'pv-redirects' ) },
	{ value: '308', label: __( '308 (Permanent)', 'pv-redirects' ) },
];

/**
 * Human label for one status code.
 *
 * @param {number} status Status code.
 * @return {string} Label, or the raw code when unknown.
 */
export function statusLabel( status ) {
	const option = STATUS_OPTIONS.find(
		( candidate ) => candidate.value === String( status )
	);

	return option ? option.label : String( status );
}
