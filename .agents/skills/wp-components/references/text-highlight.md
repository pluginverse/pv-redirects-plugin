---
name: TextHighlight
description: "Highlights occurrences of a given string within another string of text. Wraps each match with a [<mark> tag](https://dev"
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/text-highlight
docs: https://developer.wordpress.org/block-editor/reference-guides/components/text-highlight/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-texthighlight--docs
---

# TextHighlight

Highlights occurrences of a given string within another string of text. Wraps each match with a [`<mark>` tag](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/mark) which provides browser default styling.

## Usage

Pass in the `text` and the `highlight` string to be matched against.

In the example below, the string `Gutenberg` would be highlighted twice.

```jsx
import { TextHighlight } from '@wordpress/components';

const MyTextHighlight = () => (
	<TextHighlight
		text="Why do we like Gutenberg? Because Gutenberg is the best!"
		highlight="Gutenberg"
	/>
);
```

## Props

The component accepts the following props.

### `highlight`: `string`

The string to search for and highlight within the `text`. Case insensitive. Multiple matches.

-   Required: Yes
-   Default: `''`

### `text`: `string`

The string of text to be tested for occurrences of then given `highlight`.

-   Required: Yes
-   Default: `''`
