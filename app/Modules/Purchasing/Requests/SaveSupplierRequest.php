<?php

namespace App\Modules\Purchasing\Requests;

/**
 * Purchasing-owned request seam.
 *
 * Validation remains inherited until the Supplier transaction is extracted;
 * this prevents two competing tax/identity contracts during the migration.
 */
class SaveSupplierRequest extends \App\Modules\Wms\Requests\SaveSupplierRequest {}
