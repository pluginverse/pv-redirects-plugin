# Common patterns

### Inspector sidebar controls

```jsx
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, ToggleControl } from '@wordpress/components';

function Edit({ attributes, setAttributes }) {
    return (
        <>
            <InspectorControls>
                <PanelBody title="Settings">
                    <RangeControl
                        label="Columns"
                        value={attributes.columns}
                        onChange={(columns) => setAttributes({ columns })}
                        min={1}
                        max={6}
                    />
                    <ToggleControl
                        label="Show title"
                        checked={attributes.showTitle}
                        onChange={(showTitle) => setAttributes({ showTitle })}
                    />
                </PanelBody>
            </InspectorControls>
            {/* block content */}
        </>
    );
}
```

## Block toolbar controls

```jsx
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { edit, trash } from '@wordpress/icons';

<BlockControls>
    <ToolbarGroup>
        <ToolbarButton icon={edit} label="Edit" onClick={handleEdit} />
        <ToolbarButton icon={trash} label="Delete" onClick={handleDelete} />
    </ToolbarGroup>
</BlockControls>
```

## Confirmation dialog

```jsx
import { useState } from '@wordpress/element';
import { Button, ConfirmDialog } from '@wordpress/components';

const [isOpen, setIsOpen] = useState(false);

<Button variant="tertiary" isDestructive onClick={() => setIsOpen(true)}>
    Delete
</Button>
{isOpen && (
    <ConfirmDialog
        onConfirm={() => { handleDelete(); setIsOpen(false); }}
        onCancel={() => setIsOpen(false)}
    >
        Are you sure you want to delete this item?
    </ConfirmDialog>
)}
```
