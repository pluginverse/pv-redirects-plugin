---
name: ToolsPanel
description: A container component for block support controls that provides reset functionality and optional item toggling.
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/tools-panel
docs: https://developer.wordpress.org/block-editor/reference-guides/components/tools-panel/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-toolspanel--docs
---

# ToolsPanel

## Contents

- Usage
- ToolsPanel Props
- ToolsPanelItem Props

ToolsPanel is a container component designed for block support controls in the inspector sidebar. It provides a dropdown menu to show/hide individual controls and reset them to their default values.

## Usage

```jsx
import {
    __experimentalToolsPanel as ToolsPanel,
    __experimentalToolsPanelItem as ToolsPanelItem,
} from '@wordpress/components';

function Example() {
    const [ width, setWidth ] = useState( undefined );
    const [ height, setHeight ] = useState( undefined );

    const resetAll = () => {
        setWidth( undefined );
        setHeight( undefined );
    };

    return (
        <ToolsPanel label="Dimensions" resetAll={ resetAll }>
            <ToolsPanelItem
                hasValue={ () => !! width }
                label="Width"
                onDeselect={ () => setWidth( undefined ) }
            >
                <UnitControl
                    label="Width"
                    value={ width }
                    onChange={ setWidth }
                />
            </ToolsPanelItem>
            <ToolsPanelItem
                hasValue={ () => !! height }
                label="Height"
                onDeselect={ () => setHeight( undefined ) }
            >
                <UnitControl
                    label="Height"
                    value={ height }
                    onChange={ setHeight }
                />
            </ToolsPanelItem>
        </ToolsPanel>
    );
}
```

## ToolsPanel Props

### label

The panel title displayed in the header.

- Type: `string`
- Required: Yes

### resetAll

Called when the "Reset all" option is selected from the dropdown menu.

- Type: `( filters?: Array<( attribute: string ) => string> ) => void`
- Required: Yes

### panelId

A unique ID for the panel, used to coordinate with ToolsPanelItem components.

- Type: `string`
- Required: No

### hasInnerWrapper

If true, the panel's items are expected to be wrapped in an additional element.

- Type: `boolean`
- Default: `false`
- Required: No

### shouldRenderPlaceholderItems

If true, placeholder items are rendered when items are hidden. Useful for maintaining layout.

- Type: `boolean`
- Default: `false`
- Required: No

### dropdownMenuProps

Additional props to pass to the dropdown menu.

- Type: `object`
- Required: No

### children

ToolsPanelItem components.

- Type: `ReactNode`
- Required: No

### className

Additional CSS class name.

- Type: `string`
- Required: No

## ToolsPanelItem Props

### label

The label displayed in the dropdown menu for toggling this item.

- Type: `string`
- Required: Yes

### hasValue

A function that returns whether this item has a value set.

- Type: `() => boolean`
- Required: Yes

### onDeselect

Called when the item is deselected (hidden) via the dropdown menu. Should reset the item's value to its default.

- Type: `() => void`
- Required: No

### onSelect

Called when the item is selected (shown) via the dropdown menu.

- Type: `() => void`
- Required: No

### isShownByDefault

Whether the item is shown by default. If false, the item starts hidden and must be enabled via the dropdown.

- Type: `boolean`
- Default: `true`
- Required: No

### panelId

The ID of the parent ToolsPanel. Used for coordination.

- Type: `string`
- Required: No

### children

The control to render when the item is visible.

- Type: `ReactNode`
- Required: No

### className

Additional CSS class name.

- Type: `string`
- Required: No
