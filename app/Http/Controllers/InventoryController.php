<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use App\Models\PurchaseDetails;
use App\Models\OrderDetails;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $selectedProductId = $request->input('product');
        $purchases = collect();
        $orders = collect();
        $inventory = [];
        $totalPurchasesQuantity = 0;
        $totalPurchasesAmount = 0;
        $totalOrdersQuantity = 0;
        $totalOrdersAmount = 0;
        $totalInventoryQuantity = 0;
        $totalInventoryAmount = 0;
        $totalHPP = 0; // Inisialisasi total HPP

        if ($selectedProductId) {
            // Fetch purchases
            $purchasesQuery = PurchaseDetails::select(
                'purchases.purchase_date as date',
                'purchase_details.quantity',
                'products.buying_price as unitcost',
                DB::raw('purchase_details.quantity * products.buying_price as total')
            )
                ->join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
                ->join('products', 'purchase_details.product_id', '=', 'products.id')
                ->where('purchases.purchase_status', '1')
                ->where('purchase_details.product_id', $selectedProductId);
            $purchases = $purchasesQuery->orderBy('purchases.purchase_date', 'ASC')->get();

            // Fetch orders
            $ordersQuery = OrderDetails::select(
                'orders.order_date as date',
                'order_details.quantity',
                'order_details.unitcost',
                'order_details.total'
            )
                ->join('orders', 'order_details.order_id', '=', 'orders.id')
                ->where('orders.order_status', 'complete')
                ->where('order_details.product_id', $selectedProductId);
            $orders = $ordersQuery->orderBy('orders.order_date', 'DESC')->get();

            // Calculate HPP using LIFO method
            $totalHPP = $this->calculateHPP($orders, $purchases);

            // Fetch initial stock and product data
            $product = DB::table('products')->select('stock', 'stock_awal', 'buying_price', 'selling_price')->where('id', $selectedProductId)->first();

            if ($product) {
                $initialStock = $product->stock_awal;

                // Calculate selling prices
                $marginPercentage = 20; // Example margin of 20%

                $inventory = [
                    [
                        'stock_awal' => $initialStock,
                        'quantity' => $product->stock,
                        'unitcost' => $product->buying_price,
                        'total' => $product->stock * $product->buying_price,
                    ]
                ];
            }

            // Calculate totals
            $totalPurchasesQuantity = $purchases->sum('quantity');
            $totalPurchasesAmount = $purchases->sum('total');
            $totalOrdersQuantity = $orders->sum('quantity');
            $totalOrdersAmount = $orders->sum('total');
            $totalInventoryQuantity = $totalHPP['remaining_quantity']; // Use remaining quantity from HPP calculation
            $totalInventoryAmount = array_sum(array_column($inventory, 'total'));
        }

        $allProducts = DB::table('products')->select('id', 'stock', 'product_name')->get();

        return view('inventories.index', [
            'purchases' => $purchases,
            'orders' => $orders,
            'inventory' => $inventory,
            'totalPurchasesQuantity' => $totalPurchasesQuantity,
            'totalPurchasesAmount' => $totalPurchasesAmount,
            'totalOrdersQuantity' => $totalOrdersQuantity,
            'totalOrdersAmount' => $totalOrdersAmount,
            'totalInventoryQuantity' => $totalInventoryQuantity,
            'totalInventoryAmount' => $totalInventoryAmount,
            'totalHPP' => $totalHPP,
            ['total_hpp'], // Use calculated HPP
            'products' => $allProducts,
            'selectedProductId' => $selectedProductId
        ]);
    }

    private function calculateHPP($orders, $purchases)
    {
        $hpp = 0; // Inisialisasi total HPP
        $remainingStock = []; // Inisialisasi array stok yang tersisa

        // Inisialisasi stok yang tersisa dengan data pembelian dalam urutan terbaru (LIFO)
        foreach ($purchases as $purchase) {
            $remainingStock[] = [
                'date' => $purchase->date,
                'quantity' => $purchase->quantity,
                'unitcost' => $purchase->unitcost
            ];
        }

        // Urutkan stok yang tersisa berdasarkan tanggal, descending (yang terbaru dulu)
        usort($remainingStock, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        foreach ($orders as $order) {
            $totalQuantity = $order->quantity; // Jumlah yang perlu diambil dari stok
            $orderHPP = 0; // HPP untuk pesanan ini

            // Ambil stok dari yang paling baru (purchase dengan tanggal terbaru)
            foreach ($remainingStock as $key => &$stock) {
                if ($totalQuantity <= 0) break; // Jika kuantitas yang diperlukan sudah terpenuhi

                // Ambil jumlah yang dibutuhkan dari stok yang tersedia
                $quantityUsed = min($totalQuantity, $stock['quantity']);
                $orderHPP += $quantityUsed * $stock['unitcost']; // HPP dihitung berdasarkan harga unit pembelian
                $totalQuantity -= $quantityUsed; // Kurangi kuantitas yang dibutuhkan
                $stock['quantity'] -= $quantityUsed; // Kurangi stok

                // Jika stok dari purchase sudah habis, hapus dari array
                if ($stock['quantity'] == 0) {
                    unset($remainingStock[$key]);
                }
            }

            $hpp += $orderHPP; // Akumulasikan total HPP
            $order->hpp = $orderHPP; // Lampirkan HPP ke setiap pesanan
        }


        // Hitung sisa stok untuk mencerminkan stok setelah penjualan
        $remainingQuantity = array_sum(array_column($remainingStock, 'quantity'));

        return [
            'total_hpp' => $hpp, // Kembalikan total HPP
            'remaining_stock' => $remainingStock, // Kembalikan array stok yang tersisa
            'remaining_quantity' => $remainingQuantity // Kembalikan total kuantitas yang tersisa
        ];
    }

    public function getInventoriesReport()
    {
        // Ambil data produk dari model
        $products = DB::table('products')->get();

        // Kirim data produk ke view
        return view('inventories.report-inventories', ['products' => $products]);
    }

    /**
     * Handle request to get inventory report
     */
    public function exportInventoriesReport(Request $request)
    {
        $productId = $request->input('product_id');

        if (!$productId) {
            return redirect()->back()->with('error', 'Please select a product.');
        }

        // Fetch product details
        $product = DB::table('products')
            ->where('id', $productId)
            ->select('stock_awal', 'stock', 'buying_price', 'selling_price')
            ->first();

        if (!$product) {
            return redirect()->back()->with('error', 'Product not found.');
        }

        $orders = DB::table('order_details')
            ->join('orders', 'order_details.order_id', '=', 'orders.id')
            ->where('order_details.product_id', $productId)
            ->where('orders.order_status', 'complete')
            ->select('orders.order_date as date', 'order_details.quantity', 'order_details.unitcost', 'order_details.total')
            ->orderBy('orders.order_date', 'DESC')
            ->get();

        $purchases = DB::table('purchase_details')
            ->join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
            ->where('purchase_details.product_id', $productId)
            ->where('purchases.purchase_status', '1')
            ->select('purchases.purchase_date as date', 'purchase_details.quantity', 'purchase_details.unitcost', 'purchase_details.total')
            ->orderBy('purchases.purchase_date', 'ASC')
            ->get();

        // Calculate HPP using LIFO method
        $totalHPP = $this->calculateHPP($orders, $purchases);
        $sellingPriceFromMargin = $this->calculateSellingPriceFromMargin($product->buying_price, 20); // Example margin of 20%

        $inventory = [
            ['Tanggal', 'Pembelian (Unit)', 'Pembelian (Harga/Unit)', 'Pembelian (Total Harga)', 'Penjualan (Unit)', 'Penjualan (Harga/Unit)', 'Penjualan (Total Harga)', 'Stok Tersedia', 'Harga Pokok Penjualan (HPP)', 'Harga Jual', 'Keuntungan (Harga Jual - HPP)', 'Keuntungan (%)']
        ];

        foreach ($orders as $order) {
            $inventory[] = [
                $order->date,
                '',
                '',
                '', // No purchases for order records
                $order->quantity,
                $order->unitcost,
                $order->total,
                $totalHPP['remaining_quantity'], // Remaining stock after the order
                $order->hpp, // HPP for this order
                $sellingPriceFromMargin,
                $sellingPriceFromMargin - $order->hpp, // Profit
                20 // Margin percentage
            ];
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($inventory, null, 'A1', true);

        $writer = new Xls($spreadsheet);
        $filename = 'inventory-report-' . date('Y-m-d') . '.xls';

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
    }

    private function calculateSellingPriceFromMargin($buyingPrice, $marginPercentage)
    {
        $profit = $buyingPrice * ($marginPercentage / 100);
        return $buyingPrice + $profit;
    }
}
