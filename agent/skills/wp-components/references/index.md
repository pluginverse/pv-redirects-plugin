# Component index

## Contents

- Form Inputs
- Color & Gradients
- Date & Time
- Layout & Spacing
- Typography
- Containers & Surfaces
- Buttons & Actions
- Feedback & Status
- Overlays & Dialogs
- Navigation
- Toolbar
- Dimension & Spacing Controls
- Tools Panel
- Data & Trees
- Drag & Drop
- Utility & Misc
- Higher-Order Components & Hooks

Every component in `@wordpress/components`, grouped by category. The reference file for a component is `references/<kebab-case-name>.md` (`TextControl` is `references/text-control.md`).

## Form Inputs

| Component | Description |
|-----------|-------------|
| `TextControl` | Single-line text input with label |
| `TextareaControl` | Multi-line text input |
| `NumberControl` | Numeric input with increment/decrement |
| `InputControl` | Low-level input with prefix/suffix slot support |
| `SelectControl` | Dropdown select menu |
| `CustomSelectControl` | Styled dropdown with custom option rendering |
| `ComboboxControl` | Searchable dropdown (select + autocomplete) |
| `CheckboxControl` | Single checkbox with label |
| `RadioControl` | Radio button group |
| `ToggleControl` | On/off switch toggle |
| `ToggleGroupControl` | Segmented button group for mutually exclusive options |
| `RangeControl` | Slider with optional number input |
| `FormTokenField` | Tag/token input for multiple values |
| `FormToggle` | Standalone toggle switch (no label) |
| `FormFileUpload` | File upload button |
| `SearchControl` | Search input with clear button |
| `Autocomplete` | Inline autocomplete suggestions (e.g., slash commands) |

## Color & Gradients

| Component | Description |
|-----------|-------------|
| `ColorPalette` | Grid of color swatches with custom color option |
| `ColorPicker` | Full color picker with hex/rgb/hsl inputs |
| `ColorIndicator` | Small circular color swatch preview |
| `GradientPicker` | Gradient editor with stop controls |
| `CustomGradientPicker` | Advanced gradient builder with type switching |
| `DuotonePicker` | Two-tone color filter picker |
| `DuotoneSwatch` | Preview swatch for a duotone filter |

## Date & Time

| Component | Description |
|-----------|-------------|
| `DatePicker` | Calendar-based date picker |
| `DateTimePicker` | Combined date and time picker |
| `TimePicker` | Time-only picker |

## Layout & Spacing

| Component | Description |
|-----------|-------------|
| `Flex` | CSS flexbox container |
| `FlexItem` | Child of `Flex` |
| `FlexBlock` | `FlexItem` that fills available space |
| `HStack` | Horizontal flex layout (shorthand) |
| `VStack` | Vertical flex layout (shorthand) |
| `Grid` | CSS grid container |
| `Spacer` | Adds spacing between elements |
| `Divider` | Visual separator line |

## Typography

| Component | Description |
|-----------|-------------|
| `Heading` | Semantic heading (h1–h6) with size presets |
| `Text` | Text element with typography props |
| `Truncate` | Truncates overflowing text with ellipsis |
| `TextHighlight` | Highlights matching text within a string |
| `FontSizePicker` | Font size selector with presets and custom input |

## Containers & Surfaces

| Component | Description |
|-----------|-------------|
| `Card` | Bordered content container |
| `CardHeader` | Header area for `Card` |
| `CardBody` | Body content area for `Card` |
| `CardFooter` | Footer area for `Card` |
| `CardDivider` | Divider within a `Card` |
| `CardMedia` | Media area within a `Card` |
| `Panel` | Collapsible section container |
| `PanelBody` | Collapsible body within a `Panel` |
| `PanelHeader` | Header for a `Panel` |
| `PanelRow` | Row layout within a `Panel` |
| `Surface` | Background surface with elevation support |
| `Elevation` | Applies box-shadow elevation to a surface |

## Buttons & Actions

| Component | Description |
|-----------|-------------|
| `Button` | Primary action element (primary, secondary, tertiary, link variants) |
| `ButtonGroup` | Groups related buttons together |
| `ExternalLink` | Link that opens in a new tab with external icon |
| `Dropdown` | Button that reveals a popover on click |
| `DropdownMenu` | Button with a menu of actions |

## Feedback & Status

| Component | Description |
|-----------|-------------|
| `Notice` | Informational banner (success, warning, error, info) |
| `NoticeList` | Renders a list of `Notice` components |
| `Snackbar` | Temporary toast notification |
| `SnackbarList` | Renders a list of `Snackbar` components |
| `Spinner` | Loading spinner indicator |
| `ProgressBar` | Horizontal progress bar |
| `Tip` | Informational tip with lightbulb icon |

## Overlays & Dialogs

