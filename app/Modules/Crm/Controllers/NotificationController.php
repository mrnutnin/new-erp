<?php
namespace App\Modules\Crm\Controllers;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
final class NotificationController extends Controller
{
 public function index(Request $request):View{$preference=DB::table('crm_notification_preferences')->where('user_id',$request->user()->id)->first();return view('Crm::notifications.index',['vapidPublicKey'=>(string)config('webpush.vapid.public_key'),'reminderEnabled'=>$preference?(bool)$preference->reminder_enabled:true,'reminderMinutes'=>(int)($preference->reminder_minutes??config('erp.crm.reminder_minutes',30))]);}
 public function updatePreference(Request $request):JsonResponse{$data=$request->validate(['reminder_enabled'=>['required','boolean'],'reminder_minutes'=>['required','integer',Rule::in([5,15,30,60,120,1440])]]);DB::table('crm_notification_preferences')->upsert([['user_id'=>$request->user()->id,'reminder_enabled'=>$data['reminder_enabled'],'reminder_minutes'=>$data['reminder_minutes'],'created_at'=>now(),'updated_at'=>now()]],['user_id'],['reminder_enabled','reminder_minutes','updated_at']);return response()->json(['status'=>true,'msg'=>'บันทึกการตั้งค่าแจ้งเตือนแล้ว']);}
 public function subscribe(Request $request):JsonResponse{$data=$request->validate(['endpoint'=>['required','url','max:2048'],'keys.p256dh'=>['required','string','max:255'],'keys.auth'=>['required','string','max:255'],'contentEncoding'=>['nullable','string','max:30']]);$request->user()->updatePushSubscription($data['endpoint'],$data['keys']['p256dh'],$data['keys']['auth'],$data['contentEncoding']??null);return response()->json(['status'=>true,'msg'=>'เปิด Browser Push แล้ว']);}
 public function unsubscribe(Request $request):JsonResponse{$endpoint=$request->validate(['endpoint'=>['required','url','max:2048']])['endpoint'];$request->user()->deletePushSubscription($endpoint);return response()->json(['status'=>true,'msg'=>'ปิด Browser Push แล้ว']);}
 public function data(Request $request):JsonResponse{$filters=$request->validate(['status'=>['nullable','in:ALL,UNREAD']]);$items=$request->user()->notifications()->when(($filters['status']??'ALL')==='UNREAD',fn($q)=>$q->whereNull('read_at'))->latest()->paginate(12)->withPath(route('crm.notifications.index'))->withQueryString();return response()->json(['html'=>view('Crm::notifications._cards',compact('items'))->render(),'pagination'=>$items->hasPages()?$items->onEachSide(1)->links('pagination::bootstrap-5')->render():'','from'=>$items->firstItem(),'to'=>$items->lastItem(),'total'=>$items->total(),'unread'=>$request->user()->unreadNotifications()->count()]);}
 public function read(Request $request,DatabaseNotification $notification):JsonResponse{$this->owned($request,$notification);$notification->markAsRead();return response()->json(['status'=>true]);}
 public function open(Request $request,DatabaseNotification $notification):RedirectResponse{$this->owned($request,$notification);$branchId=(int)($notification->data['branch_id']??0);if($branchId){$branch=Branch::query()->whereKey($branchId)->where('is_active',true)->when($request->user()->branches()->exists(),fn($q)=>$q->whereIn('id',$request->user()->branches()->select('branches.id')),fn($q)=>$q->whereIn('id',$request->user()->warehouses()->where('warehouses.is_active',true)->select('warehouses.branch_id')))->firstOrFail();$request->session()->put('selected_branch_id',$branch->id);$request->session()->forget('selected_warehouse_id');}$notification->markAsRead();$url=(string)($notification->data['url']??'');return redirect()->to(Str::startsWith($url,url('/'))?$url:route('crm.my-work.index'));}
 private function owned(Request $request,DatabaseNotification $notification):void{abort_unless((int)$notification->notifiable_id===(int)$request->user()->id&&$notification->notifiable_type===$request->user()->getMorphClass(),404);}
}
