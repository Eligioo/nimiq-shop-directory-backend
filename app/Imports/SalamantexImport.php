<?php

namespace App\Imports;

use App\Models\Shop;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Validators\Failure;
use MStaack\LaravelPostgis\Geometries\Point;
use Illuminate\Support\Facades\Log;
use Spatie\Geocoder\Geocoder;

class SalamantexImport implements SkipsOnFailure, ToModel, WithHeadingRow, WithValidation
{
    use SkipsFailures;

    private function addGeocodingPickup($shop, $data)
    {
        $geocoder = new Geocoder(new \GuzzleHttp\Client());
        $geocoder->setApiKey(config('geocoder.key'));

        try {
            $geo = $geocoder->getCoordinatesForAddress($data['label'] . ' ' . $data['city']);
            if ($geo['accuracy'] === 'result_not_found' || $geo['accuracy'] === 'APPROXIMATE') {
                $geo = $geocoder->getCoordinatesForAddress($data['address_line_1'] . ' ' . $data['city']);
            }

            $httpClient = new \GuzzleHttp\Client();
            $request = $httpClient->get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json?key=' . env('GOOGLE_MAPS_GEOCODING_API_KEY') . '&inputtype=textquery&input=' . $data['label'] . ' ' . $data['address_line_1']);
            $body = collect(json_decode($request->getBody()->getContents())->candidates)->first();

            $placeId = $body->place_id ?? null;
            if (is_null($placeId)) {
                return;
            }

            $request = $httpClient->get('https://maps.googleapis.com/maps/api/place/details/json?key=' . env('GOOGLE_MAPS_GEOCODING_API_KEY') . '&place_id=' . $placeId);
            $body = json_decode($request->getBody()->getContents());
            $body->result->reviews = [];
            if (property_exists($body->result, 'photos')) {
                $body->result->photos = (count($body->result->photos) > 0) ? [$body->result->photos[0]] : [];
            }

            $shop->pickups()->create([
                'geo_location' => new Point($geo['lat'], $geo['lng']),
                'place_id' => $placeId,
                'place_information' => json_encode($body->result)
            ]);
        } catch (\Throwable $th) {
            Log::debug($th->getMessage() . $th->getLine() . $th->getFile());
        }
    }

    public function onFailure(Failure ...$failures)
    {
    }

    public function model(array $row)
    {
        if (is_null($row['column1companyname'])) {
            return;
        }

        $shop = Shop::where([
            'source_id' => 'salamantex',
            'object_id' => $row['column1partnernumber']
        ])->first();

        $data = [
            'label' => $row['column1companyname'],
            'email' => $row['column1companymail'],
            'description' => '',
            'address_line_1' => $row['column1addressinfoaddresslines'],
            'zip' => $row['column1addressinfozipcode'],
            'city' => $row['column1addressinfocity'],
            'country' => $row['column1addressinfocountry']
        ];

        if (is_null($shop)) {
            $shop = new Shop($data);
            $shop->user_id = auth()->user()->id;
            $shop->object_id = $row['column1partnernumber'];
            $shop->source_id = 'salamantex';
            $shop->save();
        } else {
            $shop->update($data);
        }

        $shop->pickups()->delete();

        $this->addGeocodingPickup($shop, $data);
    }

    public function rules(): array
    {
        return [
            '1' => \Illuminate\Validation\Rule::unique('column1partnernumber'),
        ];
    }
}
