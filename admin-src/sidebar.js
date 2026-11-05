/**
 * Editor sidebar: per-post redirect controls bound to the `_pv_redirect_*` post meta
 * through the block editor's core/editor store (getEditedPostAttribute/editPost — the
 * native meta path; no api-fetch). Meta reaches REST because Admin::registerMeta()
 * registers the three keys on every public post type.
 */

import {
	PanelRow,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel as EditPostPanel } from '@wordpress/edit-post';
import { PluginDocumentSettingPanel as EditorPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { STATUS_OPTIONS } from './status-options';

const META_TO = '_pv_redirect_to';
const META_STATUS = '_pv_redirect_status';
const META_ACTIVE = '_pv_redirect_active';

function RedirectPanel() {
	const { meta, isViewable } = useSelect( ( select ) => {
		// The Site Editor screen has no core/editor store; PHP gates the script enqueue
		// (Admin::enqueueSidebar only loads it on post screens), this cheap guard is the
		// second line: bail so the panel renders null instead of crashing.
		const editor = select( 'core/editor' );

		if ( ! editor?.getCurrentPostId ) {
			return { meta: {}, isViewable: false };
		}

		const postType = editor.getCurrentPostType();
		const typeDefinition = select( 'core' ).getPostType( postType );

		return {
			meta: editor.getEditedPostAttribute( 'meta' ) ?? {},
			// Only public (viewable) post types carry the registered meta; non-viewable
			// types have no edit screen where the meta would survive a save.
			isViewable:
				typeDefinition !== undefined &&
				typeDefinition.viewable === true,
		};
	}, [] );

	const { editPost } = useDispatch( 'core/editor' );

	if ( ! isViewable ) {
		return null;
	}

	// Resolved here rather than at module scope: on WP 6.6+ the @wordpress/editor export
	// wins without touching the deprecated @wordpress/edit-post one; 6.4/6.5 (which lack
	// the editor export) fall back to edit-post, matching the plugin's floor.
	const DocumentSettingPanel = EditorPanel ?? EditPostPanel;

	const setMeta = ( key, value ) => editPost( { meta: { [ key ]: value } } );

	return (
		<DocumentSettingPanel
			name="pv-redirects"
			title={ __( 'Redirect', 'pv-redirects' ) }
			icon="admin-links"
		>
			<PanelRow>
				<ToggleControl
					label={ __( 'Redirect this content', 'pv-redirects' ) }
					help={
						meta[ META_ACTIVE ] === '1'
							? __(
									'Visitors to this post are redirected.',
									'pv-redirects'
								)
							: __(
									'This post is served normally.',
									'pv-redirects'
								)
					}
					checked={ meta[ META_ACTIVE ] === '1' }
					onChange={ ( active ) =>
						setMeta( META_ACTIVE, active ? '1' : '' )
					}
				/>
			</PanelRow>
			<TextControl
				label={ __( 'Destination', 'pv-redirects' ) }
				help={ __(
					'Absolute URL, or a path on this site starting with /.',
					'pv-redirects'
				) }
				value={ meta[ META_TO ] ?? '' }
				onChange={ ( value ) => setMeta( META_TO, value ) }
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ __( 'Status', 'pv-redirects' ) }
				help={ __(
					'301/308 permanent; 302/307 temporary.',
					'pv-redirects'
				) }
				value={
					meta[ META_STATUS ] ? String( meta[ META_STATUS ] ) : '301'
				}
				options={ STATUS_OPTIONS }
				onChange={ ( value ) => setMeta( META_STATUS, value ) }
				__next40pxDefaultSize
			/>
		</DocumentSettingPanel>
	);
}

registerPlugin( 'pv-redirects-sidebar', {
	render: RedirectPanel,
	icon: 'admin-links',
} );
