# Installation and setup

```bash
npm install @wordpress/components --save
```

The package requires an ES2015+ environment. If your build target doesn't support this, include the polyfill from `@wordpress/babel-preset-default`.

Components are also available globally as `wp.components` when working within the WordPress admin.

### CSS Styles

**Within WordPress** — add `wp-components` as a dependency of your plugin's stylesheet:

```php
wp_enqueue_style( 'my-plugin-style', plugins_url( 'style.css', __FILE__ ), array( 'wp-components' ) );
```

**Outside WordPress** — import the stylesheet directly:

```js
import '@wordpress/components/build-style/style.css';
```

An RTL version is available at `@wordpress/components/build-style/style-rtl.css`.

### Basic Usage

```jsx
import { Button, TextControl } from '@wordpress/components';

export default function MyButton() {
    return <Button variant="primary">Click Me!</Button>;
}
```

```jsx
// When using in a block's edit function
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
```

### TypeScript

Extract component prop types using `React.ComponentProps`:

```tsx
import type { ComponentProps } from 'react';
import { Button } from '@wordpress/components';

export default function MyButton( props: ComponentProps< typeof Button > ) {
    return <Button { ...props }>Click Me!</Button>;
}
```

### Popover Slot Setup

To control where popovers render in the DOM, wrap your app with `SlotFillProvider` and include a `Popover.Slot`:

```jsx
import { SlotFillProvider, Popover } from '@wordpress/components';

function App() {
    return (
        <SlotFillProvider>
            { /* Your components */ }
            <Popover.Slot />
        </SlotFillProvider>
    );
}
```
