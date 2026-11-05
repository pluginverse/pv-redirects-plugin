---
name: ToggleGroupControl
description: A segmented control component for selecting one option from a set of mutually exclusive choices.
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/toggle-group-control
docs: https://developer.wordpress.org/block-editor/reference-guides/components/toggle-group-control/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-togglegroupcontrol--docs
---

# ToggleGroupControl

## Contents

- Usage
- ToggleGroupControl Props
- ToggleGroupControlOption Props
- ToggleGroupControlOptionIcon Props

ToggleGroupControl is a form component that lets users choose options represented as visual toggles. It functions like a segmented control or button group where only one option can be selected at a time.

## Usage

```jsx
import {
    __experimentalToggleGroupControl as ToggleGroupControl,
    __experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

function Example() {
    return (
        <ToggleGroupControl label="Alignment" value="left" isBlock>
            <ToggleGroupControlOption value="left" label="Left" />
            <ToggleGroupControlOption value="center" label="Center" />
            <ToggleGroupControlOption value="right" label="Right" />
        </ToggleGroupControl>
    );
}
```

### With icons

```jsx
import {
    __experimentalToggleGroupControl as ToggleGroupControl,
    __experimentalToggleGroupControlOptionIcon as ToggleGroupControlOptionIcon,
} from '@wordpress/components';
import { alignLeft, alignCenter, alignRight } from '@wordpress/icons';

function Example() {
    return (
        <ToggleGroupControl label="Alignment" value="left">
            <ToggleGroupControlOptionIcon value="left" icon={ alignLeft } label="Left" />
            <ToggleGroupControlOptionIcon value="center" icon={ alignCenter } label="Center" />
            <ToggleGroupControlOptionIcon value="right" icon={ alignRight } label="Right" />
        </ToggleGroupControl>
    );
}
```

## ToggleGroupControl Props

### label

Label for the control.

- Type: `string`
- Required: Yes

### value

The current value.

- Type: `string | number`
- Required: No

### onChange

Called when the value changes.

- Type: `( value: string | number | undefined ) => void`
- Required: No

### isBlock

Whether the control takes up the full width of its container.

- Type: `boolean`
- Default: `false`
- Required: No

### isDeselectable

Whether clicking the selected option deselects it, setting the value to `undefined`.

- Type: `boolean`
- Default: `false`
- Required: No

### hideLabelFromVision

If true, the label is visually hidden.

- Type: `boolean`
- Default: `false`
- Required: No

### help

Help text below the control.

- Type: `string`
- Required: No

### children

ToggleGroupControlOption or ToggleGroupControlOptionIcon components.

- Type: `ReactNode`
- Required: Yes

### __nextHasNoMarginBottom

Opt into no bottom margin.

- Type: `boolean`
- Default: `false`
- Required: No

### __next40pxDefaultSize

Opt into the larger default size.

- Type: `boolean`
- Default: `false`
- Required: No

## ToggleGroupControlOption Props

### value

The value of this option.

- Type: `string | number`
- Required: Yes

### label

The visible label for this option.

- Type: `string`
- Required: Yes

## ToggleGroupControlOptionIcon Props

### value

The value of this option.

- Type: `string | number`
- Required: Yes

### icon

An icon to display.

- Type: `JSX.Element`
- Required: Yes

### label

Accessible label for this option.

- Type: `string`
- Required: Yes
