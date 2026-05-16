/*** Powered by DiData
  *
  * ## Triggers when:
  *
  * A Cryovial Sample (entitytype_id = 14) is created.
  *
  * ## Behavior:
  *
  * Same aggregation rule as smplCreateChildrenLog.php (per-second bucket on
  * Parent_Batch_Date), but the resulting line is written to the "Notes_" field
  * of EVERY Master Cell Line (entitytype_id = 13), not just the parent.
  *
  * Children sharing the EXACT same Parent_Batch_Date increment the count on
  * each Master Cell Line's existing line; a different timestamp creates a new
  * line on every Master Cell Line so the history is preserved.
  ***/

use Didata\Entities\Repositories\Models\Entity;

$cryovial_entitytype_id    = 14;
$master_cell_entitytype_id = 13;
$batch_date_field          = 'Parent_Batch_Date';
$notes_field               = 'Notes_';
$line_prefix               = 'Generated children';

if ($this->getCurrentMode() !== 'create') {
    return;
}

if (($this->data['entitytype_id'] ?? null) != $cryovial_entitytype_id) {
    return;
}

$batch_date = (string) ($this->data[$batch_date_field] ?? '');
if ($batch_date === '') {
    return;
}

\Log::warning('smplCreateChildrenLogAll: fetching all Master Cell Lines');

$mcls = eqb()
    ->select(['id', $notes_field])
    ->where('entitytype_id', '=', $master_cell_entitytype_id)
    ->get();

if (empty($mcls)) {
    return;
}

$existing_line_pattern = '/[^\r\n]*' . preg_quote($line_prefix, '/')
    . '\s+(\d+)\s+VIALS\s+\(' . preg_quote($batch_date, '/') . '\)'
    . '[^\r\n]*\r?\n?/';

foreach ($mcls as $mcl) {
    $existing_notes = $mcl[$notes_field] ?? '';

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

    \Log::warning('smplCreateChildrenLogAll: updating MCL ' . $mcl['id']
        . ' (count=' . $run_count . ', batch=' . $batch_date . ')');

    dac()->update(
        'entity',
        Entity::find($mcl['id']),
        [$notes_field => $new_notes]
    );
}
