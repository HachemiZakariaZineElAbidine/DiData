/*** Powered by DiData
  *
  * ## Triggers when:
  *
  * A Cryovial Sample (entitytype_id = 14) is created by the
  * "Create Children" action on a Master Cell Line (entitytype_id = 13).
  *
  * ## Behavior:
  *
  * Maintains a history of summary lines in the parent's "Notes_" field:
  *   Generated children [count] VIALS ([Parent_Batch_Date])
  *
  * Children sharing the EXACT same Parent_Batch_Date are aggregated on a
  * single line (the count is incremented). A different timestamp -- even one
  * second apart -- starts a new line, so previous batches are preserved as
  * history.
  ***/

use Didata\Entities\Repositories\Models\Entity;

$cryovial_entitytype_id = 14;
$parent_field           = 'PARENT';
$batch_date_field       = 'Parent_Batch_Date';
$notes_field            = 'Notes_';
$line_prefix            = 'Generated children';

if ($this->getCurrentMode() !== 'create') {
    return;
}

if (($this->data['entitytype_id'] ?? null) != $cryovial_entitytype_id) {
    return;
}

if (empty($this->data[$parent_field])) {
    return;
}

$parent_id  = $this->data[$parent_field];
$batch_date = (string) ($this->data[$batch_date_field] ?? '');

\Log::warning('smplCreateChildrenLog: fetching parent ' . $parent_id);

$parent = eqb()
    ->select([$notes_field])
    ->where('id', '=', $parent_id)
    ->first();

if (!$parent) {
    return;
}

$existing_notes = $parent[$notes_field] ?? '';

// Look for an existing line with the EXACT same batch date.
$existing_line_pattern = '/[^\r\n]*' . preg_quote($line_prefix, '/')
    . '\s+(\d+)\s+VIALS\s+\(' . preg_quote($batch_date, '/') . '\)'
    . '[^\r\n]*\r?\n?/';

if (preg_match($existing_line_pattern, $existing_notes, $m)) {
    $run_count = ((int) $m[1]) + 1;
    $cleaned   = preg_replace($existing_line_pattern, '', $existing_notes);
} else {
    $run_count = 1;
    $cleaned   = $existing_notes;
}

$cleaned = trim(preg_replace('/(\r?\n){2,}/', "\n", $cleaned));

$creation_massage = $line_prefix . ' ' . $run_count . ' VIALS (' . $batch_date . ')';
$new_notes = ($cleaned !== '' ? $cleaned . "\n" : '') . $creation_massage;

\Log::warning('smplCreateChildrenLog: updating parent ' . $parent_id
    . ' (count=' . $run_count . ', batch=' . $batch_date . ')');

dac()->update(
    'entity',
    Entity::find($parent_id),
    [$notes_field => $new_notes]
);
