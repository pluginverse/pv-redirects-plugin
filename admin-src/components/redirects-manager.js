/**
 * The redirects table: a paged list from GET /redirects, add/edit via the form modal,
 * and a two-step inline delete confirmation (no window.confirm).
 */

import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	createRedirect,
	deleteRedirect,
	listRedirects,
	updateRedirect,
} from '../api';
import { statusLabel } from '../status-options';
import RedirectFormModal from './redirect-form-modal';

const EMPTY_ROW = { request: '', to: '', status: 301 };

/**
 * Destination cell content: absolute URLs and site paths become links (site paths
 * resolve against the current origin, which is where the admin runs); anything else
 * (e.g. a `?p=123` query) renders as code text.
 *
 * @param {Object} props
 * @param {string} props.to Destination.
 */
function Destination( { to } ) {
	const isSitePath = to.startsWith( '/' );
	const isLinkable =
		isSitePath || to.startsWith( 'http://' ) || to.startsWith( 'https://' );

	return isLinkable ? (
		<a
			href={ isSitePath ? window.location.origin + to : to }
			target="_blank"
			rel="noreferrer"
		>
			{ to }
		</a>
	) : (
		<code>{ to }</code>
	);
}

export default function RedirectsManager( { pushNotice } ) {
	const [ rows, setRows ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ loadError, setLoadError ] = useState( '' );
	const [ modalRow, setModalRow ] = useState( null );
	const [ confirmingDelete, setConfirmingDelete ] = useState( '' );
	const [ deletingRequest, setDeletingRequest ] = useState( '' );

	const load = useCallback( async ( targetPage ) => {
		setIsLoading( true );
		setLoadError( '' );

		try {
			const data = await listRedirects( targetPage );
			const loaded = Object.entries( data.redirects ?? {} ).map(
				( [ requestPath, row ] ) => ( {
					request: requestPath,
					to: row.to,
					status: row.status,
				} )
			);
			const returnedTotal = data.total ?? loaded.length;

			setRows( loaded );
			setTotal( returnedTotal );
			setPage( data.page ?? targetPage );
			setTotalPages(
				Math.max(
					1,
					Math.ceil( returnedTotal / ( data.per_page ?? 20 ) )
				)
			);
		} catch ( error ) {
			setLoadError( error.message );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		load( 1 );
	}, [ load ] );

	/**
	 * Save the modal form. Editing with a changed request path is a rename: re-create
	 * under the new key first, then remove the old one, so a rule always exists.
	 *
	 * @param {Object}  row                Row from the form.
	 * @param {string}  row.request        Request path.
	 * @param {string}  row.to             Destination.
	 * @param {number}  row.status         HTTP status.
	 * @param {boolean} row.rejectExisting Reject matching existing keys.
	 */
	async function handleSave( { request, to, status, rejectExisting } ) {
		const original = modalRow;

		if ( original !== null && original.request !== '' ) {
			if ( request !== original.request ) {
				await createRedirect( request, to, status, rejectExisting );
				await deleteRedirect( original.request );
			} else {
				await updateRedirect( original.request, to, status );
			}

			const savedMessage = sprintf(
				/* translators: %s: request path. */
				__( 'Redirect for %s saved.', 'pv-redirects' ),
				request
			);

			pushNotice( 'success', savedMessage );
		} else {
			await createRedirect( request, to, status, rejectExisting );

			const addedMessage = sprintf(
				/* translators: %s: request path. */
				__( 'Redirect for %s added.', 'pv-redirects' ),
				request
			);

			pushNotice( 'success', addedMessage );
		}

		setModalRow( null );
		await load( page );
	}

	async function handleDelete( requestPath ) {
		setDeletingRequest( requestPath );

		try {
			await deleteRedirect( requestPath );

			const deletedMessage = sprintf(
				/* translators: %s: request path. */
				__( 'Redirect for %s deleted.', 'pv-redirects' ),
				requestPath
			);

			pushNotice( 'success', deletedMessage );
			setConfirmingDelete( '' );
			// The last row of a page beyond the first leaves an empty page; step back.
			await load( rows.length === 1 && page > 1 ? page - 1 : page );
		} catch ( error ) {
			pushNotice( 'error', error.message );
		} finally {
			setDeletingRequest( '' );
		}
	}

	const pageLabel = sprintf(
		/* translators: 1: current page, 2: total pages, 3: total redirects. */
		__( 'Page %1$d of %2$d (%3$d redirects)', 'pv-redirects' ),
		page,
		totalPages,
		total
	);

	const loadErrorMessage = sprintf(
		/* translators: %s: error message. */
		__( 'Redirects could not be loaded: %s', 'pv-redirects' ),
		loadError
	);

	return (
		<>
			<h2>{ __( 'Redirects', 'pv-redirects' ) }</h2>
			<p>
				<Button
					variant="primary"
					onClick={ () => setModalRow( EMPTY_ROW ) }
				>
					{ __( 'Add Redirect', 'pv-redirects' ) }
				</Button>
			</p>
			{ loadError !== '' && (
				<Notice
					status="error"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Retry', 'pv-redirects' ),
							onClick: () => load( page ),
						},
					] }
				>
					{ loadErrorMessage }
				</Notice>
			) }
			{ isLoading ? (
				<Spinner />
			) : (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Request', 'pv-redirects' ) }</th>
							<th>{ __( 'Destination', 'pv-redirects' ) }</th>
							<th>{ __( 'Status', 'pv-redirects' ) }</th>
							<th>{ __( 'Source', 'pv-redirects' ) }</th>
							<th>{ __( 'Actions', 'pv-redirects' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.length === 0 ? (
							<tr>
								<td colSpan={ 5 }>
									{ __(
										'No redirects yet. Add one above or with WP-CLI.',
										'pv-redirects'
									) }
								</td>
							</tr>
						) : (
							rows.map( ( row ) => (
								<tr key={ row.request }>
									<td>
										<code>{ row.request }</code>
									</td>
									<td>
										<Destination to={ row.to } />
									</td>
									<td>{ statusLabel( row.status ) }</td>
									<td>{ __( 'Option', 'pv-redirects' ) }</td>
									<td>
										{ confirmingDelete === row.request ? (
											<>
												<Button
													variant="primary"
													isDestructive
													isBusy={
														deletingRequest ===
														row.request
													}
													disabled={
														deletingRequest !== ''
													}
													onClick={ () =>
														handleDelete(
															row.request
														)
													}
												>
													{ __(
														'Confirm delete',
														'pv-redirects'
													) }
												</Button>{ ' ' }
												<Button
													variant="secondary"
													disabled={
														deletingRequest !== ''
													}
													onClick={ () =>
														setConfirmingDelete(
															''
														)
													}
												>
													{ __(
														'Cancel',
														'pv-redirects'
													) }
												</Button>
											</>
										) : (
											<>
												<Button
													variant="secondary"
													size="small"
													onClick={ () =>
														setModalRow( row )
													}
												>
													{ __(
														'Edit',
														'pv-redirects'
													) }
												</Button>{ ' ' }
												<Button
													variant="secondary"
													isDestructive
													size="small"
													onClick={ () =>
														setConfirmingDelete(
															row.request
														)
													}
												>
													{ __(
														'Delete',
														'pv-redirects'
													) }
												</Button>
											</>
										) }
									</td>
								</tr>
							) )
						) }
					</tbody>
				</table>
			) }
			{ ! isLoading && rows.length > 0 && (
				<p>
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => load( page - 1 ) }
					>
						{ __( 'Previous', 'pv-redirects' ) }
					</Button>{ ' ' }
					<span>{ pageLabel }</span>{ ' ' }
					<Button
						variant="secondary"
						disabled={ page >= totalPages }
						onClick={ () => load( page + 1 ) }
					>
						{ __( 'Next', 'pv-redirects' ) }
					</Button>
				</p>
			) }
			{ modalRow !== null && (
				<RedirectFormModal
					initial={ modalRow }
					onSubmit={ handleSave }
					onClose={ () => setModalRow( null ) }
				/>
			) }
		</>
	);
}
