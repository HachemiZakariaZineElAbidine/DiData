/*** Powered by DiData
  *
  * ## Triggers when:
  *
  * A Cryovial Sample (entitytype_id = 14) is updated and BARCODE goes from
  * empty to a non-empty value.
  *
  * ## Behavior:
  *
  * Finds the matching "Generated children N VIALS (Parent_Batch_Date)" line in
  * every Master Cell Line (entitytype_id = 13) Notes_ field and appends the new
  * BARCODE to it. The barcode list is comma-separated and de-duplicated:
  *
  *   Generated children 2 VIALS (08-05-2026 13:08:08) K017907962
  *   Generated children 2 VIALS (08-05-2026 13:08:08) K017907962,K017907958
  *
  * Lines are matched by EXACT Parent_Batch_Date string -- same rule as
  * smplCreateChildrenLog* so the line written at create time is the one updated
  * here.
  ***/

use Didata\Entities\Repositories\Models\Entity;

$cryovial_entitytype_id    = 14;
$master_cell_entitytype_id = 13;
$batch_date_field          = 'Parent_Batch_Date';
$barcode_field             = 'BARCODE';
$notes_field               = 'Notes_';
$line_prefix               = 'Generated children';

if ($this->getCurrentMode() !== 'update') {
    return;
}

if (($this->data['entitytype_id'] ?? null) != $cryovial_entitytype_id) {
    return;
}

if (!array_key_exists($barcode_field, $this->data)) {
    return;
}

$new_barcode = (string) ($this->data[$barcode_field] ?? '');
if ($new_barcode === '') {
    return;
}

$old = $this->getOldData();
$old_barcode = (string) ($old[$barcode_field] ?? '');
if ($old_barcode === $new_barcode) {
    return;
}

$batch_date = (string) ($this->data[$batch_date_field] ?? $old[$batch_date_field] ?? '');
if ($batch_date === '') {
    return;
}

\Log::warning('smplAppendBarcodeToLog: fetching all Master Cell Lines for batch '
    . $batch_date . ' barcode ' . $new_barcode);

$mcls = eqb()
    ->select(['id', $notes_field])
    ->where('entitytype_id', '=', $master_cell_entitytype_id)
    ->get();

if (empty($mcls)) {
    return;
}

$line_pattern = '/(' . preg_quote($line_prefix, '/')
    . '\s+\d+\s+VIALS\s+\(' . preg_quote($batch_date, '/') . '\))([^\r\n]*)/';

foreach ($mcls as $mcl) {
    $existing_notes = (string) ($mcl[$notes_field] ?? '');
    if ($existing_notes === '') {
        continue;
    }

    if (!preg_match($line_pattern, $existing_notes, $m)) {
        continue;
    }

    $head = $m[1];
    $tail = trim($m[2]);

    $barcodes = $tail === ''
        ? []
        : preg_split('/[\s,]+/', $tail, -1, PREG_SPLIT_NO_EMPTY);

    if (in_array($new_barcode, $barcodes, true)) {
        continue;
    }

    $barcodes[] = $new_barcode;
    $new_line = $head . ' ' . implode(',', $barcodes);

    $new_notes = preg_replace($line_pattern, $new_line, $existing_notes, 1);

    \Log::warning('smplAppendBarcodeToLog: updating MCL ' . $mcl['id']
        . ' (batch=' . $batch_date . ', barcode=' . $new_barcode . ')');

    dac()->update(
        'entity',
        Entity::find($mcl['id']),
        [$notes_field => $new_notes]
    );
}
