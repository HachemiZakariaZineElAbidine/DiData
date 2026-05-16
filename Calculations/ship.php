use App\Common;
use App\DidataPackages\DidataCache;

$country_source_field = 'country_source';
$shipped_by_field = 'shipped_by';
$status_field = 'Shipping_Status';

$sample_entitytype_id = 3; // Entity type for samples
$box_entitytype_id = 7;    // Entity type for boxes

$this->addMethod('propagateShippingValues', function($box_id) use (
  
    $shipped_by_field, 
    $status_field, 
    $sample_entitytype_id
) {
    if (is_null($box_id)) {
        \Log::warning("Box ID is null, propagation aborted.");
        return;
    }

    \Log::info("Starting propagation for box ID: $box_id");

    // Fetch all samples in the specified box
    $samples = eqb()->where('entitytype_id', '=', $sample_entitytype_id)
        ->where('STORAGE', '=', $box_id)
        ->get();

    if (empty($samples)) { // Check for an empty array instead of using isEmpty()
        \Log::info("No samples found for box ID: $box_id");
        return;
    }

    \Log::info("Found " . count($samples) . " samples for box ID: $box_id");

    // Loop through the samples and update their fields
    foreach ($samples as $sample) {
       
        $sample[$shipped_by_field] = $this->data[$shipped_by_field] ?? $sample[$shipped_by_field] ?? null;

        // Ensure the `Shipping_Status` value is propagated as an ID
        if (isset($this->data[$status_field])) {
            $sample[$status_field] = $this->data[$status_field]; // Expecting an ID here
        } else {
            $sample[$status_field] = $sample[$status_field] ?? null;
        }

        \Log::info("Updating sample ID: " . $sample['id']);

        // Save the updated sample
        try {
            $repo = dac()->DBrepo('entity');
            $repo->setOptions([
                'with_calculation' => false,
                'with_validation' => true
            ]);
            $repo->update(Didata\Entities\Repositories\Models\Entity::find($sample['id']), $sample);
            \Log::info("Sample ID: " . $sample['id'] . " updated successfully.");
        } catch (\Exception $e) {
            \Log::error("Failed to update sample ID: " . $sample['id'] . ". Error: " . $e->getMessage());
        }
    }

    \Log::info("Propagation completed for box ID: $box_id");
});

// Check if the operation is an update on a box
if (
    $this->getCurrentMode() == "update" &&
    isset($this->data[$status_field]) && // Ensure the shipping status is being updated
    $this->data['entitytype_id'] == $box_entitytype_id
) {
    $box_id = $this->getOldData()['id'] ?? null;

    \Log::info("Triggered propagateShippingValues for box ID: $box_id");

    if ($box_id) {
        $this->propagateShippingValues($box_id);
    }
}