import { Button, Tooltip } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function StatusHelp() {
	const [ open, setOpen ] = useState( false );
	const label = __( 'Help choosing a redirect status', 'pv-redirects' );

	return (
		<div>
			<Tooltip text={ label }>
				<Button
					variant="tertiary"
					aria-expanded={ open }
					onClick={ () => setOpen( ! open ) }
				>
					{ __( 'Which status should I use?', 'pv-redirects' ) }
				</Button>
			</Tooltip>
			{ open && (
				<ul>
					<li>
						{ __(
							'301: Permanent page move; recommended for most redirects.',
							'pv-redirects'
						) }
					</li>
					<li>
						{ __( '302: Temporary page move.', 'pv-redirects' ) }
					</li>
					<li>
						{ __(
							'307: Temporary move that preserves the request method and body.',
							'pv-redirects'
						) }
					</li>
					<li>
						{ __(
							'308: Permanent move that preserves the request method and body.',
							'pv-redirects'
						) }
					</li>
				</ul>
			) }
		</div>
	);
}
