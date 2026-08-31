<form method="get" action="{{ url()->current() }}" class="d-flex align-items-center gap-2">
    <label class="small text-secondary text-nowrap" for="wms-warehouse">คลังสินค้า</label>
    <select id="wms-warehouse" name="warehouse_id" class="form-select form-select-sm" onchange="this.form.submit()">
        @foreach ($warehouses as $item)
            <option value="{{ $item->id }}" @selected((int) $item->id === (int) $warehouse?->id)>{{ $item->code }} · {{ $item->name }}</option>
        @endforeach
    </select>
</form>
