<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\Product;
use App\Models\Category;
use App\Models\Purchase;
use App\Models\Inventory;
use App\Models\Supplier;
use Illuminate\Http\Request;
use App\Models\PurchaseDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Haruncpi\LaravelIdGenerator\IdGenerator;

class PurchaseController extends Controller
{
    /**
     * Display an all purchases.
     */
    public function allPurchases()
    {
        $row = (int) request('row', 10);

        if ($row < 1 || $row > 100) {
            abort(400, 'The per-page parameter must be an integer between 1 and 100.');
        }

        $purchases = Purchase::with(['supplier'])
            ->sortable()
            ->paginate($row)
            ->appends(request()->query());

        // Memanipulasi hasil paginasi pembelian
        $purchases->setCollection($purchases->getCollection()->reverse());

        return view('purchases.purchases', [
            'purchases' => $purchases
        ]);
    }

    public function store(Request $request)
    {
        // Validasi dan simpan data pembelian

        foreach ($request->products as $product) {
            Inventory::create([
                'product_id' => $product['id'],
                'quantity' => $product['quantity'],
                'cost' => $product['cost'],
                'purchased_at' => now(),
            ]);
        }
    }
    /**
     * Display an all approved purchases.
     */
    public function approvedPurchases()
    {
        $row = (int) request('row', 10);

        if ($row < 1 || $row > 100) {
            abort(400, 'The per-page parameter must be an integer between 1 and 100.');
        }

        $purchases = Purchase::with(['product', 'supplier'])
            ->where('purchase_status', 1) // 1 = approved
            ->sortable()
            ->paginate($row)
            ->appends(request()->query());

        // Memanipulasi hasil paginasi pembelian
        $purchases->setCollection($purchases->getCollection()->reverse());

        return view('purchases.approved-purchases', [
            'purchases' => $purchases
        ]);
    }


