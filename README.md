# ACF Global Blocks

Save any ACF Pro Flexible Content row as a reusable global block, then reference it from another compatible Flexible Content field.

## Requirements

- WordPress 6.2 or newer
- PHP 7.4 or newer
- Advanced Custom Fields Pro

## Installation

1. Copy the `acf-global-blocks` directory into `wp-content/plugins/` (or `web/app/plugins/` on a Bedrock site).
2. Activate **ACF Global Blocks** in WordPress.

No theme changes are needed when the theme already renders Flexible Content rows by their original `acf_fc_layout` value. On the front end, a reference is expanded back into the original row before ACF formats it.

## Usage

1. Edit a page containing an ACF Flexible Content field.
2. Configure a row such as **Logo Grid**.
3. Click **Save as global block** in that row's header and give it a name.
4. Update the page if you also want to preserve changes made to the original local row.
5. On another page, choose **Global Block** from the Flexible Content layout menu and select the saved block.
6. Choose **Linked** to keep it synced with a local fail-safe snapshot, or **Standalone copy** to import it as a normal editable row when the page is saved.
7. Edit linked content later under **Global Blocks** in the WordPress admin menu. Changes are reflected on every page that references the block.

A standalone copy is disconnected immediately after the page is saved. It can then be edited like any other Flexible Content row, and later changes to the source global block will not affect it.

## Global block groups

1. Tick **Group** in the headers of two or more rows in the same Flexible Content field.
2. Click **Save selected as global group** below the field and name the group.
3. Insert it through the same **Global Block** layout used for individual blocks.
4. Choose **Linked** to keep the complete group synchronized, or **Standalone copy** to import all rows as normal page content.

Rows are saved in their current page order. A linked group uses one placement ID across its fallback rows, so it expands only once while every row remains available as ordinary ACF content if the plugin is unavailable.

The **Global Blocks** admin list includes separate **Individual Blocks** and **Block Groups** views, counts, and type badges.

Its **Where used** column counts linked placements only. Hover or keyboard-focus the count to see the linked pages and open their edit screens; disconnected standalone copies are intentionally excluded.

Global blocks are limited to the Flexible Content field they were created from. This prevents a row from being inserted into a field that does not define the original layout.

## Settings

Open **Global Blocks → Settings** to choose where the plugin controls appear. The plugin detects Flexible Content fields at any nesting level and lists their label, name, field group, and key.

- **Every Flexible Content field** is the default and preserves the behaviour of earlier plugin versions.
- **Only the selected fields** lets each site choose its own page-builder field without adding PHP filters.
- Existing linked placements and saved blocks remain stored if a field is disabled in settings.

## Developer control

The plugin is enabled for named Flexible Content fields by default. Limit it to selected fields with:

```php
add_filter('acf_global_blocks/enabled_for_field', function ($enabled, $field) {
    return ($field['name'] ?? '') === 'section_content';
}, 10, 2);
```

## Behaviour and safeguards

- A global block stores one or more original Flexible Content rows from the same field.
- Global blocks cannot contain other global blocks.
- Linked placements are stored as normal original-layout rows with hidden source, placement, and order metadata. If the plugin is unavailable, the last saved snapshot still renders.
- Standalone imports preserve the original layout values, including nested repeaters and media IDs.
- If a linked source is missing or unavailable, its normal-layout local snapshot is rendered. Legacy references created before version 1.2.0 need to be saved once to gain this fallback.
- Deleting a global block does not delete its page snapshots; linked placements continue rendering their last saved normal ACF rows.
- ACF's **Disable** action is saved per page placement. Disabling a linked block or group hides that placement on the front end without disabling its reusable source elsewhere.
- Disabling an individual row while editing a saved global group disables that source row everywhere the linked group is used; the other rows in the group remain active.
- Saving a row as global does not automatically replace the original local row. Add a **Global Block** row wherever the shared version should appear.
