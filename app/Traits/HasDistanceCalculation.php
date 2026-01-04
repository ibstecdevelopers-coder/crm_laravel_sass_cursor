<?php

namespace App\Traits;

trait HasDistanceCalculation
{
     /**
     * Filter applicants within radius of a model (sale/property)
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param float $radius (in km)
     * @param string $modelType (e.g., 'sale')
     * @param int $modelId
     * @return \Illuminate\Database\Query\Builder
     */
    public function scopeWithinRadiusOf($query, float $radius, string $modelType, int $modelId)
    {
        return $query->whereRaw("
            ST_Distance_Sphere(
                POINT(applicants.lng, applicants.lat),
                (SELECT POINT(lng, lat) FROM {$modelType}s WHERE id = ?)
            ) <= ?",
            [$modelId, $radius * 1000] // Convert km to meters
        );
    }
}
