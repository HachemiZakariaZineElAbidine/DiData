/*** Powered by DiData
  *
  * ## Triggers when:
  *
  * A SMPL_SAMPLE entity is created.
  *
  * ## Behavior:
  *
  * Generates a sequential barcode for SMPL_SAMPLE entities
  * in the format b001, b002, b003, ...
  *
  * Skips if barcode is already set.
  ***/

use Didata\Entities\Repositories\Models\EntityType;

// Only run on create
if ($this->getCurrentMode() !== 'create') {
    return;
}

// Skip if barcode already set
if (!empty($this->data['BARCODE'])) {
    return;
}

// Only apply to SMPL_SAMPLE entity type
$typeName = EntityType::find($this->data['entitytype_id'])?->name;
if ($typeName !== 'SMPL_CREATION') {
    return;
}

$sample = $this->getCurrentMode() === 'create'
    ? $this->data
    : array_merge($this->getOldData(), $this->data);

$workflowLineId = $sample['smpl_workflow_line_fk'] ?? null;
$quantity       = $sample['smpl_workflow_line_quantity'] ?? '';

$workflowLineLabel = '';
if ($workflowLineId) {
    $workflowLine = eqb()
        ->entityType('SMPL_WORKFLOW_LINE')
        ->select(['smpl_label'])
        ->where('id', '=', $workflowLineId)
        ->get()[0] ?? null;
    $workflowLineLabel = $workflowLine['smpl_label'] ?? '';
}

$this->data['BARCODE'] = $workflowLineLabel . $quantity;
