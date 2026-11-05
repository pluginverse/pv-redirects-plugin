---
name: Toolbar
description: A horizontal toolbar container component that groups related controls with proper accessibility semantics.
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/toolbar
docs: https://developer.wordpress.org/block-editor/reference-guides/components/toolbar/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-toolbar--docs
---

# Toolbar

## Contents

- Usage
- Toolbar Props
- ToolbarButton Props
- ToolbarGroup Props
- ToolbarItem
- ToolbarDropdownMenu

Toolbar is a horizontal bar of buttons and controls used to group related actions. It provides proper accessibility semantics including roving tabindex keyboard navigation.

## Usage

```jsx
import {
    Toolbar,
    ToolbarButton,
    ToolbarGroup,
} from '@wordpress/components';
import { edit, trash, more } from '@wordpress/icons';

function MyToolbar() {
    return (
        <Toolbar label="Options">
            <ToolbarGroup>
                <ToolbarButton icon={ edit } label="Edit" onClick={ handleEdit } />
                <ToolbarButton icon={ trash } label="Delete" onClick={ handleDelete } />
            </ToolbarGroup>
            <ToolbarGroup>
                <ToolbarButton icon={ more } label="More" onClick={ handleMore } />
            </ToolbarGroup>
        </Toolbar>
    );
}
```

### In BlockControls

```jsx
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';

<BlockControls>
    <ToolbarGroup>
        <ToolbarButton icon={ edit } label="Edit" onClick={ handleEdit } />
    </ToolbarGroup>
</BlockControls>
```

## Toolbar Props

### label

An accessible label for the toolbar.

- Type: `string`
- Required: Yes

### className

Additional CSS class name.

- Type: `string`
- Required: No

### children

Toolbar contents (ToolbarGroup, ToolbarButton, etc.).

- Type: `ReactNode`
- Required: No

## ToolbarButton Props

### icon

Icon to display.

- Type: `JSX.Element | string`
- Required: No

### label

Accessible label for the button. Also used as tooltip text.

- Type: `string`
- Required: No

### onClick

Click handler.

- Type: `( event: MouseEvent ) => void`
- Required: No

### isActive

Whether the button is in an active/pressed state.

- Type: `boolean`
- Default: `false`
- Required: No

### isDisabled

Whether the button is disabled.

- Type: `boolean`
- Default: `false`
- Required: No

### className

Additional CSS class name.

- Type: `string`
- Required: No

### title

Tooltip text. Falls back to `label` if not provided.

- Type: `string`
- Required: No

## ToolbarGroup Props

### controls

Array of control objects to render as buttons.

- Type: `Array<{ icon: string; title: string; onClick: Function; isActive?: boolean; isDisabled?: boolean }>`
- Required: No

### children

ToolbarButton or other components to render in the group.

- Type: `ReactNode`
- Required: No

### className

Additional CSS class name.

- Type: `string`
- Required: No

## ToolbarItem

A generic toolbar item that provides the toolbar context to its render prop.

### Usage

```jsx
import { Toolbar, ToolbarItem } from '@wordpress/components';
import { Button } from '@wordpress/components';

<Toolbar label="Options">
    <ToolbarItem>
        { ( toolbarItemProps ) => (
            <Button { ...toolbarItemProps }>Custom Item</Button>
        ) }
    </ToolbarItem>
</Toolbar>
```

### Props

#### children

A render prop that receives toolbar item props.

- Type: `( props: object ) => ReactNode`
- Required: Yes

## ToolbarDropdownMenu

A dropdown menu within a toolbar.

### Usage

```jsx
import { Toolbar, ToolbarDropdownMenu } from '@wordpress/components';
import { chevronDown } from '@wordpress/icons';

<Toolbar label="Options">
    <ToolbarDropdownMenu
        icon={ chevronDown }
        label="More options"
        controls={ [
            {
                title: 'Option A',
                onClick: () => handleOptionA(),
            },
            {
                title: 'Option B',
                onClick: () => handleOptionB(),
            },
        ] }
    />
</Toolbar>
```
