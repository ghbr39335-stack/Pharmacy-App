<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use Illuminate\Http\Request;
use App\Models\Notification;

class MedicineController extends Controller
{



public function addMedicine(Request $request)
    {
        $request->validate([
            'pharmacy_id'       => 'required|exists:pharmacies,id',
            'name'              => 'required|string',
            'category_medicine' => 'required|in:Antibiotics,Painkillers,Vitamins,Antidiabetics,Gastrointestinal,Respiratory,Cardiovascular,Dermatology',
            'selling_price'     => 'required|numeric',
            'cost_price'        => 'required|numeric',
            'quantity'          => 'required|integer',
            'expire_date'       => 'required|date',
            'manufacturer'      => 'required|string',
            'reorder_level'     => 'required|integer',
            'qr_code'           => 'required|string',
        ]);

        $medicine = Medicine::create($request->all());

        // تنسيق التاريخ للدواء المضاف حديثاً
        $medicineArray = $medicine->toArray();
        $medicineArray['created_at'] = $medicine->created_at ? $medicine->created_at->format('Y-m-d H:i:s') : null;
        $medicineArray['updated_at'] = $medicine->updated_at ? $medicine->updated_at->format('Y-m-d H:i:s') : null;

        return response()->json([
            'message'  => 'Medicine added Successfully',
            'medicine' => $medicineArray,
        ], 201);
    }

    public function getMedicines(Request $request)
    {
        $request->validate([
            'pharmacy_id' => 'required|exists:pharmacies,id',
        ]);

        $medicines = Medicine::where('pharmacy_id', $request->pharmacy_id)
            ->where('quantity', '>', 0)
            ->get();

        // تنسيق التاريخ لكل الأدوية المجلوبة
        $formattedMedicines = $medicines->map(function ($med) {
            $medArray = $med->toArray();
            $medArray['created_at'] = $med->created_at ? $med->created_at->format('Y-m-d H:i:s') : null;
            $medArray['updated_at'] = $med->updated_at ? $med->updated_at->format('Y-m-d H:i:s') : null;
            return $medArray;
        });

        return response()->json([
            'medicines' => $formattedMedicines,
        ]);
    }

    public function searchMedicine(Request $request)
    {
        $request->validate([
            'pharmacy_id' => 'required|exists:pharmacies,id',
            'name'        => 'required|string',
        ]);

        $medicines = Medicine::where('pharmacy_id', $request->pharmacy_id)
            ->where('name', 'LIKE', '%' . $request->name . '%')
            ->where('quantity', '>', 0)
            ->get();

        // تنسيق التاريخ لنتائج البحث
        $formattedMedicines = $medicines->map(function ($med) {
            $medArray = $med->toArray();
            $medArray['created_at'] = $med->created_at ? $med->created_at->format('Y-m-d H:i:s') : null;
            $medArray['updated_at'] = $med->updated_at ? $med->updated_at->format('Y-m-d H:i:s') : null;
            return $medArray;
        });

        return response()->json([
            'medicines' => $formattedMedicines,
        ]);
    }

    public function editMedicine(Request $request, $id)
    {
        $medicine = Medicine::find($id);

        if (!$medicine) {
            return response()->json([
                'message' => 'هذا الدواء غير موجود في قاعدة البيانات (ID خاطئ)'
            ], 404);
        }

        $request->validate([
            'name'              => 'sometimes|string',
            'category_medicine' => 'sometimes|in:Antibiotics,Painkillers,Vitamins,Antidiabetics,Gastrointestinal,Respiratory,Cardiovascular,Dermatology',
            'selling_price'     => 'sometimes|numeric',
            'cost_price'        => 'sometimes|numeric',
            'quantity'          => 'sometimes|integer',
            'expire_date'       => 'sometimes|date',
            'manufacturer'      => 'sometimes|string',
            'reorder_level'     => 'sometimes|integer',
            'qr_code'           => 'sometimes|string',
        ]);

        $medicine->update($request->all());

        // تنسيق التاريخ للدواء بعد تعديله
        $medicineArray = $medicine->toArray();
        $medicineArray['created_at'] = $medicine->created_at ? $medicine->created_at->format('Y-m-d H:i:s') : null;
        $medicineArray['updated_at'] = $medicine->updated_at ? $medicine->updated_at->format('Y-m-d H:i:s') : null;

        return response()->json([
            'message'  => 'Medicine updated Successfully',
            'medicine' => $medicineArray,
        ], 200);
    }

