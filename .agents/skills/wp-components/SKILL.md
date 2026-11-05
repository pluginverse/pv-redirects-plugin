---
name: wp-components
description: "Reference for @wordpress/components, the Gutenberg block editor UI library: picking the right component, props, and usage for inspector controls, toolbars, panels, modals, popovers, forms, and navigation. Use when the user is building or editing a Gutenberg block's edit UI, adding InspectorControls or BlockControls, asks which WordPress component to use for a control, imports from @wordpress/components or uses wp.components, or asks about a component by name such as PanelBody, ToggleControl, RangeControl, or Modal, even if they only say 'block settings sidebar'. For WP-CLI site management, use the wordpress-cli skill."
---

# WordPress Gutenberg Components

Reference for the `@wordpress/components` package, the shared UI library used across the WordPress block editor and dashboard. The value here is choosing the right component and getting its props right; the model already knows React.

## Scope

Component selection and usage inside block `edit` functions, plugin sidebars, and admin screens. Block-editor wrappers such as `InspectorControls` and `BlockControls` come from `@wordpress/block-editor` and appear here only where a pattern needs them. Site management from the terminal is the wordpress-cli skill.

## Latest docs

- **Storybook (interactive examples):** https://wordpress.github.io/gutenberg/?path=/docs/components-introduction--docs
- **Developer handbook:** https://developer.wordpress.org/block-editor/reference-guides/components/
- **Package setup guide:** https://developer.wordpress.org/block-editor/reference-guides/packages/packages-components/
- **Source & changelog:** https://github.com/WordPress/gutenberg/tree/trunk/packages/components

## Quick start

```jsx
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, RangeControl } from '@wordpress/components';
```

Inside WordPress admin the same components are available globally as `wp.components`. Install, stylesheet enqueueing, TypeScript prop types, and popover slot setup: read `references/setup.md` when starting a new plugin or the build is outside WordPress.

## Choosing a component

1. Name the UI need (text input, on/off, choose one of few, choose one of many, confirm a destructive action, transient message, overlay).
2. Look it up in `references/index.md`, which lists every component by category with a one-line purpose. Read it when unsure which component fits or when the user names a component you do not recognize.
3. Open that component's reference file, `references/<kebab-case-name>.md` (`ToggleGroupControl` is `references/toggle-group-control.md`). Each holds the upstream README with props, examples, and design guidelines, plus frontmatter links to the live docs and Storybook. Read it before writing props; prop names and defaults change between releases.
4. For the block-editor wiring (sidebar panels, toolbar buttons, confirm dialogs, notices) read `references/patterns.md` and copy the matching snippet.

## Gotchas

- `InspectorControls`, `BlockControls`, and `RichText` are in `@wordpress/block-editor`, not `@wordpress/components`. Importing them from the wrong package fails silently in some builds.
- Most `*Control` components are controlled: pass `value`/`checked` and `onChange` together, and wire `onChange` to `setAttributes`.
- Newer inputs (`ToggleGroupControl`, `NumberControl`, `InputControl`) expect `__next40pxDefaultSize` and `__nextHasNoMarginBottom` to avoid deprecation warnings; check the component's reference file for its current opt-in props.
- Popovers and dropdowns render into `Popover.Slot`; outside the editor you must provide one (see `references/setup.md`).
- Components prefixed `__experimental` or `__unstable` can change without notice; prefer the stable export when one exists.
- Styles come from the `wp-components` stylesheet; a plugin that enqueues its own CSS without that dependency gets unstyled controls.

## Keeping references current

Refresh a component file from `https://raw.githubusercontent.com/WordPress/gutenberg/trunk/packages/components/src/{component-dir}/README.md`, keep its frontmatter, then run:

```bash
python3 scripts/add_contents.py references/
```

It inserts or rebuilds a `## Contents` list in any reference over 100 lines, so a partial read still shows the file's scope. Idempotent; safe to run after every refresh.
