<?php

namespace App\Modules\Claims\Http;

use App\Models\Employee;
use App\Modules\Claims\Models\ClaimItem;
use App\Modules\Claims\Services\ClaimReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the receipt of a claim item, only to those who may see the claim.
 */
class ClaimReceiptController
{
    public function __invoke(Request $request, ClaimItem $claimItem): Response
    {
        $user = $request->user();
        // Authorization first, so that nobody learns which items have a receipt
        abort_unless($user instanceof Employee && ClaimReceipt::canView($user, $claimItem), 403);

        $path = (string) $claimItem->receipt;
        abort_unless(
            ClaimReceipt::isValidPath($path) && Storage::disk(ClaimReceipt::DISK)->exists($path),
            404
        );

        return Storage::disk(ClaimReceipt::DISK)->response($path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
