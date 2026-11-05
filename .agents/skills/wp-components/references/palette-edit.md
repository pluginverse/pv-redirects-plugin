---
name: PaletteEdit
description: A component for editing color palettes and gradient palettes with add, remove, and rename functionality.
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/palette-edit
docs: https://developer.wordpress.org/block-editor/reference-guides/components/palette-edit/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-paletteedit--docs
---

# PaletteEdit

## Contents

- Usage
- Props

`PaletteEdit` allows users to edit color and gradient palettes. It provides an interface for adding, removing, renaming, and modifying colors or gradients in a palette.

## Usage

```jsx
import { __experimentalPaletteEdit as PaletteEdit } from '@wordpress/components';

function Example() {
    const [ colors, setColors ] = useState( [
        { name: 'Red', slug: 'red', color: '#ff0000' },
        { name: 'Blue', slug: 'blue', color: '#0000ff' },
    ] );

    return (
        <PaletteEdit
            colors={ colors }
            onChange={ setColors }
            paletteLabel="My Colors"
            emptyMessage="No colors defined."
            canOnlyChangeValues={ false }
            canReset={ true }
        />
    );
}
```

### Gradient palette editing

```jsx
<PaletteEdit
    gradients={ gradients }
    onChange={ setGradients }
    paletteLabel="My Gradients"
    emptyMessage="No gradients defined."
/>
```

## Props

### `colors`

-   Type: `Array<{ name: string; slug: string; color: string }>`
-   Required: No

Array of color objects to display and edit. Each object should have `name`, `slug`, and `color` properties.

### `gradients`

-   Type: `Array<{ name: string; slug: string; gradient: string }>`
-   Required: No

Array of gradient objects to display and edit. Each object should have `name`, `slug`, and `gradient` properties.

### `onChange`

-   Type: `( newPalette: Array ) => void`
-   Required: Yes

Called when the palette changes (add, remove, rename, or modify a color/gradient).

### `paletteLabel`

-   Type: `string`
-   Required: Yes

The label for the palette section.

### `emptyMessage`

-   Type: `string`
-   Required: No

Message to display when the palette is empty.

### `canOnlyChangeValues`

-   Type: `boolean`
-   Default: `false`
-   Required: No

When `true`, prevents adding, removing, or renaming palette items. Only color/gradient values can be changed.

### `canReset`

-   Type: `boolean`
-   Default: `true`
-   Required: No

Whether to show the reset button.

### `slugPrefix`

-   Type: `string`
-   Default: `''`
-   Required: No

A prefix to add to auto-generated slugs.

### `popoverProps`

-   Type: `object`
-   Required: No

Additional props to pass to the color picker popover.
