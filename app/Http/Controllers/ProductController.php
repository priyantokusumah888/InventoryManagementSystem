<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Unit;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Redirect;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Picqer\Barcode\BarcodeGeneratorHTML;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Haruncpi\LaravelIdGenerator\IdGenerator;

class ProductController extends Controller
{
    // Method untuk menampilkan daftar produk
    public function index()
    {
        $row = (int) request('row', 10);

        if ($row < 1 || $row > 100) {
            abort(400, 'The per-page parameter must be an integer between 1 and 100.');
        }

        $products = Product::with(['category', 'unit'])
            ->filter(request(['search']))
            ->sortable()
            ->paginate($row)
            ->appends(request()->query());

        // Implementasi algoritma LIFO
        $products->setCollection($products->getCollection()->reverse()); // Balik urutan produk

        return view('products.index', compact('products'));
    }

    // Method untuk menampilkan form pembuatan produk baru
    public function create()
    {
        $categories = Category::all();
        $units = Unit::all();
        return view('products.create', compact('categories', 'units'));
    }

    // Method untuk menyimpan produk baru
    public function store(Request $request)
    {
        $product_code = IdGenerator::generate([
            'table' => 'products',
            'field' => 'product_code',
            'length' => 4,
            'prefix' => 'PC'
        ]);

        $rules = [
            'product_image' => 'image|file|max:2048',
            'product_name' => 'required|string',
            'category_id' => 'required|integer',
            'unit_id' => 'required|integer',
            'stock' => 'required|integer',
            'stock_awal' => 'required|integer',
            'buying_price' => 'required|integer',
        ];

        $validatedData = $request->validate($rules);

        // Generate product code
        $validatedData['product_code'] = $product_code;

        // Calculate selling price as 50% margin from buying price
        $validatedData['selling_price'] = (int) ($validatedData['buying_price'] * 1.5);

        // Handle upload image
        if ($file = $request->file('product_image')) {
            $fileName = uniqid() . '.' . $file->getClientOriginalExtension();
            $path = 'public/products/';

            // Upload image to Storage
            $file->storeAs($path, $fileName);
            $validatedData['product_image'] = $fileName;
        }

        Product::create($validatedData);

        return Redirect::route('products.index')->with('success', 'Product has been created!');
    }

    // Method untuk menampilkan detail produk
    public function show(Product $product)
    {
        $generator = new BarcodeGeneratorHTML();
        $barcode = $generator->getBarcode($product->product_code, $generator::TYPE_CODE_128);

        return view('products.show', compact('product', 'barcode'));
    }

    // Method untuk menampilkan form edit produk
    public function edit(Product $product)
    {
        $categories = Category::all();
        $units = Unit::all();
        return view('products.edit', compact('categories', 'units', 'product'));
    }

    // Method untuk memperbarui produk
    public function update(Request $request, Product $product)
    {
        $rules = [
            'product_image' => 'image|file|max:2048',
            'product_name' => 'required|string',
            'category_id' => 'required|integer',
            'unit_id' => 'required|integer',
            'stock' => 'required|integer',
            'buying_price' => 'required|integer',
        ];

        $validatedData = $request->validate($rules);

        // Calculate selling price as 10% margin from buying price
        $validatedData['selling_price'] = (int) ($validatedData['buying_price'] * 1.1);

        // Handle upload image
        if ($file = $request->file('product_image')) {
            $fileName = uniqid() . '.' . $file->getClientOriginalExtension();
            $path = 'public/products/';

            // Delete previous image if exists
            if ($product->product_image) {
                Storage::delete($path . $product->product_image);
            }

            // Store new image to Storage
            $file->storeAs($path, $fileName);
            $validatedData['product_image'] = $fileName;
        }

        $product->update($validatedData);

        return Redirect::route('products.index')->with('success', 'Product has been updated!');
    }

    // Method untuk menghapus produk
    public function destroy(Product $product)
    {
        // Delete photo if exists
        if ($product->product_image) {
            Storage::delete('public/products/' . $product->product_image);
        }

        $product->delete();

        return Redirect::route('products.index')->with('success', 'Product has been deleted!');
    }

    // Method untuk menampilkan form impor produk
    public function import()
    {
        return view('products.import');
    }

    // Method untuk menangani impor produk
    public function handleImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xls,xlsx',
        ]);

        $the_file = $request->file('file');

        try {
            $spreadsheet = IOFactory::load($the_file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $row_limit = $sheet->getHighestDataRow();
            $row_range = range(2, $row_limit);
            $data = [];

            foreach ($row_range as $row) {
                $buyingPrice = $sheet->getCell('F' . $row)->getValue();
                $data[] = [
                    'product_name' => $sheet->getCell('A' . $row)->getValue(),
                    'category_id' => $sheet->getCell('B' . $row)->getValue(),
                    'unit_id' => $sheet->getCell('C' . $row)->getValue(),
                    'product_code' => $sheet->getCell('D' . $row)->getValue(),
                    'stock' => $sheet->getCell('E' . $row)->getValue(),
                    'buying_price' => $buyingPrice,
                    'selling_price' => (int) ($buyingPrice * 1.1), // Calculate selling price
                    'product_image' => $sheet->getCell('H' . $row)->getValue(),
                ];
            }

            Product::insert($data);
        } catch (Exception $e) {
            return Redirect::route('products.index')->with('error', 'There was a problem uploading the data!');
        }

        return Redirect::route('products.index')->with('success', 'Data product has been imported!');
    }

    // Method untuk mengekspor produk
    public function export()
    {
        $products = Product::all()->sortBy('product_name');

        $product_array[] = [
            'Product Name',
            'Category Id',
            'Unit Id',
            'Product Code',
            'Stock',
            'Buying Price',
            'Selling Price',
            'Product Image',
        ];

        foreach ($products as $product) {
            $product_array[] = [
                $product->product_name,
                $product->category_id,
                $product->unit_id,
                $product->product_code,
                $product->stock,
                $product->buying_price,
                $product->selling_price,
                $product->product_image,
            ];
        }

        $this->exportExcel($product_array);
    }

    // Method untuk mengekspor data ke Excel
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
            header('Content-Disposition: attachment;filename="products.xls"');
            header('Cache-Control: max-age=0');
            ob_end_clean();
            $Excel_writer->save('php://output');
            exit();
        } catch (Exception $e) {
            return Redirect::route('products.index')->with('error', 'Failed to export data!');
        }
    }
}
