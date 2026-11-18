<?php

namespace App\Services\Erp;

use App\Models\CategoryDetail;
use App\Models\CompanySetting;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TransactionDetail;
use App\Models\Variant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Katalog toko dari ERP DK (ERP = sumber data): kategori (jalur "Induk > Anak" → kategori utama › detail), barang,
 * varian (SKU, harga dasar atau level harga pilihan, berat), foto, dan stok tersedia (dijumlah dari gudang sumber).
 * Sinkron bertahap memakai `updated_since`; sinkron penuh juga menonaktifkan barang yang sudah tidak ada di ERP.
 */
final class ErpCatalogSync
{
    /** @return array{products: int, variants: int, stock: int, inactive: int} */
    public function run(ErpConnection $erp, bool $full = false): array
    {
        $started = now();
        $since = $full ? null : (CompanySetting::values($erp->companyId)['erp_catalog_synced_at'] ?? null);
        $stats = ['products' => 0, 'variants' => 0, 'stock' => 0, 'inactive' => 0];
        $seen = [];

        foreach ($erp->pages('/products', array_filter(['updated_since' => $since])) as $row) {
            $stats['variants'] += $this->product($erp, $row);
            $stats['products']++;
            $seen[] = (int) $row['id'];
        }
        if ($full) {
            // Barang yang sudah tidak ada di ERP (terlewat webhook product.deleted).
            $stats['inactive'] = $this->remove($erp, Product::query()->where('company_id', $erp->companyId)->whereNotNull('erp_product_id')
                ->whereNotIn('erp_product_id', $seen ?: [0])->pluck('erp_product_id')->all());
        }
        $stats['stock'] = $this->stock($erp);

        CompanySetting::setMany($erp->companyId, [
            'erp_catalog_synced_at' => $started->toIso8601String(), 'erp_last_error' => null,
            'erp_last_result' => $stats + ['at' => now()->toIso8601String()],
        ]);

        return $stats;
    }

    /**
     * Barang dihapus di ERP: dihapus juga di toko; yang sudah pernah dibeli (riwayat transaksi) atau masih dirujuk data lain
     * cukup dinonaktifkan supaya riwayat pesanan tetap utuh. Hasil = jumlah barang yang dihapus/dinonaktifkan.
     *
     * @param  list<int>  $erpProductIds
     */
    public function remove(ErpConnection $erp, array $erpProductIds): int
    {
        $count = 0;
        $products = Product::query()->where('company_id', $erp->companyId)->whereIn('erp_product_id', array_map('intval', $erpProductIds))->get();
        foreach ($products as $product) {
            $sold = TransactionDetail::query()->where('product_id', $product->id)->exists();
            if (! $sold) {
                try {
                    DB::transaction(function () use ($product) {
                        $images = $product->productVariants()->pluck('image')->filter()->all();
                        $product->delete();
                        DB::afterCommit(fn () => Storage::disk('public')->delete(array_values(array_filter($images, fn ($p) => str_starts_with((string) $p, 'products/erp/')))));
                    });
                    $count++;

                    continue;
                } catch (QueryException) {
                    // Masih dirujuk data lain (mis. dokumen B2B lama): dinonaktifkan saja.
                }
            }
            if ($product->status !== 'inactive') {
                $product->forceFill(['status' => 'inactive'])->save();
                ProductVariant::query()->where('product_id', $product->id)->update(['stock' => 0]);
                $count++;
            }
        }

        return $count;
    }

    /** Stok tersedia per varian = Σ tersedia gudang sumber (minus = 0); varian toko tanpa saldo di ERP = 0. */
    public function stock(ErpConnection $erp): int
    {
        $available = [];
        $warehouses = $erp->warehouses ?: [null];
        foreach ($warehouses as $code) {
            foreach ($erp->pages('/stock', array_filter(['warehouse_code' => $code])) as $row) {
                $available[(int) $row['variant_id']] = ($available[(int) $row['variant_id']] ?? 0) + (float) $row['available'];
            }
        }
        $variants = ProductVariant::query()->whereNotNull('erp_variant_id')
            ->whereHas('product', fn ($q) => $q->where('company_id', $erp->companyId))->get(['id', 'erp_variant_id', 'stock']);
        $updated = 0;
        foreach ($variants as $variant) {
            $stock = max(0, (int) floor($available[$variant->erp_variant_id] ?? 0));
            if ((int) $variant->stock !== $stock) {
                $variant->update(['stock' => $stock]);
                $updated++;
            }
        }

        return $updated;
    }

