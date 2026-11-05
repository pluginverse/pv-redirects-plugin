---
name: Scrollable
description: "This feature is still experimental. “Experimental” means this is an early implementation subject to drastic and breaking"
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/scrollable
docs: https://developer.wordpress.org/block-editor/reference-guides/components/scrollable/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-scrollable--docs
---

# Scrollable

<div class="callout callout-alert">
This feature is still experimental. “Experimental” means this is an early implementation subject to drastic and breaking changes.
</div>

`Scrollable` is a layout component that content in a scrollable container.

## Usage

```jsx
import { __experimentalScrollable as Scrollable } from '@wordpress/components';

function Example() {
	return (
		<Scrollable style={ { maxHeight: 200 } }>
			<div style={ { height: 500 } }>...</div>
		</Scrollable>
	);
}
```

## Props

### `children`: `ReactNode`

The children elements.

-   Required: Yes

### `scrollDirection`: `string`

Renders a scrollbar for a specific axis when content overflows.

-   Required: No
-   Default: `y`
-   Allowed values: `x`, `y`, `auto`

### `smoothScroll`: `boolean`

Enables (CSS) smooth scrolling.

-   Required: No
-   Default: `false`
