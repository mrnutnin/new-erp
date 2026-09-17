@php
    $labels = collect();
    foreach ($items as $item) {
        for ($copy = 0; $copy < $copies; $copy++) {
            $labels->push($item);
        }
    }
@endphp

@if($sheet)
    @foreach($labels->chunk($columns * $rows) as $pageIndex => $page)
        <table class="pdf-label-sheet">
            @foreach($page->chunk($columns) as $row)
                <tr>
                    @for($column = 0; $column < $columns; $column++)
                        <td style="width:{{ 100 / $columns }}%;height:{{ $cellHeight }}mm">
                            @if($item = $row->values()->get($column))
                                <div class="pdf-label-cell">
                                    @include('Wms::pdf.item-label', compact('item', 'companyName', 'symbol', 'large', 'labelSize'))
                                </div>
                            @endif
                        </td>
                    @endfor
                </tr>
            @endforeach
        </table>
        @if(! $loop->last)<pagebreak />@endif
    @endforeach
@else
    @foreach($labels as $item)
        @include('Wms::pdf.item-label', compact('item', 'companyName', 'symbol', 'large', 'labelSize'))
        @if(! $loop->last)<pagebreak />@endif
    @endforeach
@endif
