<?php

namespace App\Modules\Claims\Observers;

use App\Modules\Claims\Models\ClaimItem;
use App\Modules\Claims\Services\ClaimReceipt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the receipt files in order: only accept a path the upload field produces, and remove the
 * file that was replaced or whose item was deleted.
 */
class ClaimItemObserver
{
    public function saving(ClaimItem $item): void
    {
        // The path comes from the browser with the form
        if ($item->isDirty('receipt') && filled($item->receipt) && ! ClaimReceipt::isValidPath($item->receipt)) {
            throw ValidationException::withMessages(['receipt' => 'The receipt is not valid.']);
        }
    }

    public function saved(ClaimItem $item): void
    {
        if ($item->wasChanged('receipt')) {
            $this->deleteFile($item->getOriginal('receipt'));
        }
    }

    public function deleted(ClaimItem $item): void
    {
        $this->deleteFile($item->receipt);
    }

    private function deleteFile(?string $path): void
    {
        if (filled($path) && ClaimReceipt::isValidPath($path)) {
            Storage::disk(ClaimReceipt::DISK)->delete($path);
        }
    }
}
