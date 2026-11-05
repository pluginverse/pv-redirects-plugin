---
name: ExternalLink
description: "Link to an external resource."
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/external-link
docs: https://developer.wordpress.org/block-editor/reference-guides/components/external-link/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-externallink--docs
---

# ExternalLink

Link to an external resource.

## Usage

```jsx
import { ExternalLink } from '@wordpress/components';

const MyExternalLink = () => (
	<ExternalLink href="https://wordpress.org">WordPress.org</ExternalLink>
);
```

## Props

The component accepts the following props. Any other props will be passed through to the `a`.

### `children`: `ReactNode`

The content to be displayed within the link.

-   Required: Yes

### `href`: `string`

The URL of the external resource.

-   Required: Yes
