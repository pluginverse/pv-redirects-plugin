/**
 * Add/edit form. Opens in a Modal; validates request and destination inline and warns
 * when the request uses wildcard syntax.
 */

import {
	Button,
	Modal,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { STATUS_OPTIONS } from '../status-options';
import StatusHelp from './status-help';

const WILDCARD_HELP = __(
	'Wildcard: * matches a run of non-slash characters, a trailing /* matches the rest of the path, and a * in the destination is replaced by the matched text.',
	'pv-redirects'
);

/**
 * @param {Object}                                                                props
 * @param {Object}                                                                props.initial  Row to edit, or an empty add row {request:'', to:'', status:301}.
 * @param {(row: {request: string, to: string, status: number}) => Promise<void>} props.onSubmit Called with the cleaned row; resolves on success, rejects on failure.
 * @param {() => void}                                                            props.onClose  Close request.
 */
export default function RedirectFormModal( { initial, onSubmit, onClose } ) {
	const [ requestPath, setRequestPath ] = useState( initial.request );
	const [ destination, setDestination ] = useState( initial.to );
	const [ status, setStatus ] = useState( String( initial.status ) );
	const [ errors, setErrors ] = useState( [] );
	const [ submitError, setSubmitError ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );

	const isEditing = initial.request !== '';

	async function handleSubmit( event ) {
		event.preventDefault();

		const trimmedRequest = requestPath.trim();
		const trimmedDestination = destination.trim();
		const found = [];

		if ( trimmedRequest === '' ) {
			found.push( __( 'The request path is required.', 'pv-redirects' ) );
		} else if ( ! trimmedRequest.startsWith( '/' ) ) {
			found.push(
				__(
					'The request path must start with a slash, e.g. /old-page/.',
					'pv-redirects'
				)
			);
		}

		if ( trimmedDestination === '' ) {
			found.push( __( 'The destination is required.', 'pv-redirects' ) );
		}

		setErrors( found );

		if ( found.length > 0 ) {
			return;
		}

		setIsSaving( true );

		try {
			await onSubmit( {
				request: trimmedRequest,
				to: trimmedDestination,
				status: Number( status ),
			} );
		} catch ( error ) {
			setSubmitError( error.message );
			setIsSaving( false );
		}
	}

	return (
		<Modal
			title={
				isEditing
					? __( 'Edit Redirect', 'pv-redirects' )
					: __( 'Add Redirect', 'pv-redirects' )
			}
			onRequestClose={ onClose }
		>
			<form onSubmit={ handleSubmit }>
				{ errors.length > 0 && (
					<Notice status="error" isDismissible={ false }>
						{ errors.map( ( error ) => (
							<p key={ error }>{ error }</p>
						) ) }
					</Notice>
				) }
				{ submitError !== '' && (
					<Notice status="error" isDismissible={ false }>
						{ submitError }
					</Notice>
				) }
				{ requestPath.includes( '*' ) && (
					<Notice status="warning" isDismissible={ false }>
						{ WILDCARD_HELP }
					</Notice>
				) }
				<TextControl
					label={ __( 'Request path', 'pv-redirects' ) }
					help={ __(
						'Path on this site, starting with /, e.g. /old-page/.',
						'pv-redirects'
					) }
					value={ requestPath }
					onChange={ setRequestPath }
					__next40pxDefaultSize
				/>
				<TextControl
					label={ __( 'Destination', 'pv-redirects' ) }
					help={ __(
						'Absolute URL, or a path on this site starting with /.',
						'pv-redirects'
					) }
					value={ destination }
					onChange={ setDestination }
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'Status', 'pv-redirects' ) }
					help={ __(
						'301/308 permanent; 302/307 temporary.',
						'pv-redirects'
					) }
					value={ status }
					options={ STATUS_OPTIONS }
					onChange={ setStatus }
					__next40pxDefaultSize
				/>
				<StatusHelp />
				<p>
					<Button
						variant="secondary"
						onClick={ onClose }
						disabled={ isSaving }
					>
						{ __( 'Cancel', 'pv-redirects' ) }
					</Button>{ ' ' }
					<Button
						variant="primary"
						type="submit"
						isBusy={ isSaving }
						disabled={ isSaving }
					>
						{ __( 'Save Redirect', 'pv-redirects' ) }
					</Button>
				</p>
			</form>
		</Modal>
	);
}
