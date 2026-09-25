# Find & Resave for Craft CMS

Find text anywhere in your content, then replace it and resave the matching elements through Craft.

Craft 5's built-in **Find and Replace** utility writes straight to the database, with no preview and no resave. This plugin adds **Utilities → Find & Resave**, which works differently:

- **Searches every field:** titles and every field value stored on the element (Plain Text, CKEditor, Link, Table, Dropdown, SEO fields, and so on), on all element types and sites. Nested Matrix entries are searched as their own elements.
- **Shows matches first:** each result has the element, its owner for nested entries, the matching fields and a highlighted snippet, with a link to edit it.
- **Lets you choose:** tick which elements to change.
- **Resaves properly:** every change goes through Craft's element service, so entry revisions (with notes), search indexes, cache invalidation and plugin events all work as normal.
- **Resave only:** resave the matching elements without changing anything. This is useful after a raw database find and replace.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

## Installation

The plugin isn't on Packagist or the Plugin Store, so add the GitHub repository to your project's `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/stevehurst/craft-find-replace"
  }
]
```

Then install it:

```bash
composer require foundbrand/craft-find-replace
php craft plugin/install find-replace
```

With DDEV, prefix both commands with `ddev`, as in `ddev composer …` and `ddev craft …`.

### Local development

To work on the plugin from a local checkout, use a `path` repository instead:

```json
"repositories": [
  { "type": "path", "url": "../craft-plugins/find-replace" }
]
```

With DDEV, the path must be available inside the container. Mount it with a `docker-compose.*.yaml` in `.ddev/`, or keep the plugin inside the project folder.

## Usage

1. Back up your database.
2. Go to **Utilities → Find & Resave** and search. Turn on **Include drafts** if needed. Revisions and trashed elements are never included.
3. Review the matches, and untick any elements you want to leave alone.
4. Enter the replacement text and click **Replace & resave selected**. To save the elements without changing them, click **Resave selected only**.
5. The work runs in the queue. When it finishes, the utility shows how many elements were saved, unchanged or failed, and why each failure happened.

Access is controlled by the **Utilities → Find & Resave** user permission. Admins always have access.

## How it works

- **Search:** it looks in `elements_sites.title` and `elements_sites.content`, the JSON column where Craft 5 stores each element's field values. Matching is exact and case-sensitive.
- **Replace:** it re-reads each element's stored values when the job runs, replaces the text in every value that contains it (recursing into structured values like Link or Table data), sets the values back on the element and saves it.
- **Validation:** saves use the `essentials` validation scenario, the same as `craft resave/*`, so unrelated required-field rules don't block a content fix.
- **Revisions:** a replace creates a revision for entries with revisions enabled. **Resave only** doesn't, because it marks the element as resaving.

## Limitations

- **Relation fields** (Entries, Assets, Categories, Users, Tags) store their IDs in the `relations` table, not in field content, so they can't be searched.
- **Keys in structured data:** replacement changes text values, never array keys.
- **Result cap:** results are capped at 500 elements per search. Run the replace, then search again for the rest.
- **Structured values:** a replacement inside a structured value (a Dropdown option, a Link URL, a Money amount) must still be a valid value for that field. Replacing a Dropdown value with one that isn't an option, for example, will fail or be cleared when the element saves.

## License

MIT
