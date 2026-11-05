/**
 * Admin app: a dismissible notice stack over the redirects manager and the settings panel.
 */

import { Notice } from '@wordpress/components';
import { useCallback, useRef, useState } from '@wordpress/element';
import RedirectsManager from './components/redirects-manager';
import SettingsPanel from './components/settings-panel';

export default function App() {
	const [ notices, setNotices ] = useState( [] );
	const noticeId = useRef( 0 );

	/**
	 * Queue one result notice (status: 'success' | 'error' | 'warning' | 'info').
	 */
	const pushNotice = useCallback( ( status, message ) => {
		noticeId.current += 1;
		setNotices( ( current ) => [
			...current,
			{ id: noticeId.current, status, message },
		] );
	}, [] );

	const removeNotice = useCallback( ( id ) => {
		setNotices( ( current ) =>
			current.filter( ( notice ) => notice.id !== id )
		);
	}, [] );

	return (
		<>
			{ notices.map( ( notice ) => (
				<Notice
					key={ notice.id }
					status={ notice.status }
					onRemove={ () => removeNotice( notice.id ) }
				>
					{ notice.message }
				</Notice>
			) ) }
			<RedirectsManager pushNotice={ pushNotice } />
			<SettingsPanel pushNotice={ pushNotice } />
		</>
	);
}
