<?php

namespace App\Modules\Purchasing\Requests;

/**
 * Purchasing-owned request seam. Validation remains shared until the
 * controller action contract is widened without breaking the WMS surface.
 */
class ChangePurchaseDocumentStatusRequest extends \App\Modules\Wms\Requests\ChangePurchaseDocumentStatusRequest {}
