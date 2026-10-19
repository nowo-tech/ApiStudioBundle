# Templates

Templates live in `src/Resources/views/` (Twig namespace `@NowoApiStudioBundle`). Override one by copying it to
`templates/bundles/NowoApiStudioBundle/<same path>`.

## Table of contents

- [Per-locale fields](#per-locale-fields)
- [Delete confirmation](#delete-confirmation)

## Per-locale fields

| Template | Page | Rendering |
| --- | --- | --- |
| `endpoint/form.html.twig` | endpoint create / edit | `translations` (CollectionType of `ApiEndpointTranslationFormType`, FormKit option `locale_tabs`: mode `entries`, `locales` = `ui.locales`, `default_locale` = `ui.default_locale`) rendered with `@NowoFormKitBundle/form/_locale_tabs.html.twig`, tabs id `endpoint-form-locale`, the session UI locale opened |
| `endpoint/show.html.twig` | endpoint console (read-only documentation) | UiKit `nowo_ui_partial('locale_tabs')` with one panel per locale (`[data-doc-title]`, `[data-doc-description]`), tabs id `endpoint-doc-locale`; `api-endpoint-doc.js` edits the open panel's locale |

DOM ids follow UiKit: root `#<tabs id>`, buttons `#tab-<tabs id>-<locale>`, panels `#tab-<tabs id>-<locale>-panel`.
Theme slots `tabs`, `tab`, `tab_active`, `tab_panel`; switching, keyboard and the `nowo-ui:tabs:change` event come
from `nowo-ui-tabs.js` (loaded by `base.html.twig` through UiKit's `scripts` partial).

## Delete confirmation

`_delete_confirm.html.twig` (variables `id`, `action`, `token`) renders the delete button and a UiKit confirm
`<dialog>` whose footer holds the POST form with the CSRF token. Used by `endpoint/form.html.twig` and
`environment/form.html.twig`; no inline `onsubmit` / `confirm()`.