    public function getExpiringMedicines(Request $request)
    {
        $request->validate([
            'pharmacy_id' => 'required|exists:pharmacies,id',
        ]);

        $threeMonthsLater = now()->addMonths(3);

        $medicines = Medicine::where('pharmacy_id', $request->pharmacy_id)
            ->whereDate('expire_date', '<=', $threeMonthsLater)
            ->whereDate('expire_date', '>=', now())
            ->get();

        foreach ($medicines as $medicine) {
            $alreadyNotified = Notification::where('pharmacy_id', $request->pharmacy_id)
                ->where('type', 'expiry')
                ->where('message', 'LIKE', '%' . $medicine->name . '%')
                ->exists();

            if (!$alreadyNotified) {
                Notification::create([
                    'pharmacy_id' => $request->pharmacy_id,
                    'title'       => 'تنبيه انتهاء صلاحية',
                    'message'     => 'دواء ' . $medicine->name . ' ستنتهي صلاحيته بتاريخ ' . $medicine->expire_date,
                    'type'        => 'expiry',
                    'is_read'     => false,
                    'date'        => now(),
                ]);
            }
        }

        // تنسيق التاريخ لقائمة الأدوية أوشكت على الانتهاء
        $formattedMedicines = $medicines->map(function ($med) {
            $medArray = $med->toArray();
            $medArray['created_at'] = $med->created_at ? $med->created_at->format('Y-m-d H:i:s') : null;
            $medArray['updated_at'] = $med->updated_at ? $med->updated_at->format('Y-m-d H:i:s') : null;
            return $medArray;
        });

        return response()->json([
            'expiring_count'     => $medicines->count(),
            'expiring_medicines' => $formattedMedicines,
        ]);
    }

    public function getLowStockMedicines(Request $request)
    {
        $request->validate([
            'pharmacy_id' => 'required|exists:pharmacies,id',
        ]);

        $medicines = Medicine::where('pharmacy_id', $request->pharmacy_id)
            ->where('quantity', '<=', 30)
            ->get();

        foreach ($medicines as $medicine) {
            $alreadyNotified = Notification::where('pharmacy_id', $request->pharmacy_id)
                ->where('type', 'low_stock')
                ->where('message', 'LIKE', '%' . $medicine->name . '%')
                ->exists();

            if (!$alreadyNotified) {
                Notification::create([
                    'pharmacy_id' => $request->pharmacy_id,
                    'title'       => 'تنبيه نقص مخزون',
                    'message'     => 'دواء ' . $medicine->name . ' كميته أصبحت ' . $medicine->quantity . ' فقط',
                    'type'        => 'low_stock',
                    'is_read'     => false,
                    'date'        => now(),
                ]);
            }
        }

        // تنسيق التاريخ لقائمة الأدوية منخفضة الكمية
        $formattedMedicines = $medicines->map(function ($med) {
            $medArray = $med->toArray();
            $medArray['created_at'] = $med->created_at ? $med->created_at->format('Y-m-d H:i:s') : null;
            $medArray['updated_at'] = $med->updated_at ? $med->updated_at->format('Y-m-d H:i:s') : null;
            return $medArray;
        });

        return response()->json([
            'low_stock_count'     => $medicines->count(),
            'low_stock_medicines' => $formattedMedicines,
        ]);
    }}