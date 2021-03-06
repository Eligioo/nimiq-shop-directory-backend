<?php

namespace App\Http\Controllers;

use App\Filters\BoundingBoxFilter;
use App\Filters\LocationFilter;
use App\Filters\VoidFilter;
use App\Models\Pickup;
use App\Models\Shipping;
use App\Models\Shop;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ShopController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = auth()->user();
        return view('shops.index', [
            'shops' => ($user->is_admin) ? Shop::all() : Shop::where('user_id', $user->id)->get()
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('shops.edit');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'label' => 'required',
            'email' => 'email:rfc,dns'
        ]);

        $shop = new Shop($request->all());
        $shop->user_id = auth()->user()->id;
        $shop->save();

        return redirect(route('shops.show', $shop->id));
    }

    /**
     * Display the specified resource.
     *
     * @param  Shop  $shop
     * @return \Illuminate\Http\Response
     */
    public function show(Shop $shop)
    {
        $user = auth()->user();
        if (!$user->is_admin && $shop->user_id !== $user->id) {
            return redirect(route('shops.index'));
        }

        return view('shops.edit', ['shop' => $shop]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  Shop  $shop
     * @return \Illuminate\Http\Response
     */
    public function edit(Shop $shop)
    {
        return view('shops.edit', ['shop' => $shop]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $shop = Shop::findOrFail($id);
        $user = auth()->user();
        if (!$user->is_admin && $shop->user_id !== $user->id) {
            return redirect(route('shops.index'));
        }

        $request->validate([
            'label' => 'required',
            'email' => 'email:rfc,dns'
        ]);
        $shop->update($request->all());

        return redirect(route('shops.show', $shop->id));
    }

    public function search(Request $request)
    {
        try {
            $limit = intval($request->query('filter')['limit'] ?? 20);
            if ($limit === 0) {
                throw new \Exception('Unable to parse limit into int.');
            } else if ($limit > 200) {
                $limit = 200;
            }

            $radius = floatval($request->query('filter')['radius'] ?? 50);
            if ($radius === 0.0) {
                throw new \Exception('Unable to parse radius into float.');
            }

            $shops = QueryBuilder::for(Shop::class)
                ->allowedFilters([
                    'city',
                    'country',
                    'description',
                    'email',
                    'label',
                    'address_line_1',
                    'address_line_2',
                    'address_line_3',
                    'website',
                    'zip',
                    AllowedFilter::custom('bounding_box', new BoundingBoxFilter($request->query('filter')['bounding_box'] ?? null)),
                    AllowedFilter::custom('limit', new VoidFilter),
                    AllowedFilter::custom('location', new LocationFilter($radius)),
                    AllowedFilter::custom('radius', new VoidFilter),
                    AllowedFilter::exact('digital_goods')
                ])
                ->paginate($limit)
                ->appends(request()->query());
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 400);
        }

        $pickups = Pickup::all();
        $shippings = Shipping::all();

        $shops->transform(function ($shop) use (&$pickups, &$shippings) {
            $shop->pickups = $pickups->filter(function ($pickup) use (&$shop) {
                $shop->accepts = ["Bitcoin", "Dash", "Litecoin", "Ethereum", "Ripple", "Stellar", "Nimiq"];
                return $shop->id === $pickup->shop_id;
            })->values();

            $shop->shippings = $shippings->filter(function ($shipping) use (&$shop) {
                return $shop->id === $shipping->shop_id;
            })->values();

            return $shop;
        });

        return $shops;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Shop $shop)
    {
        $user = auth()->user();
        if (!$user->is_admin && $shop->user_id !== $user->id) {
            return redirect(route('shops.index'));
        }

        $shop->delete();

        return redirect(route('shops.index'));
    }
}
