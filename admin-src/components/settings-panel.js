/**
 * Settings panel: three toggles and the default status, saved via POST /settings
 * (the server merges the partial object and rebuilds the map).
 */

import {
	Button,
	Notice,
	PanelBody,
	PanelRow,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { getSettings, saveSettings } from '../api';
import { STATUS_OPTIONS } from '../status-options';
import StatusHelp from './status-help';

export default function SettingsPanel( { pushNotice } ) {
	const [ settings, setSettings ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );

	useEffect( () => {
		let isCurrent = true;

		getSettings()
			.then( ( data ) => {
				if ( isCurrent ) {
					setSettings( data );
				}
			} )
			.catch( ( error ) => {
				if ( isCurrent ) {
					setLoadError( error.message );
				}
			} );

		return () => {
			isCurrent = false;
		};
	}, [] );

	async function save() {
		setIsSaving( true );

		try {
			const saved = await saveSettings( {
				enabled: settings.enabled,
				case_sensitive: settings.case_sensitive,
				preserve_qs: settings.preserve_qs,
				default_status: settings.default_status,
			} );

			setSettings( saved );
			pushNotice( 'success', __( 'Settings saved.', 'pv-redirects' ) );
		} catch ( error ) {
			pushNotice( 'error', error.message );
		} finally {
			setIsSaving( false );
		}
	}

	const loadErrorMessage = sprintf(
		/* translators: %s: error message. */
		__( 'Settings could not be loaded: %s', 'pv-redirects' ),
		loadError
	);

	return (
		<PanelBody
			title={ __( 'Settings', 'pv-redirects' ) }
			initialOpen={ false }
		>
			{ settings === null && loadError === '' && <Spinner /> }
			{ loadError !== '' && (
				<Notice status="error" isDismissible={ false }>
					{ loadErrorMessage }
				</Notice>
			) }
			{ settings !== null && (
				<>
					<PanelRow>
						<ToggleControl
							label={ __( 'Redirects enabled', 'pv-redirects' ) }
							help={
								settings.enabled
									? __(
											'Redirects are being served.',
											'pv-redirects'
										)
									: __(
											'Redirects are turned off; visitors get 404s.',
											'pv-redirects'
										)
							}
							checked={ settings.enabled }
							onChange={ ( enabled ) =>
								setSettings( { ...settings, enabled } )
							}
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							label={ __(
								'Case-sensitive matching',
								'pv-redirects'
							) }
							help={
								settings.case_sensitive
									? __(
											'/Old-Page/ and /old-page/ are different paths.',
											'pv-redirects'
										)
									: __(
											'/Old-Page/ and /old-page/ match the same redirect.',
											'pv-redirects'
										)
							}
							checked={ settings.case_sensitive }
							onChange={ ( caseSensitive ) =>
								setSettings( {
									...settings,
									case_sensitive: caseSensitive,
								} )
							}
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							label={ __(
								'Preserve query string',
								'pv-redirects'
							) }
							help={ __(
								"Carry the visitor's query string onto the destination.",
								'pv-redirects'
							) }
							checked={ settings.preserve_qs }
							onChange={ ( preserveQs ) =>
								setSettings( {
									...settings,
									preserve_qs: preserveQs,
								} )
							}
						/>
					</PanelRow>
					<SelectControl
						label={ __( 'Default status', 'pv-redirects' ) }
						help={ __(
							'Used for new redirects and per-post redirects without an explicit status.',
							'pv-redirects'
						) }
						value={ String( settings.default_status ) }
						options={ STATUS_OPTIONS }
						onChange={ ( value ) =>
							setSettings( {
								...settings,
								default_status: Number( value ),
							} )
						}
						__next40pxDefaultSize
					/>
					<StatusHelp />
					<PanelRow>
						<Button
							variant="primary"
							isBusy={ isSaving }
							disabled={ isSaving }
							onClick={ save }
						>
							{ __( 'Save Settings', 'pv-redirects' ) }
						</Button>
					</PanelRow>
				</>
			) }
		</PanelBody>
	);
}
