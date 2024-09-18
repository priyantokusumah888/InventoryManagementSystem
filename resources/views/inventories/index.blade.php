@extends('dashboard.body.main')

@section('specificpagescripts')
<script src="{{ asset('assets/js/img-preview.js') }}"></script>
@endsection

@section('content')
<!-- BEGIN: Header -->
<header class="page-header page-header-dark bg-gradient-primary-to-secondary pb-10">
    <div class="container-xl px-4">
        <div class="page-header-content pt-4">
            <div class="row align-items-center justify-content-between">
                <div class="col-auto mt-4">
                    <h1 class="page-header-title">
                        <div class="page-header-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                        Laporan Stock Barang
                    </h1>
                </div>
                <div class="col-auto my-4">
                    <a href="{{ route('inventories.getInventoriesReport') }}" class="btn btn-success add-list my-1"><i class="fa-solid fa-file-export me-3"></i>Export</a>
                </div>
            </div>

            <nav class="mt-4 rounded" aria-label="breadcrumb">
                <ol class="breadcrumb px-3 py-2 rounded mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Laporan Stock</li>
                </ol>
            </nav>
        </div>
    </div>
</header>
<!-- END: Header -->

<!-- BEGIN: Main Page Content -->
<div class="container-xl px-2 mt-n10">
    <div class="card mb-4">
        <div class="card-header">
            Tabel Laporan Stock
            <!-- Filter Dropdown -->
            <form method="GET" action="{{ route('inventories.index') }}" class="mt-3">
                <div class="form-group">
                    <label for="product">Filter Produk:</label>
                    <select id="product" name="product" class="form-control">
                        <option value="">Pilih Produk</option>
                        @foreach($products as $product)
                        <option value="{{ $product->id }}" {{ $selectedProductId == $product->id ? 'selected' : '' }}>
                            {{ $product->product_name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Filter</button>
            </form>
        </div>
        <div class="card-body">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th colspan="3">Penjualan</th>
                        <th colspan="3">Pembelian</th>
                        <th colspan="2">Stok</th>
                        <th>HPP</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td></td>
                        <td>Unit</td>
                        <td>Harga/Unit</td>
                        <td>Total Harga</td>
                        <td>Unit</td>
                        <td>Harga/Unit</td>
                        <td>Total Harga</td>
                        <td>Unit</td>
                        <td></td>
                        <td></td>
                    </tr>

                    @php
                    // Variabel inisialisasi
                    $currentStock = !empty($inventory) ? $inventory[0]['stock_awal'] : 0;
                    $totalHPP = 0;
                    $totalOrdersQuantity = 0;
                    $totalOrdersAmount = 0;
                    $totalPurchasesQuantity = 0;
                    $totalPurchasesAmount = 0;
                    $remainingStockFromPurchases = collect(); // Untuk mencatat stok pembelian yang tersisa dengan koleksi
                    @endphp

                    @if (!empty($inventory))
                    @php
                    $inventory = array_shift($inventory);
                    @endphp
                    <tr>
                        <td></td>
                        <td>-</td>
                        <td>-</td>
                        <td>-</td>
                        <td>-</td>
                        <td>-</td>
                        <td>-</td>
                        <td>{{ $currentStock }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                    @endif

                    <!-- Proses Pembelian -->
                    @foreach ($purchases as $purchase)
                    @php
                    // Tambahkan pembelian ke stok yang tersisa
                    $remainingStockFromPurchases->push([
                    'quantity' => $purchase->quantity,
                    'unitcost' => $purchase->unitcost
                    ]);
                    $currentStock += $purchase->quantity;
                    $totalPurchasesQuantity += $purchase->quantity;
                    $totalPurchasesAmount += $purchase->total;
                    @endphp
                    <tr>
                        <td>{{ $purchase->date }}</td>
                        <td>-</td>
                        <td>-</td>
                        <td>-</td>
                        <td>{{ $purchase->quantity }}</td>
                        <td>{{ is_numeric($purchase->unitcost) ? number_format($purchase->unitcost, 0, ',', '.') : '-' }}</td>
                        <td>{{ is_numeric($purchase->total) ? number_format($purchase->total, 0, ',', '.') : '-' }}</td>
                        <td>{{ $currentStock }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                    @endforeach

                    <!-- Proses Penjualan -->
                    @foreach ($orders as $order)
                    @php
                    $quantitySold = $order->quantity;
                    $hppForOrder = 0;

                    // Implementasi LIFO
                    while ($quantitySold > 0 && $remainingStockFromPurchases->isNotEmpty()) {
                    $purchase = $remainingStockFromPurchases->pop(); // Ambil pembelian terakhir

                    if ($purchase['quantity'] <= $quantitySold) {
                        // Jika pembelian sepenuhnya digunakan
                        $hppForOrder +=$purchase['quantity'] * $purchase['unitcost'];
                        $quantitySold -=$purchase['quantity'];
                        } else {
                        // Jika hanya sebagian dari pembelian digunakan
                        $hppForOrder +=$quantitySold * $purchase['unitcost'];
                        $purchase['quantity'] -=$quantitySold;
                        $quantitySold=0;
                        $remainingStockFromPurchases->push($purchase); // Kembalikan sisa pembelian ke koleksi
                        }
                        }

                        $currentStock -= $order->quantity;
                        $totalHPP += $hppForOrder;
                        $totalOrdersQuantity += $order->quantity;
                        $totalOrdersAmount += $order->total;
                        @endphp
                        <tr>
                            <td>{{ $order->date }}</td>
                            <td>{{ $order->quantity }}</td>
                            <td>{{ is_numeric($order->unitcost) ? number_format($order->unitcost, 0, ',', '.') : '-' }}</td>
                            <td>{{ is_numeric($order->total) ? number_format($order->total, 0, ',', '.') : '-' }}</td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                            <td>{{ $currentStock }}</td>
                            <td></td>
                            <td>{{ number_format($hppForOrder, 0, ',', '.') }}</td>
                        </tr>
                        @endforeach
                </tbody>

                <!-- Optional: Total Row -->
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td>{{ $totalOrdersQuantity }}</td>
                        <td></td>
                        <td>{{ is_numeric($totalOrdersAmount) ? number_format($totalOrdersAmount, 0, ',', '.') : '-' }}</td>
                        <td>{{ $totalPurchasesQuantity }}</td>
                        <td></td>
                        <td>{{ is_numeric($totalPurchasesAmount) ? number_format($totalPurchasesAmount, 0, ',', '.') : '-' }}</td>
                        <td>{{ $currentStock }}</td>
                        <td></td>
                        <td>{{ number_format($totalHPP, 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<!-- END: Main Page Content -->
@endsection