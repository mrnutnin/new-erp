<?php
namespace App\Modules\Wms\Requests;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;
final class SaveIssueReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { $d='decimal:0,'.WmsDecimal::places(); return ['document_date'=>['required','date_format:Y-m-d','before_or_equal:today'],'issue_document_id'=>['required','integer','exists:wms_issue_documents,id'],'reason'=>['required','string','min:5','max:500'],'lines'=>['required','array','min:1','max:100'],'lines.*.issue_line_id'=>['required','integer','exists:wms_issue_lines,id'],'lines.*.quantity'=>['required','numeric',$d,'gt:0','max:999999999999.99999999']]; }
}
