# PHP Scripts in DiData - CALC

**By Riad Gacem**

---

## Table of Contents

- [Guidelines & Best Practices](#guidelines--best-practices)
  - [Optimize Performance](#optimize-performance)
  - [Logs](#logs)
- [Available APIs](#available-apis)
  - [Fetch Resource](#fetch-resource)
  - [Fetch Project](#fetch-project)
  - [Get Full Entity Data (New & Old)](#get-full-entity-data-new--old)
  - [Create Entity](#create-entity)
  - [Update Entity](#update-entity)
  - [Copy File](#copy-file)
  - [Print Label Using Calculation](#print-label-using-calculation)
  - [Throw Validation Exception](#throw-validation-exception)
  - [Get Choice Name](#get-choice-name)
- [Example Calculations](#example-calculations)
  - [Barcodes](#barcodes)
  - [Thaw Cycle](#thaw-cycle)
  - [Automatically Create Parent on Excel Import](#automatically-create-parent-on-excel-import)
  - [Inherit Parent Values](#inherit-parent-values)
  - [Rule - Storage Full](#rule---storage-full)
  - [Expiry Stock / Maintenance - Notification](#expiry-stock--maintenance---notification)
  - [Shipment - Change Project](#shipment---change-project)
  - [Aliquot - Inherit Values](#aliquot---inherit-values)
  - [Age](#age)
  - [Date of Birth](#date-of-birth)
  - [Collection Tubes](#collection-tubes)
  - [Storage Position to Row-Column](#storage-position-to-row-column)
  - [Storage Row-Column from Alphabet to Numeric](#storage-row-column-from-alphabet-to-numeric)
  - [Get Logged In User Info](#get-logged-in-user-info)
  - [Date Formatting for Printing (MM-DD-YY)](#date-formatting-for-printing-mm-dd-yy)
  - [Store Sample Based on Type + Study](#store-sample-based-on-type--study)
  - [Get Entity Files](#get-entity-files)

---

> **Note:** For notifications and large batch imports in DEV mode, ensure to run:
>
> ```bash
> php artisan schedule:work        # Notifications
> php artisan queue:work database   # Batch import
> ```

---

## Guidelines & Best Practices

### Optimize Performance

Any system with less than 4M entities should be fast. All calculations should take less than **200 ms** in execution.

| #  | Slow (Avoid)                                                                 | Replacement                                                                                      |
|----|------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------|
| 1  | `eqb()->select(['fields names' ...])->find()`                               | `eqb()->select(['fields names' ...])->first();`                                                 |
| 2  | `eqb()`                                                                      | `eqb()->entitytype('NAME')` — use Entity type filter when possible                              |
| 3  | After persistence                                                            | Use "Before persistence" when possible                                                           |
| 4  | Find multiple entities in multiple requests: `foreach() { eqb()->find('id'); }` | Get all records in one request: `eqb()->whereIn([IDs]);`                                        |

When possible, always use these methods:

```php
->select(['Field names']);
->limit(100);
->page(1);
```

### Logs

- Use `\Log::info` for logs that should be shown most of the time.
- Use `\Log::debug` for logs that are only needed for debugging / troubleshooting.
- Use `\Log::warning` to log any risky operation like fetch, update, or create — or anything that should be avoided in the future.

> **Important:** Avoid Entity Create or Update when possible within calculations because they are greedy and can lead to performance issues or hidden issues. Always add a `Log::warning` to easily identify these elements in case of issues.

---

## Available APIs

### Fetch Resource

```php
$entitytype = dac()->find('entitytype', $this->getOldData()['entitytype_id']);
eqb()->get();
eqb()->find($entity_id);
eqb()->count();
eqb()->project(null)->count();
eqb()->project(1)->count();
eqb()->orderBy("id", "DESC");
eqb()->setQueryByValueGetter(true)->where("Type", "=", 'Compounds')->count();
```

### Fetch Project

```php
// Get the current project
dac()->getContext()->getProject()       // Can be null for Global project
dac()->getContext()->getProject()->id

// Find project using a NAME
App\Models\AccessRights\Projects\Project::where('name', 'project_name')->first();
```

### Get Full Entity Data (New & Old)

```php
$entity = $this->getCurrentMode() == 'create'
    ? $this->data
    : array_merge($this->getOldData(), $this->data);
```

### Create Entity

```php
$created = dac()->create('entity', [
    'entitytype_id' => 1,
    'NAME' => 'CREATED RECORD'
]);

// OPTION TO CREATE WITHOUT CALCULATIONS
$repo = dac()->DBrepo('entity');
$repo->setOptions([
    'with_calculation' => false,
    'with_validation' => true
]);
$repo->create($project_id, ['fieldName' => 'value']);
```

### Update Entity

```php
dac()->update(
    'entity',
    Didata\Entities\Repositories\Models\Entity::find($entity['id']),
    ['fieldName' => 'value']
);

// OPTION TO UPDATE WITHOUT CALCULATIONS
$repo = dac()->DBrepo('entity');
$repo->setOptions([
    'with_calculation' => false,
    'with_validation' => true
]);
$repo->update(
    Didata\Entities\Repositories\Models\Entity::find($this->data[$entity['id']]),
    ['fieldName' => 'value']
);
```

### Copy File

```php
$this->addMethod("copyFile", function($id_file) {
    $path = Storage::disk('user-storage')->path(
        ($file = App\Models\File::find($id_file))->path . '/' . $file->name
    );
    $file = new Illuminate\Http\File($path);
    $new_image = new Illuminate\Http\UploadedFile(
        $file->getPathname(),
        $file->getFilename(),
        $file->getMimeType(),
        0,
        true
    );
    return $new_image;
});
```

### Print Label Using Calculation

```php
use App\Services\Printers\PrintingService;
use App\Models\Printers\PrinterTemplate;

$entity = array_merge($this->getOldData(), $this->data);

if (
    $this->getCurrentMode() == 'create'
    && $this->data['entitytype_id'] == 9
    && isset($this->data['Print_label'])
    && $this->data['Print_label'] == true
) {
    $templateId = 1;
    $template = PrinterTemplate::find($templateId);
    consumeService(new PrintingService($template, $this->data + $this->getOldData()));
}
```

### Throw Validation Exception

Validation exceptions are shown as regular errors for end-users (not as "non-expected" exceptions).

```php
throwValidationException(["Message to show user"]);
```

### Get Choice Name

```php
use Didata\Entities\Repositories\Models\Fields\Choice;

$getChoiceName = function ($choice_id) {
    return Choice::find($choice_id)?->value;
};
```

---

## Example Calculations

### Barcodes

```php
$sample_entitytype = 9;
$freezer_entitytype = 5;
$shelf_entitytype = 6;
$rack_entitytype = 7;
$box_entitytype = 8;
$barcode_field = 'BARCODE';
$name_field = 'NAME';
$entitytype_id_field = 'entitytype_id';

// Only assign if not exist
// If is storage: Storage_name = Barcode name if not given
$this->addMethod('assignBarcode', function($entitytype_id, $prefix, $is_storage, $length = '5')
    use (&$barcode_field, $name_field, $entitytype_id_field)
{
    if ($this->data[$entitytype_id_field] == $entitytype_id) {
        $entity_id = $this->data['id'];
        $this->data[$barcode_field] = sprintf($prefix . '%0' . $length . 'd', $entity_id);
        if ($is_storage && !isset($this->data[$name_field])) {
            $this->data[$name_field] = $this->data[$barcode_field];
        }
    }
});

if ($this->getCurrentMode() == 'create' && !isset($this->data[$barcode_field])) {
    $this->assignBarcode($sample_entitytype, 'S', FALSE);
    // STORAGES
    $this->assignBarcode($freezer_entitytype, 'F', TRUE, '4');
    $this->assignBarcode($shelf_entitytype, 'SH', TRUE);
    $this->assignBarcode($rack_entitytype, 'R', TRUE);
    $this->assignBarcode($box_entitytype, 'B', TRUE);
}
```

### Thaw Cycle

Automatically increment the thaw cycle when the entity of type "Sample" is taken out of a Storage/Freezer.

```php
$sample_entityType = 3;
$thawCycle_field = 'Thaw_cycle';
$storage_field = 'STORAGE';

if (
    $this->data['entitytype_id'] == $sample_entityType
    && isset($this->getOldData()[$storage_field])
    && array_key_exists($storage_field, $this->data)
    && is_null($this->data[$storage_field])
    && !isset($this->data[$thawCycle_field])
) {
    $thaw_cycle = $this->getOldData()[$thawCycle_field] ?? 0;
    $this->data[$thawCycle_field] = $thaw_cycle + 1;
}
```

### Automatically Create Parent on Excel Import

**Conditions:** Sample entitytype, Create mode, Patient is empty, `ID_Study` + `Study` are not null.

**Logic:**
1. Search if Patient exists using `ID_Study` (patient id) + `Study`.
2. If found → Link existing patient to the `Patient` field.
3. If not found → Create new Patient with `ID_Study` and `Study` values, then link it to the Sample as Subject + Parent.

```php
$id_study_field = 'ID_Study';
$patient_field = 'smpl_subject_fk';
$Study_field = 'smpl_study_fk';
$subject_id_field = 'smpl_subject_id';
$subject_id_shwn_field = 'smpl_id';
$parent_field = 'PARENT';
$sample_entitytype_id = 14;
$patient_entitytype_id = 11;

if (
    $this->data['entitytype_id'] == $sample_entitytype_id
    && $this->getCurrentMode() == 'create'
    && !isset($this->data[$patient_field])
) {
    if (isset($this->data[$id_study_field]) && isset($this->data[$Study_field])) {
        $patients = eqb()
            ->where('entitytype_id', '=', $patient_entitytype_id)
            ->where($Study_field, '=', $this->data[$Study_field])
            ->where($subject_id_field, '=', $this->data[$id_study_field])
            ->get();

        if (count($patients) > 0) {
            $this->data[$patient_field] = $patients[0]['id'];
        } else {
            $patient['entitytype_id'] = $patient_entitytype_id;
            $patient[$Study_field] = $this->data[$Study_field];
            $patient[$subject_id_field] = $this->data[$id_study_field];
            $patient[$subject_id_shwn_field] = $this->data[$id_study_field];
            $createdPatient = dac()->create('entity', $patient);
            $this->data[$patient_field] = $createdPatient['id'];
            $this->data[$parent_field] = $createdPatient['id'];
        }
    }
}
```

### Inherit Parent Values

```php
$parent_field = "PARENT";
$inheritValues_field = 'Inherit_parent_values';
$fields_ignore = [
    'id', "PARENT", "BARCODE", "STORAGE", "POSITION_ROW",
    "POSITION_COLUMN", 'Provided_Document', $inheritValues_field
];

if (
    $this->getCurrentMode() == 'create'
    && isset($this->data[$parent_field])
    && isset($this->data[$inheritValues_field])
    && $this->data[$inheritValues_field] == true
) {
    $parent_values = eqb()->find($this->data[$parent_field]);
    foreach ($fields_ignore as $field_ignore) {
        unset($parent_values[$field_ignore]);
    }
    $new_sample = array_merge($parent_values, $this->data);
    $this->data = $new_sample;
}
```

### Rule - Storage Full

```php
$this->error = false;

if (isset($this->newData['entity_fk_to_storage'])) {
    $storage = eqb()::find($this->newData['entity_fk_to_storage']);
    if (isset($storage['storage_2D_NB_ROWS']) && isset($storage['storage_2D_NB_COLUMNS'])) {
        $stored_count = eqb()->where('entity_fk_to_storage', '=', $storage['id'])->count();
        $storage_capacity = $storage['storage_2D_NB_ROWS'] * $storage['storage_2D_NB_COLUMNS'];
        if ($stored_count > $storage_capacity) {
            $this->error = true;
            $this->message = 'Storage full; current capacity: ' . $stored_count;
        }
    }
}
```

### Expiry Stock / Maintenance - Notification

**Calculation:**

```php
\Log::info("Event run");

$expiredFieldName = 'Prochaine_maintenance';
$entities = dac()->getQueryFromCurrentProject('entity')
    ->where($expiredFieldName, '<', today())
    ->get();

$entities_string = json_encode($entities, JSON_PRETTY_PRINT);
$this->addEventValue('expired', $entities_string);
$this->isChecked = true;
```

**Notification template:**

```
Liste des instruments prévue pour la maintenance:
{{!!$eventData['expired']!!}}
```

### Shipment - Change Project

```php
if (
    $this->getCurrentMode() == 'update'
    && isset($this->data['Request_status'])
    && $this->data['Request_status'] == 'Shipped'
) {
    if ($this->getCurrentMode() == 'create') {
        $all_values = $this->data;
    } else {
        $old_values = eqb()::find($this->data['id']);
        $entity = array_merge($old_values, $this->data);
    }

    $requesting_project = intval($entity['Requesting_project']);
    $provider_project = intval($entity['Provider_project']);

    switch ($requesting_project) {
        case 691: $requesting_project = 3; break;
        case 690: $requesting_project = 2; break;
        case 692: $requesting_project = 4; break;
    }

    switch ($provider_project) {
        case 691: $provider_project = 3; break;
        case 690: $provider_project = 2; break;
        case 692: $provider_project = 4; break;
    }

    $project_ids = [$requesting_project, $provider_project];
    \Log::info("Assigning to projects " . json_encode($project_ids));

    consumeService(
        new \App\Services\AccessRights\Projects\SyncResourceToProjectsService(
            dac()->DBRepo('entity'),
            $entity['id'],
            $project_ids
        )
    );
}
```

### Aliquot - Inherit Values

```php
if (
    $this->getCurrentMode() == 'create'
    && isset($this->data['Status'])
    && $this->data['Status'] == 'Aliquoted'
) {
    $parent_values = eqb()::find($this->data['linked_entities']);
    $data_with_inheritance = array_merge($parent_values, $this->data);
    unset($data_with_inheritance['id']);
    unset($data_with_inheritance['Is_Aliquot']);
    $this->data = $data_with_inheritance;
    $this->data['Is_Aliquot'] = true;
}
```

### Age

```php
if (isset($this->data['Date_of_birth'])) {
    $age = date_diff(
        date_create($this->data['Date_of_birth']),
        date_create(date("Y-m-d"))
    )->format('%y');
    $this->data['Age'] = $age;
}
```

### Date of Birth

```php
$dob = 'Date_of_birth';
$age_full = 'Age_full';

if (isset($this->data[$dob])) {
    $age = date_diff(
        date_create($this->data['Date_of_birth']),
        date_create(date("Y-m-d"))
    );

    $years  = $age->format('%y') < 1 ? '' : $age->format('%y') . ($age->format('%y') == 1 ? ' year '  : ' years ');
    $months = $age->format('%m') < 1 ? '' : $age->format('%m') . ($age->format('%m') == 1 ? ' month ' : ' months ');
    $days   = $age->format('%d') < 1 ? '' : $age->format('%d') . ($age->format('%d') == 1 ? ' day '   : ' days ');

    if ($age->format('%R') == '-') {
        $this->data[$age_full] = 'NOT BORN YET';
    } elseif ($age->format('%a') == 0) {
        $this->data[$age_full] = 'Today';
    } else {
        $this->data[$age_full] = $years . $months . $days;
    }
}
```

### Collection Tubes

Creates child tube entities when a sample's status is set to "Collected BL". Works only if using Workflow so Status and collection tubes are assigned at the same time.

```php
Log::info('Collection tubes ' . json_encode($this->data));
$updated_data = $this->data;

if (
    $this->data['entitytype_id'] == 1
    && isset($this->data['Status'])
    && $this->data['Status'] == 'Collected BL'
) {
    if ($this->getCurrentMode() == 'create') {
        $all_values = $this->data;
    } else {
        $old_values = $this->getOldData();
        $entity = array_merge($old_values, $this->data);
    }

    if (isset($updated_data['EDTA_2_6_ml_red']) && $updated_data['EDTA_2_6_ml_red'] == true) {
        dac()->create('entity', [
            'entitytype_id' => 4, 'Volume_ml' => 2,
            'Container' => 'EDTA 2.6 ml', 'Status' => 'Collected BL',
            'parent_entity' => $entity['id'], 'Collection_time' => $entity['Collection_time']
        ]);
        \Log::info('Created EDTA - RED');
    }

    if (isset($updated_data['EDTA_7_5_ml_red']) && $updated_data['EDTA_7_5_ml_red'] == true) {
        dac()->create('entity', [
            'entitytype_id' => 4, 'Volume_ml' => 7,
            'Container' => 'EDTA 7.5 ml', 'Status' => 'Collected BL',
            'parent_entity' => $entity['id'], 'Collection_time' => $entity['Collection_time']
        ]);
        \Log::info('Created EDTA 7.5 - RED');
    }

    if (isset($updated_data['Lithium_Heparin_6_ml']) && $updated_data['Lithium_Heparin_6_ml'] == true) {
        dac()->create('entity', [
            'entitytype_id' => 4, 'Volume_ml' => 6,
            'Container' => 'Lith-Hep 6 ml', 'Status' => 'Collected BL',
            'parent_entity' => $entity['id'], 'Collection_time' => $entity['Collection_time']
        ]);
        \Log::info('Created Lithium Heparin 6 - Purple');
    }

    if (isset($updated_data['Trace_element_6_ml_blue']) && $updated_data['Trace_element_6_ml_blue'] == true) {
        dac()->create('entity', [
            'entitytype_id' => 4, 'Volume_ml' => 5,
            'Container' => 'Vacutainer trace element', 'Status' => 'Collected BL',
            'parent_entity' => $entity['id'], 'Collection_time' => $entity['Collection_time']
        ]);
        \Log::info('Created Trace 6 ml - Blue');
    }

    if (isset($updated_data['Urine_8_ml']) && $updated_data['Urine_8_ml'] == true) {
        dac()->create('entity', [
            'entitytype_id' => 12, 'Volume_ml' => 8,
            'Container' => 'Monovette Urine', 'Status' => 'Collected UR',
            'parent_entity' => $entity['id'], 'Collection_time' => $entity['Collection_time']
        ]);
        \Log::info('Created Urine 8 - Yellow');
    }

    if (isset($updated_data['Saliva_2_ml']) && $updated_data['Saliva_2_ml'] == true) {
        dac()->create('entity', [
            'entitytype_id' => 18, 'Volume_ml' => 2,
            'Container' => 'Monovette Saliva', 'Status' => 'Collected SAL',
            'parent_entity' => $entity['id'], 'Collection_time' => $entity['Collection_time']
        ]);
        \Log::info('Created Saliva 2 - White');
    }

    if (isset($updated_data['Lith-Heparine_7_5_ml'])) {
        dac()->create('entity', [
            'entitytype_id' => 4, 'Volume_ml' => 7,
            'entity_barcode' => $entity['Lith-Heparine_7_5_ml'],
            'Container' => 'Lith-Hep Trace 7.5 ml', 'Status' => 'Collected BL',
            'parent_entity' => $entity['id'], 'Collection_time' => $entity['Collection_time']
        ]);
        \Log::info('Created Lith 7.5 - I');
    }

    $this->data = $updated_data;
    $this->data['Status'] = 'Completed-participants';
    \Log::info('Collection tubes successfully created');
}
```

### Storage Position to Row-Column

Translates the value in the position field to `POSITION_ROW` & `POSITION_COLUMN`.

```php
$position_fieldName = 'POS_NUMBER';
$storage_field = 'STORAGE';
$position_row_field = 'POSITION_ROW';
$position_column_field = 'POSITION_COLUMN';
$number_rows_field = 'NUMBER_ROWS';
$number_column_field = 'NUMBER_COLUMNS';
$nb_columns = 10; // default
$nb_rows = 10;    // default

$data = $this->getCurrentMode() == 'update'
    ? array_merge($this->getOldData(), $this->data)
    : $this->data;

if (isset($this->data[$position_fieldName]) && $this->data[$position_fieldName] > 0) {
    $pos = $this->data[$position_fieldName];

    if (isset($data[$storage_field])) {
        $storage = eqb()
            ->select([$number_rows_field, $number_column_field])
            ->where('id', '=', $data[$storage_field])
            ->first();
    }

    if (isset($storage[$number_rows_field]))    $nb_rows = $storage[$number_rows_field];
    if (isset($storage[$number_column_field]))   $nb_columns = $storage[$number_column_field];

    if ($pos % $nb_columns == 0) {
        $this->data[$position_row_field] = (int) ($pos / $nb_columns);
        $this->data[$position_column_field] = $nb_columns;
    } else {
        $this->data[$position_row_field] = (int) ($pos / $nb_columns) + 1;
        $this->data[$position_column_field] = $pos % $nb_columns;
    }
}
```

### Storage Row-Column from Alphabet to Numeric

```php
$position_row_field = 'POSITION_ROW';
$position_column_field = 'POSITION_COLUMN';
$row_field = 'ROW';
$column_field = 'COLUMN';

$this->addMethod('alphabet_to_num', function($value) {
    if (ctype_alpha($value)) {
        return ord(strtoupper($this->data[$value])) - ord('A') + 1;
    } else {
        return $value;
    }
});

if (isset($this->data[$row_field])) {
    $this->data[$position_row_field] = $this->alphabet_to_num($this->data[$row_field]);
}
if (isset($this->data[$column_field])) {
    $this->data[$position_column_field] = $this->alphabet_to_num($this->data[$column_field]);
}
```

### Get Logged In User Info

```php
$user = getCurrentUser(); // built-in method to get the current logged-in user

// Method to retrieve data from user entitytype based on the username
$this->addMethod("getUserDataByField", function($user_name, $field_name) use ($user_entitytype) {
    $user_entity = eqb()
        ->where("entitytype_id", '=', $user_entitytype)
        ->where('$_USERNAME', '=', $user_name)
        ->first();
    return $user_entity[$field_name];
});
```

### Date Formatting for Printing (MM-DD-YY)

```php
<?php
use Carbon\Carbon;

\Log::info("Evaluating the print using Tfr_Date ---------");

if (!empty($Tfr_Date)) {
    try {
        $date = Carbon::createFromFormat('m-d-Y H:i:s', $Tfr_Date);
        $formattedDate = $date->format('d-m-y');
        \Log::info('Tfr_Date is properly formatted for print', ['formattedDate' => $formattedDate]);
    } catch (\Exception $e) {
        $formattedDate = 'Invalid';
        \Log::error('Tfr_Date could not be parsed for print', ['error' => $e->getMessage()]);
    }
} else {
    $formattedDate = 'Missing';
    \Log::error('Tfr_Date is missing for print');
}

\Log::info('=== FINAL VALUE TO BE PRINTED ===', ['printed_value' => $formattedDate]);
?>
{{ $formattedDate ?? 'Date not available' }}
```

### Store Sample Based on Type + Study

Auto-assigns Storage to DNA sample types on create mode when no storage is set and a parent exists (controls are excluded). Finds the latest stored box matching the same Study, Sample type, and optionally Container volume of the parent. "Latest" means last created with available positions.

```php
\Log::debug("Data on DNA Storage: " . json_encode($this->data));
use App\Common;

$sample_entitytype_id = 14;
$box_entitytype_id = 7;
$dna_type_id = 733;
$study_fk_field = 'smpl_study_fk';
$sample_type_field = 'smpl_sample_type_fk';
$container_volume_field = 'smpl_container_volume';
$storage_field = 'STORAGE';
$number_rows_field = Common::STORAGE_2D_NB_ROWS_FIELD_NAME;
$number_columns_field = Common::STORAGE_2D_NB_COLUMNS_FIELD_NAME;
$created_at_field = 'created_at';
$parent_field = 'PARENT';
$label_field = 'smpl_label';
$sample_type_label_field = 'smpl_sample_type_label';

// Finds the box to fill in the sample
$this->addMethod('findBox', function($study_Id, $sample_type, $container_volume)
    use (
        $box_entitytype_id, $study_fk_field, $sample_type_field, $storage_field,
        $number_rows_field, $number_columns_field, $container_volume_field,
        $created_at_field, $label_field, $sample_type_label_field
    )
{
    $boxQuery = eqb()
        ->where('entitytype_id', '=', $box_entitytype_id)
        ->where($study_fk_field, '=', $study_Id)
        ->where($sample_type_field, '=', $sample_type);

    \Log::debug("3 DNA Storage");

    if ($container_volume) {
        $boxQuery->where($container_volume_field, '=', $container_volume);
    }

    $found_box = $boxQuery->orderBy($created_at_field, 'desc')->first();

    if (!$found_box) {
        $study_name = eqb()->find($study_Id)[$label_field];
        $sample_type = eqb()->find($sample_type)[$sample_type_label_field];
        $error_message = "No Box found with current Study: '" . $study_name
            . "' and Sample type: '" . $sample_type . "'. Please create a box first.";
        \Log::error($error_message . " Full data: " . json_encode($this->data));
        throwValidationException([$error_message]);
    }

    \Log::debug("4 DNA Storage");

    $occupation_count = eqb()->where($storage_field, '=', $found_box['id'])->count();

    if ($occupation_count >= $found_box[$number_rows_field] * $found_box[$number_columns_field]) {
        $study_name = eqb()->find($study_Id)[$label_field];
        $sample_type = eqb()->find($sample_type)[$sample_type_label_field];
        $error_message = 'Box Full. Please create new box first with Study: \''
            . $study_name . '\' and same Sample type: \'' . $sample_type
            . '\'. Last Box ID: ' . $found_box['id'];
        \Log::error($error_message . " Full data: " . json_encode($this->data));
        throwValidationException([$error_message]);
    }

    return $found_box;
});

$this->addMethod('assignStorage', function()
    use ($study_fk_field, $sample_type_field, $container_volume_field, $storage_field, $parent_field)
{
    $sample_study = $this->data[$study_fk_field] ?? null;
    \Log::debug("1 DNA Storage");

    if (!$sample_study) {
        \Log::error("❌ Study missing in current sample. Data: " . json_encode($this->data));
        throwValidationException(["❌ Study missing in current sample."]);
    }

    $parent = eqb()->find($this->data[$parent_field]);
    $sample_type = $parent[$sample_type_field] ?? null;

    if (!$sample_type) {
        \Log::error("❌ Sample does not have a type. Parent data: " . json_encode($parent));
        throwValidationException(["❌ Sample does not have a type."]);
    }

    $container_volume = $this->data[$container_volume_field] ?? null;
    \Log::debug("2 DNA Storage");

    $box = $this->findBox($sample_study, $sample_type, $container_volume);
    $this->data[$storage_field] = $box['id'];
});

if (
    $this->getCurrentMode() == 'create'
    && $this->data['entitytype_id'] == $sample_entitytype_id
    && isset($this->data[$parent_field])
    && !isset($this->data[$storage_field])
    && isset($this->data[$sample_type_field])
    && $this->data[$sample_type_field] == $dna_type_id
) {
    $this->assignStorage();
    \Log::debug("DNA Stored " . json_encode($this->data['STORAGE']));
}

\Log::debug("DNA Storage finished. DATA: " . json_encode($this->data));
```

### Get Entity Files

```php
use Didata\Entities\Services\EntityFiles\GetFilesByEntityService;

$this->addMethod("getAllOrderFiles", function($order) use ($entity_files_fields) {
    $attached_files = [];
    $files = [];
    $entity = Didata\Entities\Repositories\Models\Entity::find($order['id']);
    $attached_files = consumeService(new GetFilesByEntityService($entity));

    foreach ($entity_files_fields as $field) {
        if (isset($order[$field])) {
            $files[] = \App\Models\File::find($order[$field]);
        }
    }

    return array_merge($files, $attached_files->toArray());
});
```