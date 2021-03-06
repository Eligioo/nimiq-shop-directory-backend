<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MStaack\LaravelPostgis\Eloquent\PostgisTrait;

class Shipping extends Model
{
    use HasFactory, PostGisTrait;

    protected $hidden = ['pivot'];

    protected $fillable = [
        'geo_location',
        'radius',
        'countries'
    ];

    protected $postgisFields = [
        'geo_location',
    ];

    protected $postgisTypes = [
        'geo_location' => [
            'geomtype' => 'geography',
            'srid' => 4326
        ]
    ];
}
