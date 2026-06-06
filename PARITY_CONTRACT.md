# Trident Magento module — feature parity build contract

You are adding ONE admin feature screen to the `Qoliber_TridentCache` Magento 2 module so it
reaches parity with the Next.js admin panel. Module root:
`integrations/cms/magento/module/Qoliber/TridentCache`.

## Read these existing files FIRST as the canonical pattern
- `Controller/Adminhtml/Cache/Stats.php` — index controller (renders a page)
- `Controller/Adminhtml/Cache/PurgeTags.php` — POST action controller (calls client, redirects with message)
- `ViewModel/Stats.php` — ViewModel pattern (ArgumentInterface, injects TridentClient + Config, format helpers)
- `view/adminhtml/layout/trident_cache_stats.xml` — layout pattern
- `view/adminhtml/templates/purge.phtml` and `templates/stats.phtml` — template/UI pattern (`.trident-card`, message guards, formkey forms)
- `Model/TridentClient.php` — the API client; ALL methods you need already exist (see your feature's list below)

## Hard rules
1. Edit/create ONLY files under your feature's namespace. **Do NOT touch** `Model/TridentClient.php`,
   `etc/adminhtml/menu.xml`, `etc/acl.xml`, `Model/Config.php`, or any other feature's files — those are
   already done. The menu action `trident/<feature>/index` and ACL resource `Qoliber_TridentCache::<feature>`
   already exist for you.
2. **PHP conventions (strict):** `declare(strict_types=1);`; the qoliber author docblock header (copy it
   verbatim from an existing file); PHP 8.1 **constructor property promotion** with `readonly`; **FQDN in
   docblocks**; one-line `/** @var \Type $x */` for template var hints; never use `addFieldToFilter('x',['nin'=>[...]])`.
3. Controllers extend `\Magento\Backend\App\Action`, declare
   `public const ADMIN_RESOURCE = 'Qoliber_TridentCache::<feature>';`. Index controller returns
   `\Magento\Framework\View\Result\Page` (set active menu `Qoliber_TridentCache::<feature>` + title).
   Action controllers (run/enable/etc.) return `\Magento\Framework\Controller\Result\Redirect`, read
   `$this->getRequest()->getParams()`, call the client, push `$this->messageManager->addSuccessMessage()` /
   `addErrorMessage()`, and redirect back to the feature index. Backend POST controllers get form-key
   validation automatically.
4. ViewModels implement `\Magento\Framework\View\Element\Block\ArgumentInterface`, inject
   `\Qoliber\TridentCache\Model\TridentClient` + `\Qoliber\TridentCache\Model\Config`, and expose
   `isEnabled()` (`$client->isEnabled()`), `isTridentConfigured()` (`$config->isTridentEnabled()`), the data
   getters, plus any `formatBytes/formatNumber/formatPercentage` helpers you need (copy from ViewModel/Stats.php).
5. Layout file `view/adminhtml/layout/trident_<feature>_index.xml`: `<page>` → `<head><css
   src="Qoliber_TridentCache::css/source/_module.less"/></head>` → referenceContainer `content` → a
   `Magento\Backend\Block\Template` block with `template="Qoliber_TridentCache::<feature>.phtml"` and a
   `view_model` object argument pointing at your ViewModel.
6. Template `view/adminhtml/templates/<feature>.phtml`: start with the same `$viewModel = $block->getData('view_model');`
   prologue + the two `message message-warning` guards (`!isTridentConfigured()` / `!isEnabled()`) from purge.phtml,
   then the feature UI using `.trident-card` / `.trident-card-title` / `.trident-card-description` / `.trident-table`
   classes (these exist in `_module.less`; if you need a new class, use an existing one — do NOT edit the LESS).
   Escape everything via the `$escaper` (escapeHtml/escapeUrl/escapeJs/escapeHtmlAttr). POST forms must include
   `<?= $block->getBlockHtml('formkey') ?>` and confirm() on destructive actions.
7. Add ONE integration test under `Test/Integration/Controller/<Feature>/IndexTest.php` mirroring the style of
   existing `Test/Integration/*` (assert the controller dispatches / ACL resource). Keep it minimal and self-contained.
8. Do NOT run Magento or composer. You can `php -l <file>` to syntax-check (ignore unrelated blackfire/tideways
   startup warnings). Match the existing files' exact formatting.

## Deliverable
A working feature screen (index controller + any action controllers + ViewModel + layout + template + a test),
self-contained under your namespace, syntactically clean. Report the files you created.
