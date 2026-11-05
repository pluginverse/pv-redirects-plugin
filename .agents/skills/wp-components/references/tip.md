---
name: Tip
description: A component to display a helpful tip with a lightbulb icon.
source: https://github.com/WordPress/gutenberg/tree/trunk/packages/components/src/tip
docs: https://developer.wordpress.org/block-editor/reference-guides/components/tip/
storybook: https://wordpress.github.io/gutenberg/?path=/docs/components-tip--docs
---

# Tip

Tip is a component to show a short piece of helpful information, displayed with a lightbulb icon.

## Usage

```jsx
import { Tip } from '@wordpress/components';

const MyTip = () => (
    <Tip>
        You can use keyboard shortcuts to speed up your workflow.
    </Tip>
);
```

## Props

### children

The tip content to display.

- Type: `ReactNode`
- Required: No
