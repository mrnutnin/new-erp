<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    private const ACTION_LABELS = [
        'wms.purchase_order.created' => 'สร้างใบสั่งซื้อ',
        'wms.purchase_order.created_from_requisition' => 'สร้างใบสั่งซื้อจากใบขอซื้อ',
        'wms.purchase_order.updated' => 'แก้ไขใบสั่งซื้อ',
        'wms.purchase_order.approved' => 'อนุมัติใบสั่งซื้อ',
        'wms.purchase_order.voided' => 'ยกเลิกใบสั่งซื้อ',
        'wms.purchase_order.deleted' => 'ลบใบสั่งซื้อ',
        'wms.purchase_document.created' => 'สร้างร่างใบตั้งหนี้',
        'wms.purchase_document.updated' => 'แก้ไขร่างใบตั้งหนี้',
        'wms.purchase_document.approved' => 'อนุมัติใบตั้งหนี้',
        'wms.purchase_document.posted' => 'ลงบัญชีใบตั้งหนี้',
        'wms.purchase_document.voided' => 'ยกเลิกใบตั้งหนี้',
        'wms.purchase_document.deleted' => 'ลบร่างใบตั้งหนี้',
        'wms.purchase_document.reversed' => 'กลับรายการใบตั้งหนี้',
        'wms.purchase_document.variance_approved' => 'อนุมัติผลต่าง 3-way match',
        'wms.purchase_document.variance_rejected' => 'ไม่อนุมัติผลต่าง 3-way match',
        'wms.purchase_document.variance_recovered' => 'เปิดให้ตรวจผลต่าง 3-way match ใหม่',
        'wms.inventory.ops_smoke.posted' => 'ทดสอบลง Inventory และ GL สำเร็จ',
        'wms.inventory_adjustment.created' => 'สร้างร่างรายการปรับปรุงสินค้า',
        'wms.inventory_adjustment.updated' => 'แก้ไขร่างรายการปรับปรุงสินค้า',
        'wms.inventory_adjustment.approved' => 'อนุมัติรายการปรับปรุงสินค้า',
        'wms.inventory_adjustment.posted' => 'ลงบัญชีรายการปรับปรุงสินค้า',
        'wms.inventory_adjustment.deleted' => 'ลบร่างรายการปรับปรุงสินค้า',
        'wms.issue.created' => 'สร้างร่างใบเบิกสินค้า',
        'wms.issue.approved' => 'อนุมัติใบเบิกสินค้า',
        'wms.issue.posted' => 'ลง Stock ใบเบิกสินค้า',
        'wms.issue.deleted' => 'ลบร่างใบเบิกสินค้า',
        'wms.issue_return.created' => 'สร้างร่างใบรับคืนจากการเบิก',
        'wms.issue_return.approved' => 'อนุมัติใบรับคืนจากการเบิก',
        'wms.issue_return.posted' => 'ลง Stock ใบรับคืนจากการเบิก',
        'wms.issue_return.deleted' => 'ลบร่างใบรับคืนจากการเบิก',
        'wms.purchase_requisition.created' => 'สร้างใบขอซื้อ',
        'wms.purchase_requisition.updated' => 'แก้ไขใบขอซื้อ',
        'wms.purchase_requisition.submit' => 'ส่งใบขอซื้อเพื่ออนุมัติ',
        'wms.purchase_requisition.approve' => 'อนุมัติใบขอซื้อ',
        'wms.purchase_requisition.reject' => 'ตีกลับใบขอซื้อ',
        'wms.purchase_requisition.void' => 'ยกเลิกใบขอซื้อ',
        'wms.purchase_requisition.deleted' => 'ลบใบขอซื้อ',
        // Older Purchasing transitions use a hyphenated event name.
        'wms.purchase-requisition.submit' => 'ส่งใบขอซื้อเพื่ออนุมัติ',
        'wms.purchase-requisition.approve' => 'อนุมัติใบขอซื้อ',
        'wms.purchase-requisition.reject' => 'ตีกลับใบขอซื้อ',
        'wms.purchase-requisition.void' => 'ยกเลิกใบขอซื้อ',
        'finance.advance_deposit.created' => 'สร้างเงินล่วงหน้า/เงินมัดจำ',
        'finance.advance_deposit.application_created' => 'ตัดเงินล่วงหน้า/เงินมัดจำกับเอกสาร',
        'finance.advance_deposit.reversed' => 'กลับรายการเงินล่วงหน้า/เงินมัดจำ',
        'finance.payment_voucher.created' => 'สร้างร่างใบสำคัญจ่าย',
        'finance.payment_voucher.submitted' => 'ส่งใบสำคัญจ่ายเพื่ออนุมัติ',
        'finance.payment_voucher.approved' => 'อนุมัติใบสำคัญจ่าย',
        'finance.payment_voucher.voided' => 'ยกเลิกใบสำคัญจ่าย',
        'finance.payment_voucher.settlement_created' => 'สร้าง Settlement จากใบสำคัญจ่าย',
        'finance.settlement.created' => 'สร้างร่างเอกสารรับ/จ่ายเงิน',
        'finance.settlement.posted' => 'ลงบัญชีเอกสารรับ/จ่ายเงิน',
        'finance.settlement.reversed' => 'กลับรายการเอกสารรับ/จ่ายเงิน',
        'finance.advance_deposit.created_from_settlement' => 'สร้างเงินล่วงหน้า/เงินมัดจำจาก Settlement',
        'pos.sales_commission.approved' => 'อนุมัติคอมมิชชั่น',
        'pos.sales_commission.rejected' => 'ไม่อนุมัติคอมมิชชั่น',
        'pos.sales_commission.status_changed' => 'แก้ไขสถานะคอมมิชชั่น',
        'pos.commission_payment_batch.created' => 'สร้างชุดจ่ายคอมมิชชั่น',
        'pos.commission_payment_batch.submitted' => 'ส่งชุดจ่ายให้ฝ่ายการเงิน',
        'pos.commission_payment_batch.cancelled' => 'ยกเลิกชุดจ่ายใน POS',
        'finance.commission_payment_batch.verified' => 'ฝ่ายการเงินตรวจสอบชุดจ่ายแล้ว',
        'finance.commission_payment_batch.cancelled' => 'ฝ่ายการเงินยกเลิกชุดจ่าย',
        'finance.commission_payout.created' => 'สร้างเอกสารจ่ายคอมมิชชั่น',
        'finance.commission_payout.posted' => 'Post เอกสารจ่ายคอมมิชชั่น',
        'finance.commission_payment_request.created' => 'สร้างใบขอจ่ายคอมมิชชั่น',
        'finance.commission_payment_request.submitted' => 'ส่งใบขอจ่ายคอมมิชชั่นเพื่ออนุมัติ',
        'finance.commission_payment_request.approved' => 'อนุมัติใบขอจ่ายคอมมิชชั่น',
        'finance.commission_payment_request.cancelled' => 'ยกเลิกใบขอจ่ายคอมมิชชั่น',
        'finance.commission_payment_request.voucher_created' => 'สร้างใบสำคัญจ่ายจากใบขอจ่ายคอมมิชชั่น',
    ];

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function getCreatedAtAttribute($value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC')->setTimezone('Asia/Bangkok');
    }

    public function getActionAttribute(?string $value): string
    {
        return self::ACTION_LABELS[$value] ?? $value ?? '-';
    }

    /**
     * Return the human-entered explanation captured by a status/action event.
     * Reasons are stored in the audit snapshot so the history remains useful
     * even when the document is later voided or reversed.
     */
    public function getReasonAttribute(): ?string
    {
        $keys = [
            'reason', 'void_reason', 'rejection_reason', 'reversal_reason',
            'approval_reason', 'posting_reason', 'validation_reason',
            'lock_reason', 'close_reason', 'reopen_reason', 'change_reason',
        ];

        foreach ([$this->new_values, $this->old_values] as $values) {
            foreach ($keys as $key) {
                $value = $values[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