    /** Satu barang ERP → barang + varian toko; hasil = jumlah varian. */
    private function product(ErpConnection $erp, array $row): int
    {
        return DB::transaction(function () use ($erp, $row) {
            [$main, $detail] = $this->categories((string) ($row['category'] ?? ''));
            $product = Product::query()->firstOrNew(['company_id' => $erp->companyId, 'erp_product_id' => (int) $row['id']]);
            $product->fill([
                'name' => (string) $row['name'],
                'description' => $row['description'] ?? $product->description,
                'main_category_id' => $main?->id,
                'category_detail_id' => $detail?->id,
                'status' => ! empty($row['active']) ? 'active' : 'inactive',
            ]);
            if (! $product->exists) {
                $product->slug = $this->slug($erp->companyId, (string) $row['code'], (string) $row['name']);
            }
            $product->erp_synced_at = now();
            $product->save();

            $images = collect($row['images'] ?? []);
            $main_image = $images->firstWhere('variant_id', null) ?? $images->first();
            $multi = count($row['variants'] ?? []) > 1;
            $keep = [];
            foreach ($row['variants'] ?? [] as $v) {
                $variant = $this->variantOption($v, $multi);
                $line = ProductVariant::query()->where('product_id', $product->id)
                    ->where(fn ($q) => $q->where('erp_variant_id', (int) $v['id'])->orWhere('variant_id', $variant->id))->first()
                    ?? new ProductVariant(['product_id' => $product->id, 'stock' => 0]);
                $image = $images->firstWhere('variant_id', (int) $v['id']) ?? $main_image;
                $weight = $v['weight'] ?? $row['weight'] ?? null;
                $line->fill([
                    'variant_id' => $variant->id,
                    'erp_variant_id' => (int) $v['id'],
                    'sku' => $v['sku'],
                    'price' => $this->price($erp, $v, (string) ($row['base_unit'] ?? '')),
                    'weight_grams' => $weight !== null ? (int) round((float) $weight * 1000) : $line->weight_grams,
                ]);
                if ($image) {
                    $line->image = $this->image($erp, $image) ?? $line->image;
                }
                $line->save();
                $keep[] = $line->id;
            }
            // Varian yang dihapus/dinonaktifkan di ERP: stok 0 supaya tidak bisa dibeli (riwayat transaksi tetap).
            ProductVariant::query()->where('product_id', $product->id)->whereNotIn('id', $keep ?: [0])->update(['stock' => 0]);
            // Belum ada harga sama sekali di ERP: tidak ditampilkan supaya tidak terjual Rp 0 (aktif lagi begitu berharga).
            if ($keep !== [] && (float) ProductVariant::query()->whereIn('id', $keep)->max('price') <= 0) {
                $product->forceFill(['status' => 'inactive'])->save();
            }

            return count($keep);
        });
    }

    /** Harga jual toko: level harga pilihan untuk satuan dasar, selain itu harga dasar varian; belum diisi di ERP = 0. */
    private function price(ErpConnection $erp, array $v, string $baseUnit): string
    {
        if ($erp->priceLevelId !== null) {
            $level = collect($v['prices'] ?? [])->first(fn ($p) => (int) $p['price_level_id'] === $erp->priceLevelId && ($p['unit'] ?? $baseUnit) === $baseUnit);
            if ($level && is_numeric($level['price'] ?? null)) {
                return (string) $level['price'];
            }
        }

        return is_numeric($v['base_price'] ?? null) ? (string) $v['base_price'] : '0';
    }

    /** Opsi varian toko (tabel variants: nama + nilai) dari opsi varian ERP; barang satu varian = "Varian: Standar". */
    private function variantOption(array $v, bool $multi): Variant
    {
        $options = (array) ($v['options'] ?? []);
        if ($options === []) {
            return Variant::query()->firstOrCreate(['name' => 'Varian', 'value' => $multi ? (string) ($v['name'] ?? $v['sku']) : 'Standar']);
        }

        return Variant::query()->firstOrCreate(['name' => Str::limit(implode(' / ', array_keys($options)), 250, ''), 'value' => Str::limit(implode(' / ', array_values($options)), 250, '')]);
    }

    /** "Baut > Baut hex > M10" → kategori utama "Baut", detail "Baut hex" (tingkat lebih dalam ikut nama detail). */
    private function categories(string $path): array
    {
        $parts = array_values(array_filter(array_map('trim', explode('>', $path))));
        if ($parts === []) {
            return [null, null];
        }
        $main = MainCategory::query()->where('name', $parts[0])->first()
            ?? MainCategory::query()->create(['name' => $parts[0], 'slug' => $this->uniqueSlug('main_categories', $parts[0])]);
        if (count($parts) === 1) {
            return [$main, null];
        }
        $name = implode(' › ', array_slice($parts, 1));
        $detail = CategoryDetail::query()->where('main_category_id', $main->id)->where('name', $name)->first()
            ?? CategoryDetail::query()->create(['main_category_id' => $main->id, 'name' => $name, 'slug' => $this->uniqueSlug('category_details', $name)]);

        return [$main, $detail];
    }

    /** Foto ERP disalin ke disk public; nama file memuat versi (?v=), jadi foto yang tidak berubah tidak diunduh ulang. */
    private function image(ErpConnection $erp, array $image): ?string
    {
        $url = (string) ($image['url'] ?? '');
        if ($url === '') {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $path = "products/erp/{$erp->companyId}/{$image['id']}-".($query['v'] ?? '0').'.webp';
        if (! Storage::disk('public')->exists($path)) {
            try {
                Storage::disk('public')->put($path, $erp->download($url));
            } catch (ErpException) {
                return null;
            }
        }

        return $path;
    }

    private function slug(int $companyId, string $code, string $name): string
    {
        $base = Str::slug($name) ?: Str::slug($code) ?: 'barang';
        $slug = $base;
        $i = 2;
        while (Product::query()->where('company_id', $companyId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function uniqueSlug(string $table, string $name): string
    {
        $base = Str::slug($name) ?: 'kategori';
        $slug = $base;
        $i = 2;
        while (DB::table($table)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
