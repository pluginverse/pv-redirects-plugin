---
name: ScrollLock
description: "ScrollLock is a content-free React component for declaratively preventing scroll bleed from modal UI to the page body. T"
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/scroll-lock
docs: https://developer.wordpress.org/block-editor/reference-guides/components/scroll-lock/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-scrolllock--docs
---

# ScrollLock

ScrollLock is a content-free React component for declaratively preventing scroll bleed from modal UI to the page body. This component applies a `lockscroll` class to the `document.documentElement` and `document.scrollingElement` elements to stop the body from scrolling. When it is present, the lock is applied.

## Usage

Declare scroll locking as part of modal UI.

```jsx
import { useState } from 'react';
import { ScrollLock, Button } from '@wordpress/components';

const MyScrollLock = () => {
	const [ isScrollLocked, setIsScrollLocked ] = useState( false );

	const toggleLock = () => {
		setIsScrollLocked( ( locked ) => ! locked ) );
	};

	return (
		<div>
			<Button variant="secondary" onClick={ toggleLock }>
				Toggle scroll lock
			</Button>
			{ isScrollLocked && <ScrollLock /> }
			<p>
				Scroll locked:
				<strong>{ isScrollLocked ? 'Yes' : 'No' }</strong>
			</p>
		</div>
	);
};
```
