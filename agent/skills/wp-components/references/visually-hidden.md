---
name: VisuallyHidden
description: "VisuallyHidden is a component used to render text intended to be visually hidden, but will show for alternate devices, f"
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/visually-hidden
docs: https://developer.wordpress.org/block-editor/reference-guides/components/visually-hidden/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-visuallyhidden--docs
---

# VisuallyHidden

`VisuallyHidden` is a component used to render text intended to be visually hidden, but will show for alternate devices, for example a screen reader.

## Usage

```jsx
import { VisuallyHidden } from '@wordpress/components';

function Example() {
	return (
		<VisuallyHidden>
			<label>Code is Poetry</label>
		</VisuallyHidden>
	);
}
```

## Best practices

The element that `VisuallyHidden` renders has the style `position: absolute`. When using this component be careful of the [stacking context](https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_Positioning/Understanding_z_index/The_stacking_context). Even though `VisuallyHidden` isn't visible, it can still affect layout. An example of this is that `VisuallyHidden` may ignore `overflow` styles of ancestor elements because it instead adopts the `overflow` of its stacking context. One known side-effect can be an unexpected scrollbar appearing. To fix this kind of issue, introduce a stacking context on a more immediate parent of `VisuallyHidden`. Adding `position: relative` is often an easy way to do this.