    /**
     * Display a purchase details.
     */
    public function purchaseDetails(String $purchase_id)
    {
        $purchase = Purchase::with(['supplier', 'user_created', 'user_updated'])
            ->where('id', $purchase_id)
            ->first();

        $purchaseDetails = PurchaseDetails::with('product')
            ->where('purchase_id', $purchase_id)
            ->orderBy('id')
            ->get();

        return view('purchases.details-purchase', [
            'purchase' => $purchase,
            'purchaseDetails' => $purchaseDetails,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function createPurchase()
    {
        return view('purchases.create-purchase', [
            'categories' => Category::all(),
            'suppliers' => Supplier::all(),
        ]);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function storePurchase(Request $request)
    {
        // Validasi input
        $validatedData = $request->validate([
            'supplier_id' => 'required|string',
            'purchase_date' => 'required|string',
            'total_amount' => 'required|numeric',
            'product_id.*' => 'required|string', // Validasi array product_id
            'quantity.*' => 'required|numeric',   // Validasi array quantity
            'unitcost.*' => 'required|numeric',   // Validasi array unitcost
            'total.*' => 'required|numeric',      // Validasi array total
        ]);

        // Generate purchase number
        $purchase_no = IdGenerator::generate([
            'table' => 'purchases',
            'field' => 'purchase_no',
            'length' => 10,
            'prefix' => 'PRS-'
        ]);

        // Set additional data
        $purchaseData = [
            'purchase_status' => 0, // 0 = pending, 1 = approved
            'purchase_no' => $purchase_no,
            'created_by' => auth()->user()->id,
            'created_at' => now(),
        ];

        // Insert purchase data
        $purchase = Purchase::create(array_merge($validatedData, $purchaseData));

        // Create Purchase Details
        $purchaseDetails = [];
        if (is_array($request->product_id)) {
            foreach ($request->product_id as $key => $productId) {
                $purchaseDetails[] = [
                    'purchase_id' => $purchase->id,
                    'product_id' => $productId,
                    'quantity' => $request->quantity[$key],
                    'unitcost' => $request->unitcost[$key],
                    'total' => $request->total[$key],
                    'created_at' => now(),
                ];
            }
        }

        PurchaseDetails::insert($purchaseDetails);

        return Redirect::route('purchases.allPurchases')->with('success', 'Purchase has been created!');
    }

    /**
     * Handle update a status purchase
     */
    public function updatePurchase(Request $request)
    {
        $purchase_id = $request->id;

        // after purchase approved, add stock product
        $products = PurchaseDetails::where('purchase_id', $purchase_id)->get();

        foreach ($products as $product) {
            Product::where('id', $product->product_id)
                ->update(['stock' => DB::raw('stock+' . $product->quantity)]);
        }

        Purchase::findOrFail($purchase_id)
            ->update([
                'purchase_status' => 1,
                'updated_by' => auth()->user()->id
            ]); // 1 = approved, 0 = pending

        return Redirect::route('purchases.allPurchases')->with('success', 'Purchase has been approved!');
    }

    /**
     * Handle delete a purchase
     */
    public function deletePurchase(String $purchase_id)
    {
        Purchase::where([
            'id' => $purchase_id,
            'purchase_status' => '0'
        ])->delete();

        PurchaseDetails::where('purchase_id', $purchase_id)->delete();

        return Redirect::route('purchases.allPurchases')->with('success', 'Purchase has been deleted!');
    }

    /**
     * Display an all purchases.
     */
    public function dailyPurchaseReport()
    {
        $row = (int) request('row', 10);

        if ($row < 1 || $row > 100) {
            abort(400, 'The per-page parameter must be an integer between 1 and 100.');
        }

        $purchases = Purchase::with(['supplier'])
            ->where('purchase_date', Carbon::now()->format('Y-m-d')) // 1 = approved
            ->sortable()
            ->paginate($row)
            ->appends(request()->query());

        return view('purchases.purchases', [
            'purchases' => $purchases
        ]);
    }

    /**
     * Show the form input date for purchase report.
     */
    public function getPurchaseReport()
    {
        return view('purchases.report-purchase');
    }

    /**
     * Handle request to get purchase report
     */
    public function exportPurchaseReport(Request $request)
    {
        $rules = [
            'start_date' => 'required|string|date_format:Y-m-d',
            'end_date' => 'required|string|date_format:Y-m-d',
        ];

        $validatedData = $request->validate($rules);

        $sDate = $validatedData['start_date'];
        $eDate = $validatedData['end_date'];

        // $purchaseDetails = DB::table('purchases')
        //     ->whereBetween('purchases.purchase_date',[$sDate,$eDate])
        //     ->where('purchases.purchase_status','1')
        //     ->join('purchase_details', 'purchases.id', '=', 'purchase_details.purchase_id')
        //     ->get();

        $purchases = DB::table('purchase_details')
            ->join('products', 'purchase_details.product_id', '=', 'products.id')
            ->join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
            ->whereBetween('purchases.purchase_date', [$sDate, $eDate])
            ->where('purchases.purchase_status', '1')
            ->select('purchases.purchase_no', 'purchases.purchase_date', 'purchases.supplier_id', 'products.product_code', 'products.product_name', 'purchase_details.quantity', 'purchase_details.unitcost', 'purchase_details.total')
            ->get();


        $purchase_array[] = array(
            'Date',
            'No Purchase',
            'Supplier',
            'Product Code',
            'Product',
            'Quantity',
            'Unitcost',
            'Total',
        );

        foreach ($purchases as $purchase) {
            $purchase_array[] = array(
                'Date' => $purchase->purchase_date,
                'No Purchase' => $purchase->purchase_no,
                'Supplier' => $purchase->supplier_id,
                'Product Code' => $purchase->product_code,
                'Product' => $purchase->product_name,
                'Quantity' => $purchase->quantity,
                'Unitcost' => $purchase->unitcost,
                'Total' => $purchase->total,
            );
        }

        $this->exportExcel($purchase_array);
    }

    /**
     *This function loads the customer data from the database then converts it
     * into an Array that will be exported to Excel
     */
    public function exportExcel($products)
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '4000M');

        try {
            $spreadSheet = new Spreadsheet();
            $spreadSheet->getActiveSheet()->getDefaultColumnDimension()->setWidth(20);
            $spreadSheet->getActiveSheet()->fromArray($products);
            $Excel_writer = new Xls($spreadSheet);
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment;filename="purchase-report.xls"');
            header('Cache-Control: max-age=0');
            ob_end_clean();
            $Excel_writer->save('php://output');
            exit();
        } catch (Exception $e) {
            return;
        }
    }
}
