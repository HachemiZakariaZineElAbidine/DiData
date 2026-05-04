/*** Powered by DiData
  *
  * ## Triggers when:
  *
  * A SMPL_CREATION entity is created or updated.
  *
  * ## Behavior:
  *
  * Validates that the number of barcodes in smpl_barcodes matches
  * smpl_workflow_line_quantity. smpl_barcodes can be comma-separated
  * or space-separated (e.g. "B1,B2,B3" or "B1 B2 B3").
  *
  * Throws a validation error if the counts differ.
  * Skips the check if smpl_barcodes is null/empty.
  ***/

use Didata\Entities\Repositories\Models\EntityType;

$typeName = EntityType::find($this->data['entitytype_id'])?->name;
if ($typeName !== 'SMPL_CREATION') {
    return;
}

$entity = $this->getCurrentMode() === 'create'
    ? $this->data
    : array_merge($this->getOldData(), $this->data);

$smplBarcodes  = $entity['smpl_barcodes'] ?? null;
$smplQuantity  = $entity['smpl_workflow_line_quantity'] ?? null;

// Skip validation if barcodes field is empty
if (empty($smplBarcodes)) {
    return;
}

// Split on commas or spaces, ignoring empty parts
$barcodeList  = preg_split('/[\s,]+/', trim($smplBarcodes), -1, PREG_SPLIT_NO_EMPTY);
$barcodeCount = count($barcodeList);

if ((int)$smplQuantity !== $barcodeCount) {
    throwValidationException([
        'The number of barcodes (' . $barcodeCount . ') does not match the expected quantity (' . (int)$smplQuantity . ').'
    ]);
}
