<?php

/**
 * Calculator Controller File Doc Comment
 *
 * PHP Version 8.1.2
 *
 * @category WhiteStores
 * @package  WhiteStores_ShippingService
 * @author   Mike Andrews<michael.andrews@whitestores.co.uk>
 * @license  https://whitestores.co.uk UNLICENSED
 * @link     https://github.com/White-Stores/shipping-service/
 */

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * Calculator Class
 *
 * @category WhiteStores
 * @package  WhiteStores_ShippingService
 * @author   Mike Andrews<michael.andrews@whitestores.co.uk>
 * @license  https://whitestores.co.uk UNLICENSED
 * @link     https://github.com/White-Stores/shipping-service/
 */
class Calculator extends Controller
{
    /**
     * Processing Function for calculating valid shipping options
     *
     * @return \Illuminate\Http\Response
     */
    public function process()
    {
        $json = json_decode(file_get_contents('php://input'));

        function IsPostcode($postcode)
        {
            if (preg_match('/^[a-z]{1,2}\d[a-z\d]?\s*\d[a-z]{2}$/i', $postcode)) {
                return true;
            } else {
                return false;
            }
        }

        if (!IsPostcode($json->collection)) {
            $return = [
                'status' => 500,
                'data' => "Invalid Collection Postcode",
            ];
            return response()->json($return);
        };

        if (!IsPostcode($json->delivery)) {
            $return = [
                'status' => 500,
                'data' => "Invalid Delivery Postcode",
            ];
            return response()->json($return);
        };

        $shipment = $json->packages;
        $weight = 0;
        $packages = 0;
        $validOptions = array();

        $biggestSide = 0;
        $secondSide = 0;

        $items = [];
        foreach ($shipment as $parcel) {
            $number = $parcel->quantity;
            $i = 0;
            while ($number != $i) {
                array_push($items, $parcel);
                $i++;
            }
        }

        foreach ($items as $parcel) {
            $parcel->weight = ceil($parcel->weight);
            if (!is_int($parcel->depth) || !is_int($parcel->length) || !is_int($parcel->width)) {
                $return = [
                    'status' => 500,
                    'data' => "Invalid Parcel Size",
                ];
                return response()->json($return);
            }

            $weight = $weight + $parcel->weight;
            $packages++;
            $sizes = [$parcel->length, $parcel->width, $parcel->depth];
            rsort($sizes);
            if ($biggestSide <= $sizes[0]) {
                $biggestSide = $sizes[0];
            }

            if ($secondSide <= $sizes[1]) {
                $secondSide = $sizes[1];
            }
        }

        $options = DB::table('shipping_options')->get();
        $palletSuggested = false;

        foreach ($options as $shipping) {
            $maxVolumeWeight = 0;
            $totalVolumeWeight = 0;
            $maxDeadWeight = 0;
            foreach ($items as $parcel) {
                $parcel->weight = ceil($parcel->weight);
                $volumeWeight = ($parcel->length * $parcel->width * $parcel->depth) / $shipping->volmultiplier;
                $totalVolumeWeight += $volumeWeight;
                $maxVolumeWeight = $volumeWeight > $maxVolumeWeight ? $volumeWeight : $maxVolumeWeight;
                $maxDeadWeight = $parcel->weight > $maxDeadWeight ? $parcel->weight : $maxDeadWeight;
            }

            // Do we need to suggest a pallet for this consignment
            if ($totalVolumeWeight >= 180) {
                $palletSuggested = true;
                continue;
            }

            // Check volumetric weight does not exceed max
            if ($shipping->maxvol <= $volumeWeight) {
                continue;
            }

            // Check dead weight
            if ($shipping->maxdead <= $maxDeadWeight) {
                continue;
            }

            // Check max length
            if ($shipping->maxlength > 0 && $shipping->maxlength <= $biggestSide) {
                continue;
            }

            // Check second side
            if ($shipping->maxsecond > 0 && $shipping->maxsecond <= $secondSide) {
                continue;
            }

            // Check max parcels is not exceeded
            if ($shipping->maxparcels > 0 && count($items) > $shipping->maxparcels) {
                continue;
            }

            $mainWeight = max($volumeWeight, $weight);

            $price = $shipping->baseprice;
            if ($shipping->afterweight > 0 && $weight > $shipping->breakweight) {
                if ($shipping->carrier == "Tuffnells") {
                    $breaking = $mainWeight - $shipping->breakweight;
                } else {
                    $breaking = $weight - $shipping->breakweight;
                }
                $price = $price + ($breaking * $shipping->afterweight);
            }
            if ($shipping->afterparcel > 0) {
                $price = $price + (($packages - 1) * $shipping->afterparcel);
            }
            $surcharge = $shipping->fuelsurcharge > 0 ? $price / (100 / $shipping->fuelsurcharge) : 0;
            $price = $price + $surcharge;

            $price = number_format($price, 2, '.', '');

            $option = array(
                'price' => floatval($price),
                'option' => $shipping->name,
                'id' => $shipping->internalId
            );
            array_push($validOptions, $option);
        }
        if (empty($validOptions)) {
            $validOptions = "No valid shipping options found";
            if ($palletSuggested) {
                $validOptions = "A pallet is required";
            }
        } else {
            // Sort the shipping options by the cheapest first
            usort($validOptions, function ($a, $b) {
                return $a['price'] < $b['price'] ? -1 : ($a['price'] > $b['price'] ? 1 : 0);
            });
        }

        $return = [
            'status' => 200,
            'data' => $validOptions,
        ];
        return response()->json($return);
    }
}
