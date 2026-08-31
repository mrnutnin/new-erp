<?php
namespace App\Modules\Wms\Requests;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class SaveIssueDocumentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { $d='decimal:0,'.WmsDecimal::places(); return ['document_date'=>['required','date_format:Y-m-d','before_or_equal:today'],'issue_type'=>['required','string','max:40'],'reason'=>['required','string','min:5','max:500'],'lines'=>['required','array','min:1','max:100'],'lines.*.item_id'=>['required','integer','exists:wms_items,id'],'lines.*.uom_id'=>['required','integer','exists:wms_uoms,id'],'lines.*.quantity'=>['required','numeric',$d,'gt:0','max:999999999999.99999999']]; }
}
