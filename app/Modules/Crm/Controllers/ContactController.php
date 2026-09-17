<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\PartyContact;
use App\Modules\Crm\Requests\SavePartyContactRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContactController extends Controller
{
    public function store(SavePartyContactRequest $request, Party $customer, AuditLogger $audit): JsonResponse
    {
        $this->customer($customer);
        $contact = DB::transaction(function () use ($request, $customer, $audit): PartyContact {
            $values = $this->values($request);
            if ($values['is_primary']) $customer->contacts()->update(['is_primary' => false]);
            $contact = $customer->contacts()->create([...$values, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $audit->record('crm.customer-contact.created', $contact, [], $contact->toArray(), $request->user(), $request);
            return $contact;
        });

        return response()->json(['status' => true, 'msg' => 'เพิ่มผู้ติดต่อแล้ว', 'contact_id' => $contact->id]);
    }

    public function update(SavePartyContactRequest $request, Party $customer, PartyContact $contact, AuditLogger $audit): JsonResponse
    {
        $this->scope($customer, $contact);
        DB::transaction(function () use ($request, $customer, $contact, $audit): void {
            $values = $this->values($request, $contact);
            if ($values['is_primary']) $customer->contacts()->whereKeyNot($contact->id)->update(['is_primary' => false]);
            $before = $contact->toArray();
            $contact->update([...$values, 'updated_by' => $request->user()->id]);
            $audit->record('crm.customer-contact.updated', $contact, $before, $contact->fresh()->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขผู้ติดต่อแล้ว']);
    }

    public function destroy(Request $request, Party $customer, PartyContact $contact, AuditLogger $audit): JsonResponse
    {
        $this->scope($customer, $contact);
        DB::transaction(function () use ($request, $contact, $audit): void {
            $before = $contact->toArray();
            $contact->delete();
            $audit->record('crm.customer-contact.deleted', $contact, $before, [], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบผู้ติดต่อแล้ว']);
    }

    private function values(SavePartyContactRequest $request, ?PartyContact $contact = null): array
    {
        $allowed = $request->input('contact_permission_status') === 'ALLOWED';
        $permission = [
            'contact_permission_status' => $request->input('contact_permission_status'),
            'lawful_basis' => $allowed ? $request->input('lawful_basis') : null,
            'allow_phone' => $allowed && $request->boolean('allow_phone'),
            'allow_email' => $allowed && $request->boolean('allow_email'),
            'allow_line' => $allowed && $request->boolean('allow_line'),
            'permission_note' => $request->input('permission_note'),
        ];
        $changed = ! $contact || collect($permission)->contains(fn ($value, string $field) => $contact->{$field} != $value);

        return [
            ...$request->safe()->except(['contact_permission_status', 'lawful_basis', 'allow_phone', 'allow_email', 'allow_line', 'permission_note', 'is_primary', 'is_active']),
            ...$permission,
            'permission_recorded_at' => $changed ? now() : $contact?->permission_recorded_at,
            'permission_recorded_by' => $changed ? $request->user()->id : $contact?->permission_recorded_by,
            'is_primary' => $request->boolean('is_primary'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
        ];
    }

    private function scope(Party $customer, PartyContact $contact): void
    {
        $this->customer($customer);
        abort_unless((int) $contact->party_id === (int) $customer->id, 404);
    }

    private function customer(Party $customer): void
    {
        abort_unless($customer->is_active && $customer->customerRole()->where('is_active', true)->exists(), 404);
    }
}
