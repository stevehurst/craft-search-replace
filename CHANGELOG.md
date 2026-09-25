# Release Notes for Search and Replace

## 1.1.0 - 2026-09-25

- Renamed the plugin to Search and Replace.
- Renamed the package to `foundbrand/craft-search-replace`, the plugin handle to `search-replace`, and the namespace to `foundbrand\searchreplace`. Uninstall the `find-replace` plugin and remove `foundbrand/craft-find-replace` before installing this version.
- Moved it from Utilities to its own section in the control panel's main navigation.
- Access is now controlled by the "Access Search and Replace" permission, which replaces the "Utilities → Find & Resave" permission.

## 1.0.0 - 2026-09-25

- Initial release.
- Find & Resave utility: search titles and all stored field content, preview matches, replace and resave through Craft via the queue.
- Field picker to limit search and replace to one field (including layout handle overrides) or titles.
