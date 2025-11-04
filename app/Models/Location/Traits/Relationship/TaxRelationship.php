<?

namespace App\Models\Location\Traits\Relationship;

use App\Models\Location\Country;

trait TaxRelationship
{
    public function country()
    {
        return $this->belongs(Country::class);
    }
}
