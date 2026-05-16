# PHP Calculation Scripts in DiData — Developer Reference

**By Riad Gacem — updated with patterns from production scripts**

This document is the working reference for writing new DiData calculation scripts. It captures the patterns actually used across the codebase (`AutoAssignPosition`, `AutoAssignAliquotToBox*`, `smpl*`, `storae_path`, `stat_storage`, `ship`, etc.) so a new calculation can be built by composing pieces from here rather than starting from scratch.

---

## Table of Contents

- [1. How a calculation runs](#1-how-a-calculation-runs)
- [2. Standard script skeleton](#2-standard-script-skeleton)
- [3. Guidelines & best practices](#3-guidelines--best-practices)
- [4. Available APIs](#4-available-apis)
- [5. Field-name constants from `App\Common`](#5-field-name-constants-from-appcommon)
- [6. Query patterns (eqb)](#6-query-patterns-eqb)
- [7. Logging conventions](#7-logging-conventions)
- [8. Skip / guard patterns](#8-skip--guard-patterns)
- [9. Helper-method pattern (`addMethod` + `use`)](#9-helper-method-pattern-addmethod--use)
- [10. Merging old + new data](#10-merging-old--new-data)
- [11. Resolving Choice values](#11-resolving-choice-values)
- [12. Storage / position patterns](#12-storage--position-patterns)
- [13. Creating boxes and other entities programmatically](#13-creating-boxes-and-other-entities-programmatically)
- [14. Updating entities (with / without calculations)](#14-updating-entities-with--without-calculations)
- [15. Notifications from a calculation](#15-notifications-from-a-calculation)
- [16. Notes / log accumulation in a text field](#16-notes--log-accumulation-in-a-text-field)
- [17. Validation & user-facing errors](#17-validation--user-facing-errors)
- [18. Example calculations](#18-example-calculations)
  - [18.1 Barcode generation](#181-barcode-generation)
  - [18.2 Age from date of birth](#182-age-from-date-of-birth)
  - [18.3 Shipment date split](#183-shipment-date-split)
  - [18.4 Project suffix on box NAME](#184-project-suffix-on-box-name)
  - [18.5 Append shared-state propagation (box → samples)](#185-append-shared-state-propagation-box--samples)
  - [18.6 Auto-assign storage (next free slot)](#186-auto-assign-storage-next-free-slot)
  - [18.7 Inherit parent values on aliquot](#187-inherit-parent-values-on-aliquot)
  - [18.8 First/Last event propagation](#188-firstlast-event-propagation)
  - [18.9 Kit status inferred from samples](#189-kit-status-inferred-from-samples)
  - [18.10 Barcode-count validation](#1810-barcode-count-validation)
- [19. Constants reference (project-specific)](#19-constants-reference-project-specific)

---

## 1. How a calculation runs

Every calculation has the same execution contract:

- `$this->data` — array of fields being written (you can override values in it).
- `$this->getCurrentMode()` — `'create'` or `'update'`.
- `$this->getOldData()` — old entity data in update mode (empty/irrelevant on create).
- `$this->addMethod('name', function () { ... })` — register a helper callable as `$this->name(...)`.
- `throwValidationException([...])` — abort with a user-visible error.
- Calculations run either **before persistence** (default — can mutate `$this->data` and cancel) or **after persistence** (for side effects on other entities).

> Prefer "before persistence" when possible — it's cheaper and safer. Use "after persistence" only when you need the entity's final `id` or want to propagate to other entities.

---

## 2. Standard script skeleton

Every script in the repo follows roughly this shape. Use it as a starting template.

```php
/*** Powered by DiData
  *
  * ## Triggers when:
  *
  * <describe the event(s) that fire this calculation>
  *
  * ## Behavior:
  *
  * <describe what the script does, what it writes, what it skips>
  ***/

use App\Common;
use Didata\Entities\Repositories\Models\Entity;
use Didata\Entities\Repositories\Models\EntityType;

// ─── 1. Field names — single source of truth ──────────────────────────
$fields = [
    'name'      => 'NAME',
    'barcode'   => 'BARCODE',
    'storage'   => Common::ENTITY_FK_STORAGE_FIELD_NAME,
    // ...
];

// ─── 2. Configuration / constants ─────────────────────────────────────
$sampleEntityTypeId = 3;
$boxEntityTypeId    = 7;

// ─── 3. Helpers via addMethod ─────────────────────────────────────────
$this->addMethod('doSomething', function ($x) use ($fields) {
    // ...
});

// ─── 4. Skip guards ───────────────────────────────────────────────────
if ($this->getCurrentMode() !== 'create') return;
if ((int)($this->data['entitytype_id'] ?? 0) !== $sampleEntityTypeId) return;

// ─── 5. Main logic ────────────────────────────────────────────────────
\Log::info('MyCalc: START', ['id' => $this->data['id'] ?? null]);

// ... mutate $this->data ...

\Log::info('MyCalc: DONE');
```

---

## 3. Guidelines & best practices

### Performance

Any system with fewer than 4M entities should be fast. A calculation should normally take **< 200 ms**.

| #  | Slow (avoid)                                                                  | Replacement                                                              |
|----|-------------------------------------------------------------------------------|--------------------------------------------------------------------------|
| 1  | `eqb()->select([...])->find()` (returns full model)                          | `eqb()->select([...])->first();`                                         |
| 2  | `eqb()` without a type filter                                                 | `eqb()->entityType('NAME')` or `->where('entitytype_id', '=', $id)`      |
| 3  | After persistence (re-runs the whole calc pipeline)                          | Before persistence when possible                                          |
| 4  | `foreach ($ids as $id) { eqb()->find($id); }`                                | `eqb()->whereIn('id', $ids)->get();`                                     |
| 5  | Full-table scans for "count"                                                  | Add `entitytype_id` + indexed field filters before `->count()`           |

Always narrow the query when possible:

```php
->select(['Field1', 'Field2'])
->limit(100)
->page(1)
```

### Side effects

Entity create / update inside a calculation is expensive and can hide bugs. When you must do it, always add a `\Log::warning` so it's easy to find in production logs:

```php
\Log::warning('MyCalc: createBox', ['name' => $boxName]);
```

### Idempotency

Calculations re-run. Make sure repeated runs produce the same result:
- Check "is this already set?" before writing.
- Use de-duping (e.g., `in_array($new_barcode, $barcodes, true)` before appending).
- Use "exact match" regex anchors when updating accumulator lines (see [§16](#16-notes--log-accumulation-in-a-text-field)).

---

## 4. Available APIs

### 4.1 Fetch resources

```php
$entitytype = dac()->find('entitytype', $this->getOldData()['entitytype_id']);

eqb()->get();                                    // all rows matching the builder
eqb()->find($entity_id);                         // single row by id (returns array)
eqb()->count();                                  // count
eqb()->project(null)->count();                   // global project scope
eqb()->project(1)->count();                      // specific project
eqb()->orderBy('id', 'DESC');
eqb()->setQueryByValueGetter(true)
     ->where('Type', '=', 'Compounds')
     ->count();
```

### 4.2 Current project / context

```php
dac()->getContext()->getProject();        // may be null for Global project
dac()->getContext()->getProject()->id;

// Find project by name
App\Models\AccessRights\Projects\Project::where('name', 'project_name')->first();
```

### 4.3 Full entity (new + old merged)

```php
$entity = $this->getCurrentMode() === 'create'
    ? $this->data
    : array_merge($this->getOldData(), $this->data);
```

> ⚠️ Order matters: `array_merge($old, $new)` — new values win. Several scripts in the repo invert this by accident; always put `$this->data` (the new values) on the right.

### 4.4 Create entity

```php
$created = dac()->create('entity', [
    'entitytype_id' => 1,
    'NAME'          => 'CREATED RECORD',
]);
```

To create **without re-running calculations** (recommended for programmatic box / child creation):

```php
$repo = dac()->DBrepo('entity');
$repo->setOptions([
    'with_calculation' => false,
    'with_validation'  => true,
]);
$created  = $repo->create(dac()->getContext()->getProject()->id, $data);
$newId    = is_object($created) ? $created->id : ($created['id'] ?? null);
```

### 4.5 Update entity

```php
dac()->update(
    'entity',
    Didata\Entities\Repositories\Models\Entity::find($entity['id']),
    ['fieldName' => 'value']
);
```

Without re-running calculations:

```php
$repo = dac()->DBrepo('entity');
$repo->setOptions([
    'with_calculation' => false,
    'with_validation'  => true,
]);
$repo->update(
    Didata\Entities\Repositories\Models\Entity::find($entity['id']),
    ['fieldName' => 'value']
);
```

### 4.6 Copy file

```php
$this->addMethod('copyFile', function ($id_file) {
    $file = App\Models\File::find($id_file);
    $path = Storage::disk('user-storage')->path($file->path . '/' . $file->name);
    $f = new Illuminate\Http\File($path);
    return new Illuminate\Http\UploadedFile(
        $f->getPathname(), $f->getFilename(), $f->getMimeType(), 0, true
    );
});
```

### 4.7 Get entity files

```php
use Didata\Entities\Services\EntityFiles\GetFilesByEntityService;

$this->addMethod('getAllOrderFiles', function ($order) use ($entity_files_fields) {
    $files = [];
    $entity = Didata\Entities\Repositories\Models\Entity::find($order['id']);
    $attached = consumeService(new GetFilesByEntityService($entity));

    foreach ($entity_files_fields as $field) {
        if (isset($order[$field])) {
            $files[] = \App\Models\File::find($order[$field]);
        }
    }
    return array_merge($files, $attached->toArray());
});
```

### 4.8 Print label from a calculation

```php
use App\Services\Printers\PrintingService;
use App\Models\Printers\PrinterTemplate;

if (
    $this->getCurrentMode() === 'create'
    && $this->data['entitytype_id'] === 9
    && !empty($this->data['Print_label'])
) {
    $template = PrinterTemplate::find(1);
    consumeService(new PrintingService($template, $this->data + $this->getOldData()));
}
```

### 4.9 Validation exception

User-facing error. Shown as a normal error, not a "non-expected" exception.

```php
throwValidationException(['Message shown to the user']);
```

### 4.10 Current user

```php
$user   = getCurrentUser();
$userId = $user['id'] ?? null;
```

### 4.11 Get a Choice value (Choice fields)

```php
use Didata\Entities\Repositories\Models\Fields\Choice;

$getChoiceName = fn ($choice_id) => Choice::find($choice_id)?->value;

// In a script:
$name = (string) (\Didata\Entities\Repositories\Models\Fields\Choice::find((int)$id)?->value ?? '');
```

---

## 5. Field-name constants from `App\Common`

Use these instead of hard-coded strings whenever possible — they survive renames and document intent.

```php
use App\Common;

Common::ID_FIELD_NAME                                  // 'id'
Common::ENTITY_FK_STORAGE_FIELD_NAME                   // STORAGE link
Common::ENTITY_STORAGE_2D_POSITION_ROW_FIELD_NAME      // POSITION_ROW
Common::ENTITY_STORAGE_2D_POSITION_COLUMN_FIELD_NAME   // POSITION_COLUMN
Common::STORAGE_2D_NB_ROWS_FIELD_NAME                  // NUMBER_ROWS (on storage)
Common::STORAGE_2D_NB_COLUMNS_FIELD_NAME               // NUMBER_COLUMNS (on storage)
Common::STORAGE_NAME_FIELD_NAME                        // storage NAME
Common::STORAGE_CONTEXT_NAME                           // 'Storage'
```

### Storage entity-type IDs via the Storage context

Used in `AutoAssignPosition.php` and `storae_path.php`:

```php
use App\DidataPackages\DidataCache;
use App\Common;

$ctx = DidataCache::getContextByName(Common::STORAGE_CONTEXT_NAME);
$storageEntityTypeIds = array_map('intval', array_column($ctx['_entity_types'] ?? [], 'id'));

// or iterate all contexts
$contexts = DidataCache::getContextsById();
foreach ($contexts as $collection) {
    if ($collection['name'] === Common::STORAGE_CONTEXT_NAME) {
        $storageEntityTypeIds = array_column($collection['_entity_types'], 'id');
    }
}
```

---

## 6. Query patterns (eqb)

### 6.1 By entity type — two equivalent styles

```php
// String name (clear, but requires the name to exist)
eqb()->entityType('SMPL_SUBJECT')->where('id', '=', $id)->first();

// Numeric id (fast, used everywhere)
eqb()->where('entitytype_id', '=', $sample_entitytype_id)->get();
```

### 6.2 Common predicates

```php
->where('FIELD', '=', $value)
->where('FIELD', 'has', null)           // field is set / not-null check
->where('FIELD', 'contain', $needle)    // substring (used for Storage_Path)
->whereIn('id', [1, 2, 3])
->orderBy('created_at', 'desc')
->orderBy('id', 'DESC')
```

### 6.3 Selecting / paging

```php
eqb()->select(['id', 'NAME', 'BARCODE'])
     ->where('entitytype_id', '=', $box_entitytype_id)
     ->orderBy('id', 'DESC')
     ->limit(100)
     ->page(1)
     ->get();
```

### 6.4 First / single

```php
$row  = eqb()->where('id', '=', $id)->first();          // null if not found
$row  = eqb()->where('id', '=', $id)->first('id');      // single field
$rows = eqb()->where('id', '=', $id)->get();            // always array
```

### 6.5 Strict "exactly one"

```php
$predecessor = dac()
    ->getQueryFromCurrentProject('entity')
    ->select([$storage_field, Common::STORAGE_NAME_FIELD_NAME, 'entitytype_id'])
    ->where('id', '=', $predecessorId)
    ->getIfOneEntityOrThrow();
```

### 6.6 Checkbox fields — beware of `=`

Checkbox fields in eqb don't support `=` reliably. Filter in PHP after fetching:

```php
$rows = eqb()
    ->select(['id', 'Is_Aliquot'])
    ->where('entitytype_id', '=', $aliquotEntityTypeId)
    ->where('SUBJECT',       '=', $subjectId)
    ->get();

$count = 0;
foreach ($rows as $r) {
    $v = $r['Is_Aliquot'] ?? null;
    if ($v === true || $v === 1 || $v === '1') $count++;
}
```

(Pattern from `AutoAssignAliquotToBoxBySubject.php`.)

---

## 7. Logging conventions

| Level             | When to use                                                                  |
|-------------------|------------------------------------------------------------------------------|
| `\Log::debug`     | Troubleshooting only. Don't leave debug logs everywhere.                     |
| `\Log::info`      | Normal lifecycle events: `SCRIPT LOADED`, `START`, `DONE`, decision branches.|
| `\Log::warning`   | Risky operations — `createBox`, `update`, anything that should be reviewable.|
| `\Log::error`     | Failures, exceptions, missing required references.                          |

Tag every log with the script name so they're greppable:

```php
\Log::info('AutoAssignAliquotToBox: START', ['sample_type_id' => $sampleTypeId]);
\Log::warning('AutoAssignAliquotToBox: createBox', ['name' => $name]);
\Log::error('AutoAssignAliquotToBox: createBox FAILED', ['error' => $e->getMessage()]);
```

Pass structured data as the second argument (array) — easier to query in log tooling than concatenated strings.

---

## 8. Skip / guard patterns

Most scripts exit early. Standard guards, in order:

```php
// 1. Mode
if ($this->getCurrentMode() !== 'create') return;

// 2. Entity type
if ((int)($this->data['entitytype_id'] ?? 0) !== $sampleEntityTypeId) return;

// 3. Required input
if (empty($this->data['BARCODE'])) return;

// 4. Already set — don't overwrite
if (!empty($this->data[$fields['storage']])) {
    \Log::debug('MyCalc: STORAGE already set, skipping');
    return;
}
```

### `shouldSkip` as a helper

For more complex skip logic (e.g., update-mode storage transitions), encapsulate it:

```php
$this->addMethod('shouldSkip', function () use ($fields, $sampleEntityTypeId): bool {
    $raw = $this->data;
    if ((int)($raw['entitytype_id'] ?? 0) !== $sampleEntityTypeId) return true;
    if ($this->getCurrentMode() === 'create') return false;

    $old = $this->getOldData();
    $oldStorage = $old[$fields['storage']]   ?? null;
    $newStorage = $raw[$fields['storage']]   ?? null;

    if (array_key_exists($fields['storage'], $raw) && $newStorage != $oldStorage) {
        if (empty($newStorage) && !empty($oldStorage)) return true;  // clearing
        return false;
    }
    if (!empty($oldStorage)) return true;  // already stored, not changing
    return false;
});

if ($this->shouldSkip()) return;
```

(Pattern from `AutoAssignPosition.php`.)

---

## 9. Helper-method pattern (`addMethod` + `use`)

`addMethod` is the only way to define reusable helpers inside a calculation. Closures **don't auto-capture** outer scope — pass everything you need through `use`:

```php
$fields    = ['storage' => 'STORAGE', 'name' => 'NAME'];
$boxTypeId = 7;

$this->addMethod('createBox', function (string $name, int $rows, int $cols)
    use ($fields, $boxTypeId): ?int
{
    $data = [
        'entitytype_id'       => $boxTypeId,
        $fields['name']       => $name,
        // ...
    ];
    // ...
});

// Call it:
$boxId = $this->createBox('Box_42', 9, 9);
```

> Helpers can call other helpers via `$this->otherHelper(...)`. They share the same `$this->data`.

---

## 10. Merging old + new data

Calculations on update get a `$this->data` containing only the changed fields. To work with the full record:

```php
$entity = $this->getCurrentMode() === 'create'
    ? $this->data
    : array_merge($this->getOldData(), $this->data);
```

> **Always merge in this order** — old first, new wins. Several scripts in the repo had this inverted (`array_merge($this->data, $this->getOldData())`), which silently uses stale values. If you write to `$this->data` later, mutate `$this->data` directly, not the merged variable.

---

## 11. Resolving Choice values

Choice fields store the choice ID (integer). To get the display value:

```php
use Didata\Entities\Repositories\Models\Fields\Choice;

$choice = Choice::find((int) $sampleTypeId);
$name   = (string) ($choice?->value ?? 'UNKNOWN');
```

Use it for naming: e.g., when building `Box_{SampleTypeName}_{n}`, normalize non-alphanumerics to underscores:

```php
$this->addMethod('normalizeName', function (string $s): string {
    return trim(preg_replace('/[^A-Za-z0-9]+/', '_', $s), '_');
});

$rawName  = (string) (Choice::find($sampleTypeId)?->value ?? 'UNKNOWN');
$safeName = $this->normalizeName($rawName);   // "Blood (whole)" → "Blood_whole"
```

---

## 12. Storage / position patterns

### 12.1 Check if a position is occupied

```php
$this->addMethod('isPositionOccupied', function (int $boxId, int $row, int $col) use ($fields): bool {
    $hit = eqb()
        ->where($fields['storage'],         '=', $boxId)
        ->where($fields['position_row'],    '=', $row)
        ->where($fields['position_column'], '=', $col)
        ->first('id');
    return $hit !== null && $hit !== false;
});
```

### 12.2 Find next free position (row-major scan)

```php
$this->addMethod('findNextFreePosition', function (int $boxId, int $rows, int $cols): ?array {
    for ($r = 1; $r <= $rows; $r++) {
        for ($c = 1; $c <= $cols; $c++) {
            if (!$this->isPositionOccupied($boxId, $r, $c)) {
                return ['row' => $r, 'column' => $c];
            }
        }
    }
    return null;   // box full
});
```

### 12.3 Use wanted-or-next-free

```php
$this->addMethod('resolvePosition', function (array $storage, int $wRow, int $wCol) use ($fields): ?array {
    $id = (int) $storage['id'];
    if (!$this->isPositionOccupied($id, $wRow, $wCol)) {
        return ['row' => $wRow, 'column' => $wCol];
    }
    return $this->findNextFreePosition($id,
        (int) $storage[$fields['number_rows']],
        (int) $storage[$fields['number_columns']]
    );
});
```

### 12.4 Convert a single-cell position to row + column

```php
$pos = (int) $this->data['POS_NUMBER'];
$nbCols = (int) ($storage['NUMBER_COLUMNS'] ?? 10);

if ($pos % $nbCols === 0) {
    $this->data['POSITION_ROW']    = (int)($pos / $nbCols);
    $this->data['POSITION_COLUMN'] = $nbCols;
} else {
    $this->data['POSITION_ROW']    = (int)($pos / $nbCols) + 1;
    $this->data['POSITION_COLUMN'] = $pos % $nbCols;
}
```

### 12.5 Convert alphabetic row/column to numeric

```php
$this->addMethod('alphabet_to_num', function ($v) {
    return ctype_alpha($v) ? ord(strtoupper($v)) - ord('A') + 1 : (int) $v;
});
```

### 12.6 Build a `Storage_Path` string

The format used in `storae_path.php` and `AutoAssignAliquotToBox.php`:

```
Freezer -> Shelf -> Rack -> Box [03, 07]
```

Build it with a recursive `resolvePath` that walks up the `STORAGE` chain, then append `[row, column]`:

```php
$this->data[$storage_path_field] =
    $this->formatPath($storagesPaths) . ' [' . $row . ', ' . $column . ']';
```

When a storage entity (freezer, rack, etc.) is renamed, update children paths recursively (see `storae_path.php`, `updateChildrenPath`).

---

## 13. Creating boxes and other entities programmatically

The repo's standard "create box" helper (`AutoAssignAliquotToBox*`, `AutoAssignPosition.php`):

```php
$this->addMethod('createBox', function (string $name, int $rows, int $cols, $sampleTypeId)
    use ($fields, $boxEntityTypeId): ?int
{
    $data = [
        'entitytype_id'              => $boxEntityTypeId,
        $fields['name']              => $name,
        $fields['barcode']           => $name,
        $fields['number_rows']       => $rows,
        $fields['number_columns']    => $cols,
        $fields['sample_type']       => $sampleTypeId,
    ];

    \Log::warning('createBox', ['name' => $name, 'dim' => "{$rows}x{$cols}"]);

    try {
        $repo = dac()->DBrepo('entity');
        $repo->setOptions(['with_calculation' => false, 'with_validation' => true]);
        $created = $repo->create(dac()->getContext()->getProject()->id, $data);
        $id = is_object($created) ? $created->id : ($created['id'] ?? null);
        if (!$id) { \Log::error('createBox: no ID'); return null; }
        return (int) $id;
    } catch (\Exception $e) {
        \Log::error('createBox FAILED', ['error' => $e->getMessage()]);
        return null;
    }
});
```

Two important choices in this helper:

- `with_calculation => false` — prevents the new box from re-triggering this calculation (or others) recursively.
- `with_validation => true` — still enforces field validation, so corrupt data is rejected.

---

## 14. Updating entities (with / without calculations)

For propagating values from a parent (box) to children (samples), as in `ship.php`:

```php
$repo = dac()->DBrepo('entity');
$repo->setOptions([
    'with_calculation' => false,
    'with_validation'  => true,
]);
$repo->update(
    Didata\Entities\Repositories\Models\Entity::find($sample['id']),
    $sample
);
```

Wrap each update in try/catch — one bad sample shouldn't kill the whole batch:

```php
foreach ($samples as $sample) {
    try {
        $repo->update(Entity::find($sample['id']), $sample);
        \Log::info('Sample updated', ['id' => $sample['id']]);
    } catch (\Exception $e) {
        \Log::error('Sample update FAILED', ['id' => $sample['id'], 'err' => $e->getMessage()]);
    }
}
```

---

## 15. Notifications from a calculation

Pattern from `AutoAssignPosition.php` — fire an in-app notification to the current user via `OperationsRunnerService`:

```php
$this->addMethod('sendBoxCreatedNotification', function (int $boxId, string $boxName)
    use ($notificationOperationId): void
{
    $userId = getCurrentUser()['id'] ?? null;
    if (!$userId) return;

    $payload = [
        'title'       => "New Box Auto-Created: \"{$boxName}\"",
        'content'     => "Box #{$boxId} was created in temporary storage.\nPlease assign a final location.",
        'php_script'  => null,
        'channels'    => ['in-app'],
        'user_ids'    => [$userId],
        'resource_id' => $boxId,
    ];

    try {
        $op = \App\Models\Actions\Operation::find($notificationOperationId);
        if (!$op) return;
        consumeService(new \App\Services\Actions\OperationsRunnerService($op, $payload));
    } catch (\Exception $e) {
        \Log::error('Notification failed', ['err' => $e->getMessage()]);
    }
});
```

> The operation ID (`20` in the JH pilot) refers to a pre-configured Notification operation in the platform's Operations admin. You can't send notifications without one.

For the alternative "event-based" notification (e.g., upcoming maintenance), use:

```php
$entities = dac()->getQueryFromCurrentProject('entity')
    ->where('Prochaine_maintenance', '<', today())
    ->get();
$this->addEventValue('expired', json_encode($entities, JSON_PRETTY_PRINT));
$this->isChecked = true;
```

…with a notification template that renders `{{!!$eventData['expired']!!}}`.

> For **dev mode**, notifications and batch import queues need workers running:
>
> ```bash
> php artisan schedule:work        # Notifications
> php artisan queue:work database  # Batch import
> ```

---

## 16. Notes / log accumulation in a text field

Pattern from `smplCreateChildrenLog`, `smplCreateChildrenLogAll`, and `smplAppendBarcodeToLog`: maintain a history of summary lines in a text field, aggregating events that share a key (e.g., same timestamp) and preserving older lines.

### 16.1 Increment-or-append by exact-match line

```php
$line_prefix = 'Generated children';
$batch_date  = (string) ($this->data['Parent_Batch_Date'] ?? '');

// Matches "...Generated children N VIALS (08-05-2026 13:08:08)..."
$pattern = '/[^\r\n]*' . preg_quote($line_prefix, '/')
         . '\s+(\d+)\s+VIALS\s+\(' . preg_quote($batch_date, '/') . '\)'
         . '[^\r\n]*\r?\n?/';

if (preg_match($pattern, $existing_notes, $m)) {
    $run_count = ((int) $m[1]) + 1;
    $cleaned   = preg_replace($pattern, '', $existing_notes);
} else {
    $run_count = 1;
    $cleaned   = $existing_notes;
}

$cleaned   = trim(preg_replace('/(\r?\n){2,}/', "\n", $cleaned));
$new_line  = $line_prefix . ' ' . $run_count . ' VIALS (' . $batch_date . ')';
$new_notes = ($cleaned !== '' ? $cleaned . "\n" : '') . $new_line;
```

### 16.2 Append a value to an existing line (de-duped, comma-separated)

```php
$line_pattern = '/(Generated children\s+\d+\s+VIALS\s+\('
              . preg_quote($batch_date, '/') . '\))([^\r\n]*)/';

if (preg_match($line_pattern, $existing_notes, $m)) {
    $head     = $m[1];
    $tail     = trim($m[2]);
    $barcodes = $tail === '' ? [] : preg_split('/[\s,]+/', $tail, -1, PREG_SPLIT_NO_EMPTY);

    if (!in_array($new_barcode, $barcodes, true)) {
        $barcodes[] = $new_barcode;
        $new_line   = $head . ' ' . implode(',', $barcodes);
        $new_notes  = preg_replace($line_pattern, $new_line, $existing_notes, 1);
    }
}
```

Why exact-match on the timestamp/batch key: different events get different lines, so history is preserved automatically. Two events with the same key (same second) aggregate into one line.

---

## 17. Validation & user-facing errors

Use `throwValidationException` — never `throw new \Exception`, which surfaces as an opaque "non-expected" error:

```php
throwValidationException([
    "Aliquot must have 'SUBJECT' and 'smpl_sample_type' before auto-assignment.",
]);
```

You can pass multiple messages — they all render:

```php
throwValidationException([
    "Box is full: '{$boxName}'",
    "Please create a new box with sample type '{$typeName}'",
]);
```

### Validation rule pattern (separate from a calculation)

If you're writing a **Rule** (not a calculation), the contract is different — set `$this->error` / `$this->message`:

```php
$this->error = false;

if (isset($this->newData['STORAGE'])) {
    $storage    = eqb()::find($this->newData['STORAGE']);
    $stored     = eqb()->where('STORAGE', '=', $storage['id'])->count();
    $capacity   = $storage['NUMBER_ROWS'] * $storage['NUMBER_COLUMNS'];
    if ($stored > $capacity) {
        $this->error   = true;
        $this->message = "Storage full; current capacity: {$stored}";
    }
}
```

---

## 18. Example calculations

### 18.1 Barcode generation

Two flavors are used in the project:

**Simple sequential (by id), set on create**

```php
$sample_entitytype = 3; $patient_entitytype = 1; $visit_entitytype = 2;
$freezer_entitytype = 4; $shelf_entitytype = 5; $rack_entitytype = 6; $box_entitytype = 7;
$barcode_field = 'BARCODE'; $name_field = 'NAME';

$this->addMethod('assignBarcode',
    function ($etypeId, $prefix, $is_storage, $length = '5')
    use ($barcode_field, $name_field)
{
    if ($this->data['entitytype_id'] === $etypeId) {
        $this->data[$barcode_field] = sprintf($prefix . '%0' . $length . 'd', $this->data['id']);
        if ($is_storage && !isset($this->data[$name_field])) {
            $this->data[$name_field] = $this->data[$barcode_field];
        }
    }
});

if ($this->getCurrentMode() === 'create' && !isset($this->data[$barcode_field])) {
    $this->assignBarcode($sample_entitytype,  'S',  false);
    $this->assignBarcode($patient_entitytype, 'P',  false);
    $this->assignBarcode($visit_entitytype,   'V',  false);
    $this->assignBarcode($freezer_entitytype, 'F',  true, '4');
    $this->assignBarcode($shelf_entitytype,   'SH', true);
    $this->assignBarcode($rack_entitytype,    'R',  true);
    $this->assignBarcode($box_entitytype,     'B',  true);
}
```

**Composite with parent + sibling counter** (`test_barcode_calculation.php`):

```php
// Sample (DNA type) → {parent_barcode}D{sibling_count+1}
$parent = eqb()
    ->select(['id', 'BARCODE'])
    ->where('id', '=', $parentId)
    ->get()[0] ?? null;

$parentBarcode = $parent['BARCODE'] ?? ('S' . str_pad((string) $parentId, 5, '0', STR_PAD_LEFT));

$siblingCount = dac()
    ->getQueryFromCurrentProject('entity')
    ->where('parent_sample',    '=', $parentId)
    ->where('sample_type_test', '=', SAMPLE_TYPE_DNA)
    ->where('BARCODE',          'has', null)
    ->count();

$this->data['BARCODE'] = $parentBarcode . 'D' . ($siblingCount + 1);
```

**Composite from workflow line + subject** (`smplSampleBarcode.php`):

```php
// {patientId}-{lineLabel}{tubeIndex}  e.g. 0001-URINE1
$subject  = eqb()->entityType('SMPL_SUBJECT')
    ->select(['smpl_id'])->where('id', '=', $subjectId)->first();
$line     = eqb()->entityType('SMPL_WORKFLOW_LINE')
    ->select(['smpl_label', 'smpl_id_gen_fk'])->where('id', '=', $lineId)->first();

if (!empty($line['smpl_id_gen_fk'])) return;   // delegated to other generator

$tubeCount = eqb()->entityType('SMPL_SAMPLE')
    ->where('smpl_workflow_line_fk', '=', $lineId)->count();
$tubeIdx   = ($this->data['smpl_order'] ?? 0) + $tubeCount + 1;

$this->data['BARCODE'] = $subject['smpl_id'] . '-' . $line['smpl_label'] . $tubeIdx;
```

### 18.2 Age from date of birth

```php
// Date format: DD-MM-YYYY, stored in smpl_subject_dob
if (empty($this->data['smpl_subject_dob'])) return;

$parts = explode('-', $this->data['smpl_subject_dob']);
if (count($parts) !== 3) return;
[$day, $month, $year] = $parts;
if (!is_numeric($day) || !is_numeric($month) || !is_numeric($year)) return;

$dob = new \DateTime("{$year}-{$month}-{$day}");
$this->data['sample_subject_age'] = (new \DateTime('today'))->diff($dob)->y;
```

Verbose "Age full" with years/months/days:

```php
$diff = date_diff(date_create($this->data['Date_of_birth']), date_create(date('Y-m-d')));
$y = $diff->format('%y'); $m = $diff->format('%m'); $d = $diff->format('%d');

if ($diff->format('%R') === '-')           $this->data['Age_full'] = 'NOT BORN YET';
elseif ((int) $diff->format('%a') === 0)   $this->data['Age_full'] = 'Today';
else $this->data['Age_full'] = trim(
    ($y > 0 ? "$y " . ($y === 1 ? 'year ' : 'years ') : '')
  . ($m > 0 ? "$m " . ($m === 1 ? 'month ' : 'months ') : '')
  . ($d > 0 ? "$d " . ($d === 1 ? 'day' : 'days') : '')
);
```

### 18.3 Shipment date split

```php
if (!empty($this->data['Shipment_Full_Date_'])) {
    $d = DateTime::createFromFormat('m-d-Y', $this->data['Shipment_Full_Date_']);
    if ($d) {
        $this->data['shipment_date']  = $d->format('d');
        $this->data['shipment_year']  = $d->format('Y');
        $this->data['shipment_month'] = $d->format('F');
    } else {
        $this->data['shipment_date']  = null;
        $this->data['shipment_year']  = null;
        $this->data['shipment_month'] = null;
    }
}
```

### 18.4 Project suffix on box NAME

`addProjectSuffixToBoxes.php` — add project code to a box NAME/BARCODE on create:

```php
$this->addMethod('getProjectSuffix', function ($projectId) use ($project_code_field) {
    $project = eqb()->find($projectId);
    if (isset($project[$project_code_field])) return $project[$project_code_field];
    throw new Exception('Project must have a code');
});

if (
    $this->getCurrentMode() === 'create'
    && $this->data['entitytype_id'] === $box_entitytype_id
    && !empty($this->data[$project_link_field])
) {
    $suffix = $this->getProjectSuffix($this->data[$project_link_field]);
    if (isset($this->data[$name_field])) {
        $this->data[$name_field]    = $this->data[$name_field] . '_' . $suffix;
        $this->data[$barcode_field] = $this->data[$name_field];
    } elseif (isset($this->data[$barcode_field])) {
        $this->data[$barcode_field] = $this->data[$barcode_field] . '_' . $suffix;
        $this->data[$name_field]    = $this->data[$barcode_field];
    }
}
```

### 18.5 Propagate state from a parent (box → samples)

`ship.php` — when a box's shipping status changes, push it to every sample stored inside:

```php
$this->addMethod('propagateShippingValues', function ($boxId)
    use ($shipped_by_field, $status_field, $sample_entitytype_id)
{
    if (!$boxId) return;
    $samples = eqb()
        ->where('entitytype_id', '=', $sample_entitytype_id)
        ->where('STORAGE',       '=', $boxId)
        ->get();

    $repo = dac()->DBrepo('entity');
    $repo->setOptions(['with_calculation' => false, 'with_validation' => true]);

    foreach ($samples as $s) {
        $s[$shipped_by_field] = $this->data[$shipped_by_field] ?? $s[$shipped_by_field] ?? null;
        $s[$status_field]     = $this->data[$status_field]     ?? $s[$status_field]     ?? null;

        try {
            $repo->update(Didata\Entities\Repositories\Models\Entity::find($s['id']), $s);
        } catch (\Exception $e) {
            \Log::error('propagate failed', ['id' => $s['id'], 'err' => $e->getMessage()]);
        }
    }
});

if (
    $this->getCurrentMode() === 'update'
    && isset($this->data[$status_field])
    && $this->data['entitytype_id'] === $box_entitytype_id
) {
    $this->propagateShippingValues($this->getOldData()['id'] ?? null);
}
```

### 18.6 Auto-assign storage (next free slot)

Distilled from `AutoAssignAliquotToBox.php`:

```php
$placedBox = null;
$placedPos = null;

// 1. Try existing matching boxes, newest first
foreach ($this->findMatchingBoxes($sampleTypeId) as $box) {
    $pos = $this->findNextFreePosition((int) $box['id'], 9, 9);
    if ($pos) { $placedBox = $box; $placedPos = $pos; break; }
}

// 2. None free → create one
if (!$placedBox) {
    $seq    = $this->countBoxesForSampleType($sampleTypeId) + 1;
    $name   = "Box_{$sampleTypeId}_{$seq}";
    $newId  = $this->createBox($name, 9, 9, $sampleTypeId)
        ?? throwValidationException(["Failed to create '{$name}'."]);
    $placedBox = ['id' => $newId, 'NAME' => $name];
    $placedPos = ['row' => 1, 'column' => 1];
}

$this->data['STORAGE']         = (int) $placedBox['id'];
$this->data['POSITION_ROW']    = $placedPos['row'];
$this->data['POSITION_COLUMN'] = $placedPos['column'];
$this->data['Storage_Path']    = "{$placedBox['NAME']} [{$placedPos['row']}, {$placedPos['column']}]";
```

### 18.7 Inherit parent values on aliquot

```php
$ignore = [
    'id', 'PARENT', 'BARCODE', 'STORAGE',
    'POSITION_ROW', 'POSITION_COLUMN',
    'Provided_Document', 'Inherit_parent_values',
];

if (
    $this->getCurrentMode() === 'create'
    && !empty($this->data['PARENT'])
    && !empty($this->data['Inherit_parent_values'])
) {
    $parent = eqb()->find($this->data['PARENT']);
    foreach ($ignore as $f) unset($parent[$f]);
    $this->data = array_merge($parent, $this->data);   // new values win
}
```

### 18.8 First/Last event propagation

From `smplEventPropagation.php` — when a `SMPL_*` event is saved, update each linked sample's `smpl_first_*` / `smpl_last_*` reference.

```php
use Didata\Entities\Repositories\Models\EntityType;
use Didata\Entities\Repositories\Models\Entity;

$event = $this->getCurrentMode() === 'create'
    ? $this->data
    : array_merge($this->getOldData(), $this->data);

$type = EntityType::find($event['entitytype_id'])?->name;

$map = [
    'SMPL_RECEPTION'       => ['smpl_first_reception',       'smpl_last_reception'],
    'SMPL_TRANSPORTATION'  => ['smpl_first_transportation',  'smpl_last_transportation'],
    'SMPL_STORAGE'         => ['smpl_first_storage',         'smpl_last_storage'],
    'SMPL_CENTRIFUGATION'  => ['smpl_first_centrifugation',  'smpl_last_centrifugation'],
    'SMPL_ANALYSIS'        => ['smpl_first_analysis',        'smpl_last_analysis'],
    'SMPL_PROCESSING'      => ['smpl_first_processing',      'smpl_last_processing'],
];

if (!isset($map[$type])) return;
[$firstField, $lastField] = $map[$type];

foreach ($event['smpl_samples_fk'] as $sampleId) {
    $events = eqb()->entityType($type)
        ->where('smpl_samples_fk', 'contain', $sampleId)
        ->orderBy('smpl_event_start_time', 'asc')
        ->get();

    dac()->update('entity', Entity::find($sampleId), [
        $firstField => $events[0]['id']                  ?? null,
        $lastField  => $events[count($events) - 1]['id'] ?? null,
    ]);
}
```

### 18.9 Kit status inferred from samples

`smplKitStatusInference.php` — derive a kit's status by aggregating the sequence numbers of its samples' statuses. Pattern: load all statuses once, map id → seq, classify by min/max/uniqueness.

```php
$statuses    = eqb()->entityType('SMPL_STATUS')->get();
$idToSeq     = array_column($statuses, 'smpl_seq_num', 'id');
$kitStatuses = eqb()->entityType('SMPL_KIT_STATUS')->get();
$seqToKitId  = array_column($kitStatuses, 'id', 'smpl_seq_num');

$samples = eqb()->entityType('SMPL_SAMPLE')
    ->where('smpl_kit_fk', '=', $kitId)->get();
$seqs = array_map(fn ($s) => $idToSeq[$s['smpl_sample_status_fk']] ?? null, $samples);

// Classification → write smpl_id_nb and kit status (see source for full table).
```

### 18.10 Barcode-count validation

`smplCreationBarcodeValidation.php` — confirm the number of barcodes matches expected quantity:

```php
$smplBarcodes = $entity['smpl_barcodes'] ?? null;
$smplQty      = $entity['smpl_workflow_line_quantity'] ?? null;
if (empty($smplBarcodes)) return;

$count = count(preg_split('/[\s,]+/', trim($smplBarcodes), -1, PREG_SPLIT_NO_EMPTY));
if ((int) $smplQty !== $count) {
    throwValidationException([
        "The number of barcodes ({$count}) does not match the expected quantity ("
        . (int) $smplQty . ').',
    ]);
}
```

---

## 19. Constants reference (project-specific)

These values are pinned in the Johns Hopkins pilot. New scripts should pull from `App\Common` for field names and from the entity-type catalog for IDs, but the literals are useful when reading existing code.

### Entity-type IDs

| ID  | Name (typical)        |
|-----|-----------------------|
| 1   | Patient               |
| 2   | Visit                 |
| 3   | Sample / Aliquot      |
| 4   | Freezer               |
| 5   | Shelf                 |
| 6   | Rack                  |
| 7   | Box                   |
| 11  | Liquid Nitrogen tank  |
| 13  | Master Cell Line      |
| 14  | Room / Cryovial Sample (project-dependent) |
| 15  | Container             |

> Same numeric IDs are reused with different *meanings* across projects (e.g., `14 = Room` in `storae_path.php`, but `14 = Cryovial Sample` in `smplCreateChildrenLog.php`). Always double-check the entity-type list for the project you're working in.

### Common field names

| Field                     | Meaning                                    |
|---------------------------|--------------------------------------------|
| `NAME`                    | Entity display name                         |
| `BARCODE`                 | Barcode (unique-ish identifier)             |
| `STORAGE`                 | FK to parent storage entity                 |
| `POSITION_ROW`            | Row inside a 2D storage                     |
| `POSITION_COLUMN`         | Column inside a 2D storage                  |
| `NUMBER_ROWS`             | Storage capacity rows                        |
| `NUMBER_COLUMNS`          | Storage capacity columns                     |
| `Storage_Path`            | "Freezer -> Shelf -> ... [r, c]"           |
| `PARENT`                  | Generic parent link (samples, aliquots)     |
| `smpl_sample_type`        | Choice field — sample type                  |
| `smpl_subject_fk`         | FK to SMPL_SUBJECT                          |
| `smpl_workflow_line_fk`   | FK to SMPL_WORKFLOW_LINE                    |
| `smpl_id`                 | Patient/subject ID string                    |
| `Is_Aliquot`              | Checkbox — distinguishes aliquots           |

### Other pinned values (JH pilot)

- Temporary storage id: `45859`
- Shipping status "Shipped to USA": `24`
- Notification operation id: `20`
- Default box dimensions: `9 × 9`
- Occupation level Choice IDs: empty `54`, < half `55`, half `56`, almost full `57`, full `58`

---

*Last updated: extracted from production scripts in this project.*