| Component | Description |
|-----------|-------------|
| `Modal` | Dialog overlay with title, close button, and focus trap |
| `ConfirmDialog` | Simple yes/no confirmation modal |
| `Popover` | Floating content anchored to a reference element |
| `Guide` | Multi-step onboarding modal |
| `GuidePage` | Single page within a `Guide` |
| `Tooltip` | Hover/focus tooltip for additional context |

## Navigation

| Component | Description |
|-----------|-------------|
| `TabPanel` | Tabbed content switcher |
| `NavigatorProvider` | Client-side screen navigation context |
| `NavigatorScreen` | Screen within a `NavigatorProvider` |
| `NavigatorButton` | Navigates to a `NavigatorScreen` |
| `NavigatorBackButton` | Navigates back in the navigator stack |
| `NavigableMenu` | Keyboard-navigable menu (arrow keys) |
| `MenuGroup` | Groups related menu items |
| `MenuItem` | Single item in a menu |
| `MenuItemsChoice` | Radio-like selectable menu items |

## Toolbar

| Component | Description |
|-----------|-------------|
| `Toolbar` | Horizontal toolbar container |
| `ToolbarButton` | Button within a `Toolbar` |
| `ToolbarGroup` | Groups related toolbar items |
| `ToolbarItem` | Generic item within a `Toolbar` |
| `ToolbarDropdownMenu` | Dropdown menu within a `Toolbar` |

## Dimension & Spacing Controls

| Component | Description |
|-----------|-------------|
| `BoxControl` | Linked/unlinked inputs for top/right/bottom/left values |
| `BorderControl` | Single border editor (width, style, color) |
| `BorderBoxControl` | Editor for all four sides of a border |
| `UnitControl` | Numeric input with unit dropdown (px, em, rem, %) |
| `AnglePickerControl` | Circular angle picker (0–360°) |
| `FocalPointPicker` | Image focal point selector |
| `AlignmentMatrixControl` | 3×3 grid for selecting alignment position |

## Tools Panel

| Component | Description |
|-----------|-------------|
| `ToolsPanel` | Container for block support controls with reset functionality |
| `ToolsPanelItem` | Individual control within a `ToolsPanel` |

## Data & Trees

| Component | Description |
|-----------|-------------|
| `TreeSelect` | Hierarchical dropdown select (e.g., category trees) |
| `TreeGrid` | Accessible tree table with keyboard navigation |
| `TreeGridRow` | Row within a `TreeGrid` |
| `TreeGridCell` | Cell within a `TreeGridRow` |
| `TreeGridItem` | Focusable item within a `TreeGridCell` |
| `QueryControls` | Pre-built controls for post query parameters |

## Drag & Drop

| Component | Description |
|-----------|-------------|
| `Draggable` | Makes an element draggable |
| `DropZone` | Drop target area for drag-and-drop |

## Utility & Misc

| Component | Description |
|-----------|-------------|
| `SlotFillProvider` | Context provider for the SlotFill system |
| `Slot` | Renders content inserted via matching `Fill` |
| `Fill` | Inserts content into a matching `Slot` |
| `Animate` | Wraps children with CSS animation |
| `Disabled` | Disables all interactive children |
| `ResponsiveWrapper` | Maintains aspect ratio for responsive elements |
| `ResizableBox` | Resizable container with drag handles |
| `SandBox` | Renders content in a sandboxed iframe |
| `ScrollLock` | Prevents body scrolling while mounted |
| `FocusableIframe` | Iframe that participates in focus management |
| `Icon` | Renders an SVG icon (from `@wordpress/icons` or custom) |
| `Dashicon` | Legacy Dashicon font icon (prefer `Icon`) |
| `Placeholder` | Block placeholder UI for initial setup state |
| `BaseControl` | Wraps a control with label, help text, and ID generation |
| `VisuallyHidden` | Hides content visually but keeps it accessible to screen readers |
| `Composite` | Accessible composite widget (roving tabindex) |
| `CompositeGroup` | Group within a `Composite` |
| `CompositeItem` | Focusable item within a `Composite` |
| `View` | Polymorphic base component (renders any HTML element) |

## Higher-Order Components & Hooks

| Export | Description |
|--------|-------------|
| `withNotices` | HOC that injects notice creation functions |
| `withFocusReturn` | HOC that returns focus to the previous element on unmount |
| `withFocusOutside` | HOC that detects clicks/focus outside a component |
| `withConstrainedTabbing` | HOC that traps tab key within a container |
| `withFallbackStyles` | HOC for computing styles from rendered DOM |
| `withFilters` | HOC that applies WordPress hook filters to a component |
| `withSpokenMessages` | HOC that adds ARIA live region announcements |
| `navigateRegions` | HOC for region-based keyboard navigation |
| `useNavigator` | Hook for programmatic navigator navigation |
